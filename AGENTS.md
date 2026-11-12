# Sovera Mail — notes for agents and contributors

Local-first, open-source mail client with on-device AI. Read this before touching the AI
code or the Docker setup. Per-part notes: `api/AGENTS.md`. User-facing docs: `README.md`.

## Layout and how it runs

- `web/` Nuxt 4 / Vue 3 / Tailwind 4 SPA, served by the `web` container on :3000. The user
  runs Sovera Mail **in the browser**.
- `api/` Laravel 13 API, **only in Docker** (`docker compose` at the repo root): nginx :8090,
  MySQL :3307, `queue` and `queue-ai` workers, `scheduler`. `make test`, `make lint`,
  `make restart`.
- Ollama (the model runner) runs on the host. From Docker the API reaches it as
  `host.docker.internal:11434`; `AiSettings::normaliseBaseUrl()` rewrites `localhost` to that.

Two queue workers: `queue` (default: sync, send) and `queue-ai` (`ai`: the AI pass, re-sort,
rebuild), so AI work runs while mail is fetched. All long jobs work in slices and resume after
a restart. Mail data lives in the `mysql-data` volume (only `make reset`, which asks for
"DELETE", removes it).

Mail is fetched when the UI opens and then every 5 min **only while a window polls
`/api/accounts`** (`AppPresence`, `sovera:sync-accounts --only-while-open`). Nothing sends mail
on its own: only MessageController after the user's confirmed Send
(`tests/Feature/Api/NoAutoSendTest.php` guards it).

**After changing backend code run `make restart`**: `queue`, `queue-ai` and `scheduler` are
long-running PHP processes and keep the old code until restarted.

Mail providers: Gmail (REST API, Google OAuth) and IMAP/SMTP. One interface,
`Services/Mail/Providers/MailProvider`; `MailProviders::for($account)` picks the backend.

## AI setup

One manual setup (`web/app/pages/ai.vue`): Ollama server URL, chat model, embedding model,
streaming, "Think before writing", remote-server toggle. There is no automatic model
selection or download; the user installs Ollama and pulls models.

Model names are canonicalised by `AiSettings::normaliseModel()` (`bge-m3` → `bge-m3:latest`):
Ollama treats them as one model, but the vector index is keyed by the exact string.

Every chat-model call uses one context window, `AiSettings::CHAT_CONTEXT` (24576): a different
`num_ctx` makes Ollama reload the whole model. Requests the user waits for (drafts, "Summarize"
on click, smart search) run inside `ModelRuntime::interactive()`; background loops (AI pass,
knowledge, classifier, index) call `ModelRuntime::yieldToUser()` before each model call and
pause meanwhile (Ollama runs one request per model at a time). An empty draft is an error,
never an empty text.

`ai.thinking` (default **off**, shown only for models with the `thinking` capability) sets
Ollama's `think` flag for drafts and smart search. Reasoning models can think for minutes on
real mail; while they do, the draft stream sends `stage: thinking` every ~5 s (keeps nginx's
120 s read timeout from firing); the reasoning text is never sent.

Use Ollama **GGUF** model tags: the MLX runner answers 501 to `format` (JSON schema);
`chatJson` then falls back to the schema in the prompt.

## AI processing — nothing runs on its own

The local AI starts only when the user clicks **"Process new"** (top bar,
`POST /api/ai/process`, or `php artisan sovera:process`). There is no schedule and no pass
after a sync.

`AiPassJob` (`ai` queue, 420 s slices, state in cache `ai-pass:<account>`) runs the phases in
order so each model loads once: **threads** (`NewMailProcessor`: conversations active in the
last `AI_PROCESS_DAYS`=30 days; ONE chat-model JSON call per conversation returns category,
urgency of unread mail and the summary lines — `summary` for the thread, `latest` for the
newest unread message — when `summaries_in_pass`, above `summary_min_words` of own text and not
bulk mail per `summary_skip_bulk`; a different summary model gets its own call) → **style** (if
stale) → **knowledge** (missing items) → **index** (embeddings). Reply-language detection runs
at its start.

Unloading: every Sovera request sends `keep_alive: -1`, so a model stays loaded until the user
clicks **"Unload"** in the sidebar status line (`GET /api/ai/runtime`, `POST
/api/ai/runtime/unload`). Only models Sovera has used can be unloaded; another app's model is
refused with 409; not while a pass runs.

## Categories and urgency

Local categories (`categories`, page `web/app/pages/categories.vue`) exist only in Sovera Mail.
`ThreadClassifier` gives every thread **one** category (`thread_categories`, judged from the
whole thread, once) and every message that is **unread** when it is reached an urgency
(`messages.urgency` high/medium/low, judged once; shown as the colour of the unread dot).
Answers go through an Ollama JSON schema whose values are enums, so the model can only pick.
`source = user` (picked by hand) is never overridden and is fed back to the model as examples.
"Other" is the undeletable fallback.

Categories use `ai.classify_model` (falls back to the chat model), urgency always the chat
model. `ClassifyAccountJob` is the bulk tool: Re-sort, deleting a category, "Rebuild
everything". `POST /api/ai/rebuild` throws away and rebuilds everything derived from the
mailbox (vector index, style profile, AI categories, urgency of unread mail, summaries);
hand-made choices stay.

## Knowledge, style, summaries, proofreading

- `KnowledgeBuilder` writes `knowledge_items`: `about` (roles, organisations, work, projects,
  people, standing facts), per category `audience` and `faq` (recurring questions and the
  user's usual answers), and `contact` cards for the 100 people the user writes to most.
  Prompts forbid recording passwords, codes, account or ID numbers and health data. Drafts get
  `KnowledgeContext::block()`. `source = user` (edited on the AI instructions page) is never
  overwritten.
- Style profile (`style_profiles`) = deterministic stats from all sent mail + a model-written
  guide (30 mails spread over the last 300); corrections the user makes to AI drafts
  (`ai_suggestions`, word-level diff on send) are the strongest signal in the prompt.
- `SummaryService` writes one line per important fact for a whole conversation
  (`scope=thread`, keyed by the newest message + message count) or one message. Results live in
  `ai_summaries`.
- `Proofreader` (`ai.proofread`) runs one more chat-model call over drafts and summary lines
  to fix invented words and wrong forms in the text's own language. An answer that looks like a
  rewrite (length ratio outside 0.7–1.4, empty) is ignored.
- Reply language is judged **only by the local LLM** from ≥30 sent mails (JSON schema,
  confidence ≥ 0.75), once, never over a user choice.

## Mailbox addresses

Every view has its own URL (`composables/useMailRoute.ts`): `/` Inbox, `/unread`, `/starred`,
`/sent`, `/drafts`, `/archive`, `/all`, `/category/<id>`, `/label/<gmail label id>`; an open
conversation adds `?m=<id>`. The address is the source of truth: the sidebar only navigates,
`MailboxView.vue` applies it.

## Search and the index

Plain search is keyword only: MySQL FULLTEXT (`messages_fulltext`, boolean prefix mode), newest
first, no AI. Smart search (LLM → filters + vector ranking) runs only with the "Smart search"
switch, on Enter. A reranker second pass was tried and removed — do not re-add unasked.

The vector index (`message_embeddings`, several rows per message: `chunk`, `version`) embeds each
message's own text (quoted history stripped by `OwnText`), cut at paragraph / sentence ends into
pieces of ~2000 characters (max 8), each prefixed with subject, sender and recipients. A message
scores as its best piece. Never attachment contents: attachments are stored as metadata only and
their bytes are fetched only on an explicit user action (preview, download, forward).

## Privacy rule for agents

Never read a user's mailbox data (message content, the vector index, the style profile, the
knowledge items) — not for tests, not for debugging. Use fixtures; measure quality with in-app
tools that print numbers only (e.g. `sovera:eval-classifier`), and ask first.

## Invariants

- Never delete mail; sends are confirmed and idempotent (see README).
- Nothing runs the AI or sends mail without a user action.
