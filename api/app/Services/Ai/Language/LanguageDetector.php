<?php

namespace App\Services\Ai\Language;

use App\Models\Account;
use App\Models\Message;
use App\Services\Ai\AiSettings;
use App\Services\Ai\OllamaClient;
use App\Services\Mail\OwnText;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Which language the user writes in, judged by the local chat model from a sample
 * of their sent mail. Detected once, as soon as enough sent mail has been synced,
 * and only when the model is configured and sure; otherwise nothing is set and
 * the reply language stays "same as the incoming mail" until the user picks one.
 */
class LanguageDetector
{
    /** Sent mails needed before the model is asked. */
    public const MIN_SENT = 30;

    /** Mails (newest first) and characters per mail shown to the model. */
    public const SAMPLE = 40;

    public const SAMPLE_CHARS = 350;

    /** Below this the model's answer is discarded. */
    public const MIN_CONFIDENCE = 0.75;

    /** An undecided attempt is not repeated more often than this. */
    public const RETRY_HOURS = 24;

    /**
     * Ask the model which language the given texts are written in.
     *
     * @param  string[]  $texts
     * @return array{code: ?string, confidence: float, reason: ?string} code null = could not decide
     */
    public function detect(array $texts, ?AiSettings $settings = null): array
    {
        $settings ??= AiSettings::load();
        $texts = array_values(array_filter(array_map('trim', $texts), fn ($t) => mb_strlen($t) >= 20));
        if (! $settings->isReady() || $texts === []) {
            return ['code' => null, 'confidence' => 0.0, 'reason' => $texts === [] ? 'no text' : 'local AI not configured'];
        }

        $blocks = [];
        foreach (array_slice($texts, 0, self::SAMPLE) as $i => $t) {
            $blocks[] = '--- Mail '.($i + 1)." ---\n".mb_substr($t, 0, self::SAMPLE_CHARS);
        }

        try {
            $client = new OllamaClient($settings->baseUrl, max(8192, $settings->rag()->contextTokens), $settings->thinking);
            $raw = $client->chatJson((string) $settings->model, [
                ['role' => 'system', 'content' => self::prompt()],
                ['role' => 'user', 'content' => implode("\n\n", $blocks)],
            ], self::schema(), timeout: 120);
        } catch (Throwable $e) {
            report($e);

            return ['code' => null, 'confidence' => 0.0, 'reason' => $e->getMessage()];
        }

        $code = is_string($raw['language'] ?? null) ? Languages::normalise($raw['language']) : null;
        $confidence = is_numeric($raw['confidence'] ?? null) ? max(0.0, min(1.0, (float) $raw['confidence'])) : 0.0;
        $reason = is_string($raw['reason'] ?? null) ? mb_substr($raw['reason'], 0, 300) : null;

        if ($code === null || $confidence < self::MIN_CONFIDENCE) {
            return ['code' => null, 'confidence' => $confidence, 'reason' => $reason ?? 'model was not sure'];
        }

        return ['code' => $code, 'confidence' => $confidence, 'reason' => $reason];
    }

    private static function prompt(): string
    {
        $codes = implode(', ', Languages::codes());

        return <<<PROMPT
You are given excerpts of e-mails one person wrote. Decide which language this person writes in most of the time.
Answer with JSON: {"language": <ISO 639-1 code from: {$codes}, or "unknown">, "confidence": <0..1>, "reason": <one short sentence>}.
Be careful with closely related languages and decide by vocabulary and spelling, not by script alone:
- Croatian (hr): ijekavian ("tjedan", "sveučilište", "tisuću", "kruh", "siječanj", "što", "tko"), Latin script.
- Serbian (sr): mostly ekavian ("nedelja", "univerzitet", "hiljadu", "hleb", "januar", "šta", "ko"), Cyrillic or Latin.
- Bosnian (bs): ijekavian with Bosnian vocabulary ("kahva", "sedmica", "hefta", "lahko").
- Likewise Czech vs Slovak, Norwegian vs Danish, Portuguese variants, Indonesian vs Malay.
If the mails mix languages, pick the dominant one. If you are not sure, answer "unknown" with a low confidence — never guess.
PROMPT;
    }

    private static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'language' => ['type' => 'string', 'enum' => array_merge(Languages::codes(), ['unknown'])],
                'confidence' => ['type' => 'number'],
                'reason' => ['type' => 'string'],
            ],
            'required' => ['language', 'confidence', 'reason'],
        ];
    }

    /**
     * Detection over the account's sent mail. Not "ready" until enough of it is synced.
     *
     * @return array{ready: bool, code: ?string, confidence: float, reason: ?string, samples: int, name: ?string}
     */
    public function detectForAccount(Account $account, ?AiSettings $settings = null): array
    {
        $texts = $this->sentTexts($account);
        if (count($texts) < self::MIN_SENT) {
            return ['ready' => false, 'code' => null, 'confidence' => 0.0, 'reason' => 'not enough sent mail yet', 'samples' => count($texts), 'name' => null];
        }

        $r = $this->detect($texts, $settings);

        return ['ready' => true, 'samples' => count($texts), 'name' => $r['code'] ? Languages::name($r['code']) : null] + $r;
    }

    /**
     * Set the reply language automatically — once, only when the model is sure, and
     * never over the user's own choice. Returns the code that was set, or null.
     */
    public function autoSet(Account $account): ?string
    {
        $settings = AiSettings::load();
        if ($settings->replyLanguage !== null || $settings->replyLanguageSource !== null || ! $settings->isReady()) {
            return null; // chosen by the user, detected before, or no model to ask
        }
        if ($settings->replyLanguageCheckedAt !== null && strtotime($settings->replyLanguageCheckedAt) > now()->subHours(self::RETRY_HOURS)->getTimestamp()) {
            return null; // asked recently and the model was not sure: do not hammer it
        }

        $result = $this->detectForAccount($account, $settings);
        if (! $result['ready']) {
            return null;
        }

        // The model call can take minutes: the user may have picked a language meanwhile.
        $fresh = AiSettings::load();
        if ($fresh->replyLanguage !== null || $fresh->replyLanguageSource !== null) {
            return null;
        }

        $changes = ['reply_language_checked_at' => now()->toIso8601String()];
        if ($result['code'] !== null) {
            $changes += [
                'reply_language' => $result['code'],
                'reply_language_source' => 'auto',
                'reply_language_detected_at' => now()->toIso8601String(),
            ];
        }
        $fresh->with($changes)->save();

        logger()->info('Reply language detection', ['account' => $account->email, 'language' => $result['code'], 'confidence' => $result['confidence'], 'samples' => $result['samples']]);

        return $result['code'];
    }

    /** @return string[] the user's own text of their newest sent mails */
    private function sentTexts(Account $account): array
    {
        return Message::query()
            ->where('account_id', $account->id)
            ->visible()
            ->where(fn (Builder $q) => $q->whereJsonContains('label_ids', 'SENT')->orWhere('from_email', strtolower((string) $account->email)))
            ->whereJsonDoesntContain('label_ids', 'DRAFT')
            ->select(['body_text', 'snippet'])
            ->orderByDesc('received_at')
            ->limit(self::SAMPLE * 3)
            ->get()
            ->map(fn (Message $m) => OwnText::of($m->body_text ?? $m->snippet ?? ''))
            ->filter(fn ($t) => mb_strlen($t) >= 20)
            ->values()
            ->all();
    }
}
