<?php

namespace App\Services\Ai;

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Message;
use App\Services\Ai\Retrieval\VectorStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

/**
 * "Smart search": a natural-language request such as
 *   "svi mailovi koji imaju word i pdf dokument a odnose se na silabuse kolegija"
 * becomes
 *   1. structured filters (attachment kinds, sender, dates, flags) — extracted by the
 *      local chat model as JSON and applied in SQL, and
 *   2. a topic ("silabusi kolegija") — ranked over the pre-filtered candidates with the
 *      per-account vector index (falls back to full-text when there is no index yet).
 *
 * Everything runs locally against Ollama; nothing leaves the machine.
 */
class SmartSearchService
{
    public const LIMIT = 100;

    /** How far below the best hit a semantic result may fall before it is dropped. */
    public const RELATIVE_CUTOFF = 0.8;

    /** Attachment kinds the model may name, with how we recognise them locally. */
    public const ATTACHMENT_TYPES = [
        'pdf' => ['label' => 'PDF', 'mimes' => ['application/pdf'], 'ext' => ['pdf']],
        'word' => ['label' => 'Word', 'mimes' => ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.oasis.opendocument.text', 'application/rtf'], 'ext' => ['doc', 'docx', 'odt', 'rtf']],
        'excel' => ['label' => 'Excel', 'mimes' => ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.oasis.opendocument.spreadsheet', 'text/csv'], 'ext' => ['xls', 'xlsx', 'ods', 'csv']],
        'powerpoint' => ['label' => 'PowerPoint', 'mimes' => ['application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/vnd.oasis.opendocument.presentation'], 'ext' => ['ppt', 'pptx', 'odp']],
        'image' => ['label' => 'Image', 'mimes' => ['image/'], 'ext' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'svg']],
        'archive' => ['label' => 'Archive', 'mimes' => ['application/zip', 'application/x-zip-compressed', 'application/x-rar-compressed', 'application/vnd.rar', 'application/x-7z-compressed', 'application/gzip', 'application/x-tar'], 'ext' => ['zip', 'rar', '7z', 'gz', 'tar']],
        'text' => ['label' => 'Text', 'mimes' => ['text/plain', 'text/markdown'], 'ext' => ['txt', 'md']],
    ];

    /** Above this many candidates the vector scan is not restricted by id. */
    private const MAX_ID_FILTER = 5000;

    private const FOLDERS = ['inbox', 'sent', 'starred', 'unread', 'drafts', 'archive', 'spam', 'trash', 'all'];

    public function __construct(private readonly VectorStore $store) {}

    /**
     * @return array{messages: Collection<int, Message>, interpretation: array<string, mixed>, filters: string[], ranking: 'semantic'|'fulltext'|'recent', parsed: bool}
     */
    public function search(Account $account, string $query, int $limit = self::LIMIT): array
    {
        // The user is waiting: background processing pauses meanwhile.
        return ModelRuntime::interactive(fn () => $this->run($account, $query, $limit));
    }

    private function run(Account $account, string $query, int $limit): array
    {
        $settings = AiSettings::load();
        if (! $settings->isReady()) {
            throw new AiUnavailableException('Local AI is not configured. Open "Configure AI" and pick an Ollama model to use smart search.');
        }

        $query = trim($query);
        $client = new OllamaClient($settings->baseUrl, $settings->rag()->contextTokens, $settings->thinking);

        [$interpretation, $parsed] = $this->interpret($client, (string) $settings->model, $query);

        $candidates = $this->candidates($account, $interpretation);
        $hasFilters = $this->hasFilters($interpretation);
        $topic = $interpretation['topic'];

        $ranking = 'recent';
        $messages = null;

        if ($topic !== null && $settings->canEmbed()) {
            $messages = $this->semantic($client, $settings, $account, $candidates, $topic, $hasFilters, $limit);
            if ($messages !== null) {
                $ranking = 'semantic';
            }
        }
        if ($messages === null && $topic !== null) {
            $messages = (clone $candidates)->search($topic)->orderByDesc('received_at')->orderByDesc('id')->limit($limit)->get();
            $ranking = 'fulltext';
        }
        if ($messages === null) {
            $messages = $candidates->orderByDesc('received_at')->orderByDesc('id')->limit($limit)->get();
        }

        return [
            'messages' => $messages,
            'interpretation' => $interpretation,
            'filters' => $this->describe($interpretation),
            'ranking' => $ranking,
            'parsed' => $parsed,
        ];
    }

    // ---------------------------------------------------------------- parsing

    /**
     * Ask the chat model to turn the sentence into filters. Any failure degrades to
     * "treat the whole sentence as the topic" so the search still works.
     *
     * @return array{0: array<string, mixed>, 1: bool}
     */
    private function interpret(OllamaClient $client, string $model, string $query): array
    {
        try {
            $raw = $client->chatJson($model, [
                ['role' => 'system', 'content' => $this->systemPrompt()],
                ['role' => 'user', 'content' => $query],
            ], self::schema());
        } catch (AiTimeoutException $e) {
            // Slow model: search with the sentence as the topic instead of failing.
            logger()->info('Smart search: interpretation timed out, using the sentence as topic');
            $raw = null;
        } catch (AiUnavailableException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            $raw = null;
        }

        if ($raw === null) {
            return [$this->normalise(['topic' => $query]), false];
        }

        $clean = $this->normalise($raw);
        // A model that drops everything is worse than no model: fall back to the sentence.
        if ($clean['topic'] === null && ! $this->hasFilters($clean)) {
            $clean['topic'] = $query;
        }

        return [$clean, true];
    }

    private function systemPrompt(): string
    {
        $today = CarbonImmutable::now()->toDateString();
        $types = implode(', ', array_keys(self::ATTACHMENT_TYPES));

        return <<<PROMPT
You convert a natural-language request to search a personal mailbox into a JSON filter. Today is {$today}.
The request may be in any language. Answer with JSON only.

Fields:
- topic: what the e-mails should be ABOUT, as a short phrase in the same language as the request (e.g. "silabusi kolegija", "invoices for the server"). null when the request only describes metadata (sender, dates, attachments, flags) and says nothing about the content.
- attachment_types: kinds of attached files the user asked for, from: {$types}. "word"/"docx"/"dokument u Wordu" -> word; "pdf" -> pdf; "tablica"/"excel"/"xlsx" -> excel; "prezentacija"/"ppt" -> powerpoint; "slika"/"fotografija" -> image; "zip"/"arhiva" -> archive. Empty array when no kind is mentioned.
- attachments_mode: "all" when every listed kind must be present in the same e-mail, "any" when one of them is enough. When unsure use "any".
- has_attachments: true when the user wants e-mails with (any) attachments, false when explicitly without, otherwise null.
- from: a fragment of the sender's name or address, or null. to: the same for recipients, or null.
- date_from / date_to: ISO dates (YYYY-MM-DD) derived from the request, otherwise null. Resolve relative dates ("prošli mjesec", "last week", "in June") from today's date.
- unread / starred: true or false only when stated, otherwise null.
- folder: one of inbox, sent, starred, unread, drafts, archive, spam, trash, all. Always "all" unless the user explicitly names a folder ("u poslanima" -> sent, "u inboxu" -> inbox, "u smeću" -> trash). "svi mailovi" / "all mails" means "all", not inbox.

Rules: never invent filters that are not in the request. Do not put the attachment or date words into topic. Keep topic short; it is used for semantic similarity, not exact matching.
PROMPT;
    }

    /** JSON schema handed to Ollama's structured outputs. */
    private static function schema(): array
    {
        $nullable = fn (string $type) => ['type' => [$type, 'null']];

        return [
            'type' => 'object',
            'properties' => [
                'topic' => $nullable('string'),
                'attachment_types' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => array_keys(self::ATTACHMENT_TYPES)]],
                'attachments_mode' => ['type' => 'string', 'enum' => ['any', 'all']],
                'has_attachments' => $nullable('boolean'),
                'from' => $nullable('string'),
                'to' => $nullable('string'),
                'date_from' => $nullable('string'),
                'date_to' => $nullable('string'),
                'unread' => $nullable('boolean'),
                'starred' => $nullable('boolean'),
                'folder' => $nullable('string'),
            ],
            'required' => ['topic', 'attachment_types', 'attachments_mode', 'has_attachments', 'from', 'to', 'date_from', 'date_to', 'unread', 'starred', 'folder'],
        ];
    }

    /**
     * Whitelist everything the model returned: it is untrusted output that ends up in SQL filters.
     *
     * @return array{topic: ?string, attachment_types: string[], attachments_mode: 'any'|'all', has_attachments: ?bool, from: ?string, to: ?string, date_from: ?string, date_to: ?string, unread: ?bool, starred: ?bool, folder: string}
     */
    private function normalise(array $raw): array
    {
        $str = function (mixed $v, int $max = 200): ?string {
            if (! is_string($v)) {
                return null;
            }
            $v = trim(mb_substr($v, 0, $max));

            return $v === '' || strtolower($v) === 'null' ? null : $v;
        };
        $bool = fn (mixed $v): ?bool => is_bool($v) ? $v : null;
        $date = function (mixed $v): ?string {
            $v = is_string($v) ? trim($v) : '';
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                return null;
            }
            // checkdate() rejects 2026-13-45; Carbon would silently roll it over.
            [$y, $m, $d] = array_map('intval', explode('-', $v));

            return checkdate($m, $d, $y) ? $v : null;
        };

        $types = [];
        foreach ((array) ($raw['attachment_types'] ?? []) as $t) {
            $t = is_string($t) ? strtolower(trim($t)) : '';
            if (isset(self::ATTACHMENT_TYPES[$t]) && ! in_array($t, $types, true)) {
                $types[] = $t;
            }
        }

        $folder = is_string($raw['folder'] ?? null) ? strtolower(trim($raw['folder'])) : 'all';

        return [
            'topic' => $str($raw['topic'] ?? null, 300),
            'attachment_types' => $types,
            'attachments_mode' => ($raw['attachments_mode'] ?? 'any') === 'all' ? 'all' : 'any',
            'has_attachments' => $bool($raw['has_attachments'] ?? null),
            'from' => $str($raw['from'] ?? null, 100),
            'to' => $str($raw['to'] ?? null, 100),
            'date_from' => $date($raw['date_from'] ?? null),
            'date_to' => $date($raw['date_to'] ?? null),
            'unread' => $bool($raw['unread'] ?? null),
            'starred' => $bool($raw['starred'] ?? null),
            'folder' => in_array($folder, self::FOLDERS, true) ? $folder : 'all',
        ];
    }

    private function hasFilters(array $i): bool
    {
        return $i['attachment_types'] !== [] || $i['has_attachments'] !== null || $i['from'] !== null || $i['to'] !== null
            || $i['date_from'] !== null || $i['date_to'] !== null || $i['unread'] !== null || $i['starred'] !== null || $i['folder'] !== 'all';
    }

    // ---------------------------------------------------------------- filtering

    /** @return Builder<Message> */
    private function candidates(Account $account, array $i): Builder
    {
        $q = Message::query()
            ->where('account_id', $account->id)
            ->select([
                'id', 'account_id', 'provider_message_id', 'thread_id', 'subject', 'from_name', 'from_email',
                'to_recipients', 'snippet', 'label_ids', 'is_unread', 'is_starred', 'has_attachments', 'is_risky', 'risk_acknowledged_at',
                'sent_at', 'received_at',
            ])
            ->withExists('localDraft as has_draft')
            ->inFolder($i['folder']);

        if ($i['attachment_types'] !== []) {
            if ($i['attachments_mode'] === 'all') {
                foreach ($i['attachment_types'] as $type) {
                    $q->whereHas('attachments', fn (Builder $a) => $this->attachmentOfKind($a, [$type]));
                }
            } else {
                $q->whereHas('attachments', fn (Builder $a) => $this->attachmentOfKind($a, $i['attachment_types']));
            }
        } elseif ($i['has_attachments'] !== null) {
            $q->where('has_attachments', $i['has_attachments']);
        }

        if ($i['from'] !== null) {
            $like = '%'.self::escapeLike($i['from']).'%';
            $q->where(fn (Builder $w) => $w->where('from_name', 'like', $like)->orWhere('from_email', 'like', $like));
        }
        if ($i['to'] !== null) {
            $q->where('to_recipients', 'like', '%'.self::escapeLike($i['to']).'%');
        }
        if ($i['date_from'] !== null) {
            $q->where('received_at', '>=', CarbonImmutable::parse($i['date_from'])->startOfDay());
        }
        if ($i['date_to'] !== null) {
            $q->where('received_at', '<=', CarbonImmutable::parse($i['date_to'])->endOfDay());
        }
        if ($i['unread'] !== null) {
            $q->where('is_unread', $i['unread']);
        }
        if ($i['starred'] !== null) {
            $q->where('is_starred', $i['starred']);
        }

        return $q;
    }

    /** @param  string[]  $types */
    private function attachmentOfKind(Builder $a, array $types): Builder
    {
        $a->where('is_inline', false);

        return $a->where(function (Builder $w) use ($types): void {
            foreach ($types as $type) {
                $def = self::ATTACHMENT_TYPES[$type];
                foreach ($def['mimes'] as $mime) {
                    str_ends_with($mime, '/')
                        ? $w->orWhere('mime_type', 'like', $mime.'%')
                        : $w->orWhere('mime_type', $mime);
                }
                foreach ($def['ext'] as $ext) {
                    $w->orWhere('filename', 'like', '%.'.$ext);
                }
            }
        });
    }

    private static function escapeLike(string $s): string
    {
        return addcslashes($s, '%_\\');
    }

    // ---------------------------------------------------------------- ranking

    /**
     * Rank the candidates by similarity to the topic. Returns null when the account has
     * no vectors yet (or the embedding model is missing) so the caller can fall back.
     *
     * @param  Builder<Message>  $candidates
     * @return Collection<int, Message>|null
     */
    private function semantic(OllamaClient $client, AiSettings $settings, Account $account, Builder $candidates, string $topic, bool $hasFilters, int $limit): ?Collection
    {
        $model = (string) $settings->embeddingModel;
        if ($this->store->stats($account->id, $model)['indexed'] === 0) {
            return null;
        }

        try {
            $vectors = $client->embed($model, [$topic], 60);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
        $vector = $vectors[0] ?? null;
        if ($vector === null) {
            return null;
        }

        // Only restrict the vector scan when the SQL filters actually narrowed anything.
        // A broad filter (e.g. "inbox" on a big mailbox) would put tens of thousands of
        // ids into one IN (...) (MySQL stops at 65k placeholders); then the whole account
        // is ranked instead and the candidate query below keeps only matching rows.
        $k = $limit;
        $onlyIds = [];
        if ($hasFilters) {
            $count = (clone $candidates)->reorder()->count();
            if ($count === 0) {
                return collect();
            }
            if ($count <= self::MAX_ID_FILTER) {
                $onlyIds = (clone $candidates)->reorder()->pluck('id')->map(fn ($id) => (int) $id)->all();
            } else {
                $k = $limit * 10;
            }
        }

        $hits = $this->store->search($account->id, $model, $vector, $k, [], $onlyIds);
        if ($hits === []) {
            return collect();
        }

        $top = $hits[0]['score'];
        $minScore = max($settings->rag()->minScore, $top * self::RELATIVE_CUTOFF);
        $hits = array_values(array_filter($hits, fn ($h) => $h['score'] >= $minScore));

        $byId = (clone $candidates)->reorder()->whereKey(array_column($hits, 'message_id'))->get()->keyBy('id');

        $out = collect();
        foreach ($hits as $hit) {
            if ($m = $byId->get($hit['message_id'])) {
                $m->setAttribute('score', round($hit['score'], 4));
                $out->push($m);
                if ($out->count() >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------- explaining

    /** Human-readable chips for the UI ("Attachments: PDF + Word", "From: ana", …). */
    private function describe(array $i): array
    {
        $chips = [];
        if ($i['topic'] !== null) {
            $chips[] = 'About: '.$i['topic'];
        }
        if ($i['attachment_types'] !== []) {
            $labels = array_map(fn ($t) => self::ATTACHMENT_TYPES[$t]['label'], $i['attachment_types']);
            $chips[] = 'Attachments: '.implode($i['attachments_mode'] === 'all' ? ' + ' : ' or ', $labels);
        } elseif ($i['has_attachments'] === true) {
            $chips[] = 'With attachments';
        } elseif ($i['has_attachments'] === false) {
            $chips[] = 'Without attachments';
        }
        if ($i['from'] !== null) {
            $chips[] = 'From: '.$i['from'];
        }
        if ($i['to'] !== null) {
            $chips[] = 'To: '.$i['to'];
        }
        if ($i['date_from'] !== null && $i['date_to'] !== null) {
            $chips[] = $i['date_from'].' – '.$i['date_to'];
        } elseif ($i['date_from'] !== null) {
            $chips[] = 'After '.$i['date_from'];
        } elseif ($i['date_to'] !== null) {
            $chips[] = 'Before '.$i['date_to'];
        }
        if ($i['unread'] !== null) {
            $chips[] = $i['unread'] ? 'Unread' : 'Read';
        }
        if ($i['starred'] !== null) {
            $chips[] = $i['starred'] ? 'Starred' : 'Not starred';
        }
        if ($i['folder'] !== 'all') {
            $chips[] = 'Folder: '.ucfirst($i['folder']);
        }

        return $chips;
    }
}
