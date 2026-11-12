<?php

namespace App\Services\Ai\Style;

use App\Jobs\BuildStyleProfileJob;
use App\Models\Account;
use App\Models\Message;
use App\Models\StyleProfile;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\Language\LanguageDetector;
use App\Services\Ai\OllamaClient;
use App\Services\Ai\ThinkTagFilter;
use App\Services\Mail\OwnText;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Builds and maintains the per-mailbox writing-style profile:
 *
 *  1. statistics from ALL of the user's sent mail (StyleAnalyzer, no model needed),
 *  2. a short style guide written by the local chat model from those statistics plus
 *     a dozen representative mails (only when local AI is configured),
 *  3. the prompt block that "Compose with AI" prepends to every draft.
 *
 * The profile goes stale as the user keeps sending mail (from the app or elsewhere);
 * jobs, the scheduler and the send path call refreshIfStale().
 */
class StyleProfileService
{
    /** Sent mails analysed at most (newest first). */
    public const MAX_SENT = 1500;

    /** Mails from other people used as the "normal vocabulary" baseline. */
    public const MAX_OTHERS = 600;

    /** Rebuild when at least this many new sent mails arrived … */
    public const STALE_NEW_MAILS = 5;

    /** … or when the profile is older than this and anything new arrived. */
    public const STALE_DAYS = 7;

    /** Sample mails handed to the model (30 x 1400 chars ~ 12k tokens, inside a 24k context). */
    public const SAMPLE_CHARS = 1400;

    public const SAMPLE_COUNT = 30;

    public function __construct(private readonly StyleAnalyzer $analyzer, private readonly DraftFeedbackService $feedback) {}

    // ---------------------------------------------------------------- building

    /**
     * Recompute the statistics (and, when local AI is ready, the model-written guide).
     */
    public function build(Account $account, bool $withSummary = true): StyleProfile
    {
        $profile = StyleProfile::query()->firstOrCreate(['account_id' => $account->id]);

        $mails = $this->sentMails($account);
        $names = self::nameParts($account);

        // The mailbox is being read anyway: settle the reply language if it never was.
        app(LanguageDetector::class)->autoSet($account);

        if ($mails === []) {
            $profile->forceFill([
                'stats' => ['mails' => 0, 'feedback' => $this->feedback->aggregate($account)], 'sample_count' => 0, 'analysed_until' => null,
                'built_at' => now(), 'queued_at' => null, 'last_error' => null,
            ])->save();

            return $profile;
        }

        $stats = $this->analyzer->analyse($mails, $this->otherTexts($account), $names);
        // What the user changed in earlier AI drafts before sending them.
        $stats['feedback'] = $this->feedback->aggregate($account);

        $profile->forceFill([
            'stats' => $stats,
            'sample_count' => $stats['mails'],
            'analysed_until' => max(array_filter(array_column($mails, 'sent_at'))) ?: now(),
            'built_at' => now(),
            'queued_at' => null,
            'last_error' => null,
        ])->save();

        if ($withSummary && ! $profile->summary_edited) {
            $this->buildSummary($account, $profile, $mails);
        }

        return $profile->refresh();
    }

    /**
     * Ask the local model for a compact style guide. Failures are recorded on the
     * profile (last_error) but never break the statistics part.
     */
    public function buildSummary(Account $account, StyleProfile $profile, ?array $mails = null): void
    {
        $settings = AiSettings::load();
        if (! $settings->isReady() || ! is_array($profile->stats) || ($profile->stats['mails'] ?? 0) === 0) {
            return;
        }

        $mails ??= $this->sentMails($account);
        $byId = [];
        foreach ($mails as $m) {
            $byId[$m['id']] = $m;
        }
        $samples = [];
        foreach ($profile->stats['sample_ids'] ?? [] as $id) {
            if (! isset($byId[$id]) || count($samples) >= self::SAMPLE_COUNT) {
                continue;
            }
            $m = $byId[$id];
            $to = implode(', ', array_map(fn ($e) => preg_replace('/@.*$/', '@…', $e) ?? $e, $m['to']));
            $samples[] = '--- Sent mail '.(count($samples) + 1)." (to: {$to}; subject: {$m['subject']}) ---\n".mb_substr($m['text'], 0, self::SAMPLE_CHARS);
        }

        $user = "Statistics from {$profile->stats['mails']} sent e-mails:\n".self::describe($profile->stats, 5000)
            ."\n\nRepresentative sent e-mails (written by the user):\n\n".implode("\n\n", $samples);

        try {
            // Thinking only when the user switched it on: reasoning models can think past the timeout here.
            $client = new OllamaClient($settings->baseUrl, max(24576, $settings->rag()->contextTokens), $settings->thinking);
            $text = $client->chat((string) $settings->model, [
                ['role' => 'system', 'content' => self::summaryPrompt($profile->stats)],
                ['role' => 'user', 'content' => $user],
            ], timeout: 300, temperature: 0.2);
            $text = self::cleanSummary(ThinkTagFilter::strip($text));
            if ($text === '') {
                throw new \RuntimeException('The model returned an empty style guide.');
            }
            $profile->forceFill([
                'summary' => mb_substr($text, 0, 7000),
                'summary_model' => $settings->model,
                'summary_built_at' => now(),
                'last_error' => null,
            ])->save();
        } catch (AiUnavailableException $e) {
            $profile->forceFill(['last_error' => $e->getMessage()])->save();
        } catch (Throwable $e) {
            report($e);
            $profile->forceFill(['last_error' => 'Style guide: '.$e->getMessage()])->save();
        }
    }

    private static function summaryPrompt(array $stats): string
    {
        $lang = $stats['language'] ?? [];
        arsort($lang);
        $main = (string) (array_key_first($lang) ?: 'other');
        $langName = match ($main) {
            'hr' => 'Croatian',
            'de' => 'German',
            'en' => 'English',
            default => 'their language',
        };

        // The guide is written in English (small local models stay coherent in English and
        // the drafting prompt is English anyway); the user's own wording is quoted verbatim.
        return <<<PROMPT
You study how one person writes e-mails and produce a compact STYLE GUIDE that another model will follow to write e-mails in that person's voice.
You get statistics computed from all of their sent mail and a handful of representative mails they wrote (mostly in {$langName}).

Write 20 to 35 bullet points in English, each starting with "- ", grouped in this order: TONE, OPENING, CLOSING, SENTENCES, VOCABULARY, ASKING & ANSWERING, FORMATTING, NEVER. Start each bullet with the group word in capitals and a colon.
Every bullet must be CONCRETE and carry at least one verbatim quote from the mails or statistics, in the original language, in double quotes — a rule without an example is useless. Good: 'SENTENCES: starts most mails straight with the matter, e.g. "Attached is the schedule for..." or "Could we move the meeting to...".' Bad: 'Sentences are short and direct.'
Cover: how formal contacts, clients, partners and colleagues are addressed differently (quote each); the exact greetings and sign-offs by register; how the first sentence typically starts; how requests are phrased and how the person answers quickly (quote the short replies); recurring phrases and constructions; spelling and orthography habits (diacritics, lower case after the greeting, capitalised polite forms, commas, time formats); which recipients get which language; and what the person clearly never does.
Rules: never repeat a point, never pad. Only describe what the mails and statistics show; never invent facts about the person. Statistics are exact: quote them as given or leave them out; "no greeting in 78%" means most mails start without one. Plain text: no headings, no markdown besides "- ", no introduction, no closing remark. Under 5000 characters.
PROMPT;
    }

    /** Small models sometimes loop: keep unique, non-empty bullets only, in order, capped. */
    public static function cleanSummary(string $text): string
    {
        $out = [];
        $seen = [];
        foreach (preg_split('/\r?\n/', trim($text)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $line = preg_replace('/^[-*•]\s*/u', '', $line) ?? $line;
            $line = preg_replace('/\*\*(.+?)\*\*/u', '$1', $line) ?? $line; // stray markdown bold
            $key = mb_strtolower(preg_replace('/[^\p{L}\p{N} ]+/u', '', $line) ?? $line);
            $key = preg_replace('/\s+/u', ' ', trim($key)) ?? $key;
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            // near-duplicate: shares most of its words with an earlier bullet
            $words = array_unique(explode(' ', $key));
            foreach (array_keys($seen) as $prev) {
                $common = count(array_intersect($words, explode(' ', $prev)));
                if (count($words) >= 4 && $common / count($words) >= 0.7) {
                    continue 2;
                }
            }
            $seen[$key] = true;
            $out[] = '- '.$line;
            if (count($out) >= 40) {
                break;
            }
        }

        return implode("\n", $out);
    }

    // ---------------------------------------------------------------- staleness

    /** A first build is needed, or enough new sent mail arrived since the last one. */
    public function isStale(Account $account, ?StyleProfile $profile = null): bool
    {
        $profile ??= StyleProfile::query()->where('account_id', $account->id)->first();

        if ($profile === null || $profile->built_at === null) {
            return $this->sentQuery($account)->exists();
        }

        $new = $this->sentQuery($account)
            ->when($profile->analysed_until !== null, fn (Builder $q) => $q->where('sent_at', '>', $profile->analysed_until))
            ->count();
        if ($new >= self::STALE_NEW_MAILS) {
            return true;
        }
        if ($new > 0 && $profile->built_at->lt(now()->subDays(self::STALE_DAYS))) {
            return true;
        }

        // Every few sent AI drafts carry new corrections worth learning from.
        if ($this->feedback->countSince($account, $profile->built_at) >= 3) {
            return true;
        }

        // Stats exist but the model-written guide is missing while AI is now configured.
        if ($profile->isBuilt() && $profile->summary === null && $profile->last_error === null && AiSettings::load()->isReady()) {
            return true;
        }

        return false;
    }

    /** Queue a rebuild when needed (cheap; safe to call after every send / sync / index run). */
    public function refreshIfStale(Account $account): bool
    {
        $profile = StyleProfile::query()->where('account_id', $account->id)->first();
        if ($profile?->queued_at !== null && $profile->queued_at->gt(now()->subMinutes(30))) {
            return false; // already queued
        }
        if (! $this->isStale($account, $profile)) {
            return false;
        }
        $this->queue($account);

        return true;
    }

    public function queue(Account $account): void
    {
        StyleProfile::query()->updateOrCreate(['account_id' => $account->id], ['queued_at' => now()]);
        BuildStyleProfileJob::dispatch($account->id);
    }

    // ---------------------------------------------------------------- prompt

    /**
     * The block prepended to every AI draft, or null when there is nothing usable.
     */
    public function promptBlock(Account $account, int $maxChars): ?string
    {
        $profile = StyleProfile::query()->where('account_id', $account->id)->first();
        if ($profile === null || ! $profile->isUsable()) {
            return null;
        }

        $mails = $profile->sample_count.' e-mail'.($profile->sample_count === 1 ? '' : 's');
        $parts = ["How the user writes — profile learned from {$mails} the user sent (follow it closely; examples to the same person below take precedence where they differ):"];
        if ($profile->summary !== null && trim($profile->summary) !== '') {
            $parts[] = trim($profile->summary);
        }
        $facts = self::describe($profile->stats, $maxChars);
        if ($facts !== '') {
            $parts[] = "Measured habits:\n".$facts;
        }

        $text = implode("\n\n", $parts);

        return mb_strlen($text) > $maxChars ? rtrim(mb_substr($text, 0, $maxChars)).' […]' : $text;
    }

    /**
     * The statistics as a few plain lines (for the prompt and for the model that writes
     * the guide). Only what is informative is mentioned.
     */
    public static function describe(array $s, int $maxChars = 2000): string
    {
        if (($s['mails'] ?? 0) === 0) {
            return '';
        }
        $pct = fn ($v) => (int) round(((float) $v) * 100).'%';
        $lines = [];

        $notable = fn (array $items) => array_values(array_filter($items, fn ($x) => ($x['share'] ?? 0) >= 0.03 && ($x['mails'] ?? 0) >= 2));
        $g = array_map(fn ($x) => "\"{$x['text']}\" ({$pct($x['share'])})", array_slice($notable($s['greetings'] ?? []), 0, 4));
        if ($g !== []) {
            $lines[] = '- Greetings: '.implode(', ', $g).(($s['no_greeting_share'] ?? 0) >= 0.2 ? '; no greeting in '.$pct($s['no_greeting_share']).' of mails' : '');
        } elseif (($s['no_greeting_share'] ?? 0) >= 0.5) {
            $lines[] = '- Usually starts without a greeting line.';
        }

        $c = array_map(fn ($x) => "\"{$x['text']}\" ({$pct($x['share'])})", array_slice($notable($s['closings'] ?? []), 0, 4));
        if ($c !== []) {
            $lines[] = '- Sign-offs: '.implode(', ', $c).(($s['no_closing_share'] ?? 0) >= 0.2 ? '; no sign-off in '.$pct($s['no_closing_share']).' of mails' : '');
        }

        if (! empty($s['sign_name']['text']) && ($s['sign_name']['share'] ?? 0) >= 0.05) {
            $lines[] = '- Signs with: "'.$s['sign_name']['text'].'" ('.$pct($s['sign_name']['share']).' of mails) right under the sign-off.';
        }

        $sig = $s['signature']['lines'] ?? [];
        if ($sig !== []) {
            $lines[] = '- Signature block the user appends ('.$pct($s['signature']['share'] ?? 0).' of mails; reproduce it exactly, on its own lines at the very end):';
            foreach (array_slice($sig, 0, 8) as $l) {
                $lines[] = '    '.$l;
            }
        }

        $lang = $s['language'] ?? [];
        arsort($lang);
        $langNames = ['hr' => 'Croatian', 'en' => 'English', 'de' => 'German', 'other' => 'other'];
        $langParts = [];
        foreach ($lang as $k => $v) {
            if ($v >= 0.05) {
                $langParts[] = ($langNames[$k] ?? $k).' '.$pct($v);
            }
        }
        $f = $s['formality'] ?? [];
        $formality = match (true) {
            ($f['formal'] ?? 0) >= 0.6 => 'mostly formal (polite "Vi" form) — formal in '.$pct($f['formal']).' of mails',
            ($f['informal'] ?? 0) >= 0.6 => 'mostly informal (first names, "ti" form) — informal in '.$pct($f['informal']).' of mails',
            default => 'mixed — formal '.$pct($f['formal'] ?? 0).', informal '.$pct($f['informal'] ?? 0).', neutral '.$pct($f['neutral'] ?? 0),
        };
        $lines[] = '- Language: '.($langParts !== [] ? implode(', ', $langParts) : 'n/a').'. Register: '.$formality.'.';

        $len = $s['length'] ?? [];
        if ($len !== []) {
            $size = match (true) {
                ($len['median_words'] ?? 0) <= 40 => 'short',
                ($len['median_words'] ?? 0) <= 120 => 'medium-length',
                default => 'long',
            };
            $lines[] = "- Length: typically {$size} — about {$len['median_words']} words, {$len['avg_sentences']} sentences of ~{$len['avg_words_per_sentence']} words, ".($len['avg_paragraphs'] >= 1.5 ? "{$len['avg_paragraphs']} paragraphs" : 'usually a single paragraph')
                .(($len['short_share'] ?? 0) >= 0.5 ? '; more than half of the mails are under 40 words' : '').'.';
        }

        foreach (DraftFeedbackService::describe($s['feedback'] ?? null) as $line) {
            $lines[] = $line;
        }

        $q = fn (array $items) => implode(', ', array_map(fn ($x) => '"'.(is_array($x) ? ($x['text'] ?? '') : $x).'"'.(is_array($x) && ($x['count'] ?? 0) > 1 ? " ({$x['count']}×)" : ''), $items));

        $reg = $s['by_register'] ?? null;
        if ($reg && ($reg['formal']['mails'] ?? 0) >= 3 && ($reg['informal']['mails'] ?? 0) >= 3) {
            $fg = $q(array_slice($reg['formal']['greetings'], 0, 2));
            $fc = $q(array_slice($reg['formal']['closings'], 0, 2));
            $ig = $q(array_slice($reg['informal']['greetings'], 0, 2));
            $ic = $q(array_slice($reg['informal']['closings'], 0, 2));
            $lines[] = '- By register: formal contacts get '.($fg !== '' ? "greeting {$fg}" : 'no greeting').($fc !== '' ? " and sign-off {$fc}" : '').'; colleagues/friends get '.($ig !== '' ? "greeting {$ig}" : 'no greeting').($ic !== '' ? " and sign-off {$ic}" : '').'.';
        }
        if (($s['inline_greeting_share'] ?? 0) >= 0.1) {
            $lines[] = '- Often writes the greeting and the first sentence on ONE line, e.g. "Hello, sure, …" ('.$pct($s['inline_greeting_share']).' of mails).';
        }
        if (! empty($s['openers'])) {
            $lines[] = '- First sentence usually starts with: '.$q(array_slice($s['openers'], 0, 8)).'.';
        }
        if (! empty($s['sentence_starters'])) {
            $lines[] = '- Sentences often start with: '.$q(array_slice($s['sentence_starters'], 0, 10)).'.';
        }
        if (! empty($s['short_replies'])) {
            $lines[] = '- Short replies the user sends as a whole mail: '.$q(array_slice($s['short_replies'], 0, 8)).'.';
        }
        if (! empty($s['requests'])) {
            $lines[] = '- How the user asks for things (verbatim): '.$q(array_slice($s['requests'], 0, 4)).'.';
        }
        if (! empty($s['questions'])) {
            $lines[] = '- Questions the user asks (verbatim): '.$q(array_slice($s['questions'], 0, 3)).'.';
        }
        if (! empty($s['example_sentences'])) {
            $lines[] = '- Characteristic sentences (verbatim, write like these): '.$q(array_slice($s['example_sentences'], 0, 6)).'.';
        }
        $o = $s['orthography'] ?? null;
        if ($o) {
            $parts = [];
            if (($s['language']['hr'] ?? 0) >= 0.3) {
                $parts[] = ($o['ascii_croatian_share'] ?? 0) >= 0.5
                    ? 'writes Croatian WITHOUT diacritics ("sto", "moze") in '.$pct($o['ascii_croatian_share']).' of Croatian mails'
                    : 'uses Croatian diacritics (č ć š ž đ) in '.$pct(1 - ($o['ascii_croatian_share'] ?? 0)).' of Croatian mails';
            }
            if (($o['lowercase_after_greeting_share'] ?? 0) >= 0.4) {
                $parts[] = 'continues in lower case after the greeting line ('.$pct($o['lowercase_after_greeting_share']).')';
            } elseif (($s['no_greeting_share'] ?? 1) < 0.8) {
                $parts[] = 'starts with a capital letter after the greeting';
            }
            if (($o['comma_after_greeting_share'] ?? 0) >= 0.5) {
                $parts[] = 'comma after the greeting';
            }
            if (($o['capital_vi_share'] ?? 0) >= 0.1) {
                $parts[] = 'capitalises the polite "Vi/Vam/Vaš" ('.$pct($o['capital_vi_share']).' of mails)';
            }
            if ($parts !== []) {
                $lines[] = '- Orthography: '.implode('; ', $parts).'.';
            }
        }
        if (! empty($s['time_formats'])) {
            $tf = $s['time_formats'];
            arsort($tf);
            $lines[] = '- Writes times like: '.implode(', ', array_map(fn ($k, $c) => "\"{$k}\" ({$c}×)", array_keys($tf), $tf)).'.';
        }
        $main = 'hr';
        if (! empty($s['language'])) {
            $l = $s['language'];
            arsort($l);
            $main = (string) array_key_first($l);
        }
        $foreign = array_values(array_filter($s['language_by_domain'] ?? [], fn ($d) => $d['language'] !== $main && $d['language'] !== 'other'));
        if ($foreign !== []) {
            $names = ['hr' => 'Croatian', 'en' => 'English', 'de' => 'German'];
            $lines[] = '- Switches language by recipient: '.implode(', ', array_map(fn ($d) => "{$d['domain']} → ".($names[$d['language']] ?? $d['language']), array_slice($foreign, 0, 6))).'.';
        }

        $ph = array_map(fn ($x) => "\"{$x['text']}\"", array_slice($s['phrases'] ?? [], 0, 12));
        if ($ph !== []) {
            $lines[] = '- Recurring phrases: '.implode(', ', $ph).'.';
        }
        $dw = array_map(fn ($x) => $x['word'], array_slice($s['distinctive_words'] ?? [], 0, 15));
        if ($dw !== []) {
            $lines[] = '- Words the user uses noticeably more than their correspondents: '.implode(', ', $dw).'.';
        }

        $p = $s['punctuation'] ?? [];
        $habits = [];
        if (isset($p['exclamations_per_mail'])) {
            $habits[] = match (true) {
                $p['exclamations_per_mail'] >= 1.5 => 'uses exclamation marks freely',
                $p['exclamations_per_mail'] >= 0.4 => 'an exclamation mark now and then',
                default => 'almost never uses exclamation marks',
            };
        }
        if (isset($p['emoji_share'], $p['smiley_share'])) {
            $emo = max($p['emoji_share'], $p['smiley_share']);
            $habits[] = $emo >= 0.3 ? 'often adds an emoji or smiley' : ($emo >= 0.08 ? 'occasionally a smiley' : 'no emoji or smileys');
        }
        if (($p['ellipsis_share'] ?? 0) >= 0.2) {
            $habits[] = 'likes "…" trailing dots';
        }
        if (($p['list_share'] ?? 0) >= 0.2) {
            $habits[] = 'uses bullet or numbered lists when there are several points';
        }
        if (($p['lowercase_start_share'] ?? 0) >= 0.3) {
            $habits[] = 'often starts the text in lower case';
        }
        if ($habits !== []) {
            $lines[] = '- Habits: '.implode('; ', $habits).'.';
        }

        $text = implode("\n", $lines);

        return mb_strlen($text) > $maxChars ? rtrim(mb_substr($text, 0, $maxChars)).' […]' : $text;
    }

    // ---------------------------------------------------------------- data

    /** @return Builder<Message> mail the user wrote (Sent label or own address), no drafts */
    private function sentQuery(Account $account): Builder
    {
        return Message::query()
            ->where('account_id', $account->id)
            ->visible()
            ->where(fn (Builder $q) => $q->whereJsonContains('label_ids', 'SENT')->orWhere('from_email', strtolower((string) $account->email)))
            ->whereJsonDoesntContain('label_ids', 'DRAFT')
            ->whereNotNull('sent_at');
    }

    /** @return array<int, array{id: int, text: string, sent_at: ?string, to: string[], subject: string}> */
    private function sentMails(Account $account): array
    {
        $out = [];
        $this->sentQuery($account)
            ->select(['id', 'subject', 'body_text', 'snippet', 'to_recipients', 'sent_at'])
            ->orderByDesc('sent_at')
            ->limit(self::MAX_SENT)
            ->get()
            ->each(function (Message $m) use (&$out): void {
                $text = OwnText::of($m->body_text ?? $m->snippet ?? '');
                if ($text === '') {
                    return;
                }
                $out[] = [
                    'id' => $m->id,
                    'text' => $text,
                    'sent_at' => $m->sent_at?->toIso8601String(),
                    'to' => array_values(array_filter(array_map(fn ($r) => (string) ($r['email'] ?? ''), $m->to_recipients ?? []))),
                    'subject' => (string) $m->subject,
                ];
            });

        return $out;
    }

    /** @return string[] own text of recent mails written by other people */
    private function otherTexts(Account $account): array
    {
        return Message::query()
            ->where('account_id', $account->id)
            ->visible()
            ->whereJsonDoesntContain('label_ids', 'SENT')
            ->whereJsonDoesntContain('label_ids', 'DRAFT')
            ->where('from_email', '!=', strtolower((string) $account->email))
            ->select(['body_text', 'snippet'])
            ->orderByDesc('received_at')
            ->limit(self::MAX_OTHERS)
            ->get()
            ->map(fn (Message $m) => OwnText::of($m->body_text ?? $m->snippet ?? ''))
            ->filter()
            ->values()
            ->all();
    }

    /** @return string[] lower-case name parts of the mailbox owner */
    public static function nameParts(Account $account): array
    {
        $parts = preg_split('/[\s,.]+/u', mb_strtolower((string) $account->display_name)) ?: [];
        $local = explode('@', (string) $account->email)[0] ?? '';
        foreach (preg_split('/[._\-]+/', $local) ?: [] as $p) {
            $parts[] = mb_strtolower($p);
        }

        return array_values(array_unique(array_filter($parts, fn ($p) => mb_strlen($p) >= 2)));
    }
}
