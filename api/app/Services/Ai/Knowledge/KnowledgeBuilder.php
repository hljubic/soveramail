<?php

namespace App\Services\Ai\Knowledge;

use App\Models\Account;
use App\Models\Category;
use App\Models\Contact;
use App\Models\KnowledgeItem;
use App\Models\Message;
use App\Models\StyleProfile;
use App\Services\Ai\AiSettings;
use App\Services\Ai\AiUnavailableException;
use App\Services\Ai\Language\Languages;
use App\Services\Ai\ModelRuntime;
use App\Services\Ai\OllamaClient;
use App\Services\Ai\ThinkTagFilter;
use App\Services\Mail\OwnText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Builds what the local AI knows about the user, beyond the writing style, by
 * reading the mailbox with the local chat model (nothing leaves the computer):
 *
 *  - about:     who the user is: roles, organisations, teaching, projects and the
 *               user's role in each, people they work with, standing facts
 *  - audience:  per category, how the user writes there (register, greetings, language)
 *  - faq:       per category, questions that keep coming and how the user answers
 *  - contact:   per frequent correspondent, who that is and how the user addresses them
 *
 * Every item is built once and kept; a rebuild drops the model-written ones. Items the
 * user edited by hand (source = user) are never touched. Works in time slices.
 */
class KnowledgeBuilder
{
    /** Contacts that get a card: the ones the user wrote to most, at least twice. */
    public const MAX_CONTACTS = 100;

    public const MIN_CONTACT_SENT = 2;

    /** Characters per mail excerpt and how many mails each kind reads. */
    private const EXCERPT = 900;

    private const ABOUT_MAILS = 30;

    private const ABOUT_SUBJECTS = 300;

    private const AUDIENCE_MAILS = 24;

    private const FAQ_PAIRS = 28;

    private const CONTACT_MAILS = 6;

    /** Context window for these long reads (the chat model is the strongest one). */
    private const NUM_CTX = 24576;

    /**
     * Build whatever is still missing, until the time budget runs out.
     *
     * @return array{built: int, remaining: int}
     */
    public function buildPending(Account $account, int $budgetSeconds = 420): array
    {
        $settings = AiSettings::load();
        if (! $settings->isReady()) {
            throw new AiUnavailableException('Pick a local chat model first (Configure AI).');
        }

        $deadline = microtime(true) + $budgetSeconds;
        $client = new OllamaClient($settings->baseUrl, self::NUM_CTX, thinking: false);
        $built = 0;
        foreach ($this->pendingTasks($account) as $task) {
            if (microtime(true) > $deadline) {
                break;
            }
            ModelRuntime::yieldToUser();
            $this->build($account, $task, $client, $settings);
            $built++;
        }

        return ['built' => $built, 'remaining' => count($this->pendingTasks($account))];
    }

    /**
     * Everything that should exist for this mailbox.
     *
     * @return array<int, array{kind: string, subject: string, title: string}>
     */
    public function tasks(Account $account): array
    {
        $tasks = [['kind' => KnowledgeItem::KIND_ABOUT, 'subject' => '', 'title' => 'About you']];
        foreach (Category::query()->ordered()->where('is_fallback', false)->get() as $c) {
            $tasks[] = ['kind' => KnowledgeItem::KIND_AUDIENCE, 'subject' => (string) $c->id, 'title' => $c->name];
            $tasks[] = ['kind' => KnowledgeItem::KIND_FAQ, 'subject' => (string) $c->id, 'title' => $c->name];
        }
        foreach ($this->topContacts($account) as $contact) {
            $tasks[] = ['kind' => KnowledgeItem::KIND_CONTACT, 'subject' => strtolower($contact->email), 'title' => $contact->name ?: $contact->email];
        }

        return $tasks;
    }

    /** @return array<int, array{kind: string, subject: string, title: string}> */
    public function pendingTasks(Account $account): array
    {
        $have = KnowledgeItem::query()->where('account_id', $account->id)->get(['kind', 'subject'])
            ->map(fn (KnowledgeItem $i) => $i->kind.'|'.$i->subject)->flip();

        return array_values(array_filter($this->tasks($account), fn ($t) => ! $have->has($t['kind'].'|'.$t['subject'])));
    }

    /** @return array{total: int, done: int} */
    public function progress(Account $account): array
    {
        $total = count($this->tasks($account));

        return ['total' => $total, 'done' => $total - count($this->pendingTasks($account))];
    }

    /** @return Collection<int, Contact> */
    public function topContacts(Account $account): Collection
    {
        $me = strtolower((string) $account->email);

        return Contact::query()
            ->where('account_id', $account->id)
            ->where('sent_count', '>=', self::MIN_CONTACT_SENT)
            ->whereRaw('LOWER(email) != ?', [$me])
            ->where('email', 'not like', '%noreply%')
            ->where('email', 'not like', '%no-reply%')
            ->where('email', 'not like', '%donotreply%')
            ->orderByDesc('sent_count')
            ->limit(self::MAX_CONTACTS)
            ->get();
    }

    // ---------------------------------------------------------------- one item

    /** @param  array{kind: string, subject: string, title: string}  $task */
    public function build(Account $account, array $task, OllamaClient $client, AiSettings $settings): void
    {
        [$material, $evidence] = match ($task['kind']) {
            KnowledgeItem::KIND_ABOUT => $this->aboutMaterial($account),
            KnowledgeItem::KIND_AUDIENCE => $this->audienceMaterial($account, (int) $task['subject']),
            KnowledgeItem::KIND_FAQ => $this->faqMaterial($account, (int) $task['subject']),
            KnowledgeItem::KIND_CONTACT => $this->contactMaterial($account, $task['subject']),
        };

        $content = '';
        if ($material !== null) {
            $text = $client->chat((string) $settings->model, [
                ['role' => 'system', 'content' => self::prompt($task, $account, $settings)],
                ['role' => 'user', 'content' => $material],
            ], timeout: 600, temperature: 0.2);
            $content = self::clean($text, self::LIMITS[$task['kind']]);
        }

        // An item the user wrote meanwhile wins (unique key: create only when still absent).
        KnowledgeItem::query()->firstOrCreate(
            ['account_id' => $account->id, 'kind' => $task['kind'], 'subject' => $task['subject']],
            ['title' => mb_substr($task['title'], 0, 255), 'content' => $content, 'source' => KnowledgeItem::SOURCE_AI,
                'model' => $settings->model, 'evidence' => $evidence, 'built_at' => now()],
        );
    }

    /** Longest text kept per kind (the prompt block trims again to its own budget). */
    public const LIMITS = [
        KnowledgeItem::KIND_ABOUT => 4000,
        KnowledgeItem::KIND_AUDIENCE => 2000,
        KnowledgeItem::KIND_FAQ => 2400,
        KnowledgeItem::KIND_CONTACT => 900,
    ];

    /** Model output as plain bullets; "NONE" (nothing recurring / nothing known) becomes ''. */
    public static function clean(string $text, int $limit): string
    {
        $text = trim(ThinkTagFilter::strip($text));
        $text = (string) preg_replace('/\*\*(.+?)\*\*/u', '$1', $text);
        if ($text === '' || preg_match('/^\W*none\W*$/i', $text)) {
            return '';
        }

        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit)).' […]' : $text;
    }

    // ---------------------------------------------------------------- prompts

    /** @param  array{kind: string, subject: string, title: string}  $task */
    public static function prompt(array $task, Account $account, AiSettings $settings): string
    {
        $me = $account->display_name ? "{$account->display_name} <{$account->email}>" : $account->email;
        $lang = $settings->replyLanguage !== null ? Languages::name($settings->replyLanguage) : 'the language most of the e-mails are written in';
        $common = implode("\n", [
            "Write in {$lang}. Plain text: lines starting with \"- \", no headings, no markdown, no introduction, no closing remark.",
            'Only state what the e-mails show; never guess or invent. Quote the user\'s own wording in double quotes where it helps.',
            'Never write down passwords, login or verification codes, bank or card numbers, personal ID numbers, health information or anything similarly sensitive, even if it appears in the e-mails.',
        ]);

        return match ($task['kind']) {
            KnowledgeItem::KIND_ABOUT => <<<P
You build a factual profile of {$me} from their own e-mails, so that an assistant writing e-mails in their name knows who they are and what they do.
Start each line with one of these labels and a colon, in this order, several lines per label where the e-mails support it:
ROLE (titles, positions, functions), ORGANISATION (companies, institutions, departments, teams), WORK (products, services, clients, courses or other recurring work the user is responsible for), PROJECTS (each project and the user's role in it), RESPONSIBILITIES (what people regularly turn to the user for), PEOPLE (colleagues and partners the user works with most and in which matter; names as written), STANDING FACTS (contact details, office hours, links, addresses, the way the user signs, exactly as written), WORKING HABITS (how the user delegates, follows up, answers).
Up to 40 lines, under 3500 characters. Skip a label when the e-mails show nothing for it.
{$common}
P,
            KnowledgeItem::KIND_AUDIENCE => <<<P
You study how {$me} writes e-mails in one area of their work: "{$task['title']}". Another assistant will use your notes to write new e-mails in that area in the user's voice.
Lines with these labels, in this order: WHO (who the user writes to here), LANGUAGE (which language with whom), REGISTER (formal/informal, "ti"/"Vi", first names or titles), OPENING (greetings, quoted), CLOSING (sign-offs and signature, quoted), LENGTH AND SHAPE, TYPICAL CONTENT (what these mails usually do: inform, ask, confirm, ...), PHRASES (recurring wording, quoted), NEVER (what the user clearly avoids here).
Up to 20 lines, under 1800 characters.
{$common}
P,
            KnowledgeItem::KIND_FAQ => <<<P
You get pairs from the area "{$task['title']}" of {$me}'s mailbox: a message the user received and the reply the user wrote.
Find the questions or requests that come up AGAIN AND AGAIN (in at least two pairs) and how the user typically answers them.
One line per recurring question: "- Q: <the question in general form> → A: <the user's usual answer, with their own wording quoted and the concrete facts they always give>".
At most 12 lines, under 2200 characters. If nothing recurs, answer exactly NONE.
{$common}
P,
            KnowledgeItem::KIND_CONTACT => <<<P
You write a short card about one person {$me} corresponds with: {$task['title']} <{$task['subject']}>. An assistant writing to this person in the user's name will read it.
Lines with these labels: WHO (role, organisation and relation to the user, as far as the mails show), ADDRESS (how the user greets and addresses them: "ti"/"Vi", first name or title, sign-off; quote), LANGUAGE, TOPICS (what they write about), NOTE (anything else that matters when writing to them).
Up to 7 lines, under 800 characters.
{$common}
P,
        };
    }

    // ---------------------------------------------------------------- material

    /** @return array{0: ?string, 1: int} the text the model reads, and how many mails it is based on */
    private function aboutMaterial(Account $account): array
    {
        $sent = $this->mine($account)->orderByDesc('sent_at')->limit(1500)->get(['id', 'subject', 'body_text', 'snippet', 'to_recipients', 'sent_at']);
        if ($sent->isEmpty()) {
            return [null, 0];
        }

        $subjects = $sent->pluck('subject')->filter()
            ->map(fn ($s) => trim((string) preg_replace('/^((re|fw|fwd|aw|odg|sv)\s*:\s*)+/i', '', (string) $s)))
            ->filter()->unique()->take(self::ABOUT_SUBJECTS)->values();
        $excerpts = $this->spread($sent, self::ABOUT_MAILS)->map(fn (Message $m) => $this->excerpt($m, true))->implode("\n\n");

        $parts = ["Subjects of e-mails the user sent (newest first):\n- ".$subjects->implode("\n- ")];
        $categories = Category::query()->ordered()->where('is_fallback', false)->get();
        if ($categories->isNotEmpty()) {
            $parts[] = "Areas the user sorts their mail into:\n".$categories->map(fn ($c) => "- {$c->name}".($c->description ? ': '.mb_substr($c->description, 0, 300) : ''))->implode("\n");
        }
        $people = $this->topContacts($account)->take(25)->map(fn (Contact $c) => '- '.($c->name ? "{$c->name} <{$c->email}>" : $c->email)." (user wrote {$c->sent_count}×)");
        if ($people->isNotEmpty()) {
            $parts[] = "People the user writes to most:\n".$people->implode("\n");
        }
        $signature = StyleProfile::query()->where('account_id', $account->id)->first()?->stats['signature']['lines'] ?? [];
        if ($signature !== []) {
            $parts[] = "The user's signature block:\n".implode("\n", array_slice($signature, 0, 10));
        }
        $parts[] = "E-mails the user wrote (a spread over time):\n\n".$excerpts;

        return [implode("\n\n", $parts), $sent->count()];
    }

    private function audienceMaterial(Account $account, int $categoryId): array
    {
        $sent = $this->mine($account)->whereExists($this->inCategory($categoryId))
            ->orderByDesc('sent_at')->limit(400)->get(['id', 'subject', 'body_text', 'snippet', 'to_recipients', 'sent_at']);
        if ($sent->count() < 3) {
            return [null, $sent->count()];
        }

        return ["E-mails the user wrote in this area:\n\n".$this->spread($sent, self::AUDIENCE_MAILS)->map(fn (Message $m) => $this->excerpt($m, true))->implode("\n\n"), $sent->count()];
    }

    private function faqMaterial(Account $account, int $categoryId): array
    {
        $me = strtolower((string) $account->email);
        $threads = Message::query()->where('account_id', $account->id)->visible()->whereNotNull('thread_id')
            ->whereExists($this->inCategory($categoryId))
            ->groupBy('thread_id')->orderByRaw('MAX(received_at) DESC')->limit(300)->pluck('thread_id');

        $pairs = [];
        foreach ($threads->chunk(50) as $chunk) {
            $byThread = Message::query()->where('account_id', $account->id)->visible()->whereIn('thread_id', $chunk)
                ->orderBy('received_at')->orderBy('id')->get(['id', 'thread_id', 'subject', 'body_text', 'snippet', 'from_email', 'from_name', 'label_ids', 'to_recipients', 'sent_at', 'received_at'])
                ->groupBy('thread_id');
            foreach ($byThread as $messages) {
                $question = null;
                foreach ($messages as $m) {
                    $mine = strtolower((string) $m->from_email) === $me || in_array('SENT', $m->label_ids ?? [], true);
                    if (! $mine) {
                        $question = $m;
                    } elseif ($question !== null) {
                        $pairs[] = [$question, $m];
                        $question = null;
                    }
                }
            }
            if (count($pairs) >= self::FAQ_PAIRS * 2) {
                break;
            }
        }
        if (count($pairs) < 4) {
            return [null, count($pairs)];
        }

        $text = collect($pairs)->sortByDesc(fn ($p) => $p[1]->sent_at)->take(self::FAQ_PAIRS)->values()
            ->map(fn ($p, $i) => '=== Pair '.($i + 1)." ===\nRECEIVED from ".($p[0]->from_name ?: $p[0]->from_email).":\n".$this->body($p[0], 500)."\nTHE USER REPLIED:\n".$this->body($p[1], 500))
            ->implode("\n\n");

        return ["Received messages and the user's replies:\n\n".$text, count($pairs)];
    }

    private function contactMaterial(Account $account, string $email): array
    {
        $email = strtolower($email);
        $received = Message::query()->where('account_id', $account->id)->visible()->whereRaw('LOWER(from_email) = ?', [$email])
            ->orderByDesc('received_at')->limit(self::CONTACT_MAILS)->get();
        // Recipient lists are JSON: a LIKE narrows the scan, the exact match is done here.
        $sent = $this->mine($account)->where('to_recipients', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $email).'%')
            ->orderByDesc('sent_at')->limit(60)->get()
            ->filter(fn (Message $m) => collect(array_merge($m->to_recipients ?? [], $m->cc_recipients ?? []))
                ->contains(fn ($a) => strtolower((string) ($a['email'] ?? '')) === $email))
            ->take(self::CONTACT_MAILS);
        if ($sent->count() + $received->count() < 2) {
            return [null, 0];
        }

        $mails = $sent->map(fn (Message $m) => ['when' => $m->sent_at, 'text' => '--- The user wrote to them, '.($m->sent_at?->format('Y-m-d') ?? '')." · {$m->subject} ---\n".$this->body($m, 600)])
            ->merge($received->map(fn (Message $m) => ['when' => $m->received_at, 'text' => '--- They wrote to the user, '.($m->received_at?->format('Y-m-d') ?? '')." · {$m->subject} ---\n".$this->body($m, 600)]))
            ->sortBy('when')->pluck('text')->implode("\n\n");

        return ["E-mails between the user and this person (oldest first):\n\n".$mails, $sent->count() + $received->count()];
    }

    // ---------------------------------------------------------------- helpers

    /** Mail the user sent (as SentQuery in the style profile). */
    private function mine(Account $account): Builder
    {
        return Message::query()
            ->where('account_id', $account->id)
            ->visible()
            ->where(fn (Builder $q) => $q->whereJsonContains('label_ids', 'SENT')->orWhere('from_email', strtolower((string) $account->email)))
            ->whereJsonDoesntContain('label_ids', 'DRAFT')
            ->whereNotNull('sent_at');
    }

    private function inCategory(int $categoryId): \Closure
    {
        return fn ($q) => $q->selectRaw('1')->from('thread_categories')
            ->whereColumn('thread_categories.account_id', 'messages.account_id')
            ->whereColumn('thread_categories.thread_id', 'messages.thread_id')
            ->where('thread_categories.category_id', $categoryId);
    }

    /** $n mails spread evenly over the list (newest first), not just the latest ones. */
    private function spread(Collection $mails, int $n): Collection
    {
        if ($mails->count() <= $n) {
            return $mails->values();
        }
        $step = $mails->count() / $n;

        return collect(range(0, $n - 1))->map(fn ($i) => $mails[(int) floor($i * $step)])->values();
    }

    private function excerpt(Message $m, bool $withRecipients): string
    {
        $to = $withRecipients ? collect($m->to_recipients ?? [])->map(fn ($a) => $a['name'] ?? $a['email'] ?? '')->filter()->take(3)->implode(', ') : '';

        return '--- '.($m->sent_at?->format('Y-m-d') ?? '').($to !== '' ? " · to {$to}" : '')." · {$m->subject} ---\n".$this->body($m, self::EXCERPT);
    }

    private function body(Message $m, int $max): string
    {
        $text = trim(OwnText::of($m->body_text ?? $m->snippet ?? ''));

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max).' […]' : ($text !== '' ? $text : '(no text)');
    }
}
