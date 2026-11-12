# Sovera Mail

Open-source, local-first e-mail client with on-device AI. Your mailbox is copied
into a database on your own computer, and every AI feature (drafts in your own
style, summaries, categories, urgency, smart search) runs on a model server you
run yourself ([Ollama](https://ollama.com)). Nothing is sent to a cloud AI.

| Layer      | Tech                                  | Where it runs     |
| ---------- | ------------------------------------- | ----------------- |
| UI         | Nuxt 4 · Vue 3.5 · Tailwind CSS 4     | Docker (`web`)    |
| API        | Laravel 13 · PHP 8.5                  | Docker (`api`)    |
| Storage    | MySQL 8.4                             | Docker (`mysql`)  |
| Background | Laravel queue workers + scheduler     | Docker            |
| Local AI   | Ollama (any chat + embedding model)   | your machine      |

You use Sovera Mail in your browser at <http://localhost:3000>.

```
.
├── api/        Laravel API: OAuth, Gmail / IMAP sync, mailbox store, local AI
├── web/        Nuxt SPA (the mail UI)
├── docker/     PHP-FPM, nginx, MySQL and web images
└── docker-compose.yml
```

## Features

- **Gmail** (Google OAuth) and **any IMAP/SMTP mailbox** (iCloud, Yahoo, Fastmail,
  Zoho, GMX, Proton Mail Bridge, your own server).
- Three-pane client: conversations, read/unread, star, labels and folders, reply,
  reply all, forward, new mail, drafts, attachments with risk screening.
- Keyword search (MySQL full-text, instant, deterministic) and optional **smart
  search**: describe the mail in your own words, the local model turns it into
  filters and the vector index ranks by topic.
- **AI compose**: drafts in your own voice, built from your earlier mail to the same
  person, related conversations, a style profile learned from everything you sent and
  the corrections you made to earlier drafts.
- **Categories and urgency**: your own categories (with descriptions the model
  reads); every conversation gets one, every unread message an urgency
  (high / medium / low).
- **Summaries** of a conversation or a single message, one line per fact.
- **Knowledge about you** (roles, projects, recurring questions and your usual answers,
  cards for the people you write to most), editable on the *AI instructions* page.
- Optional proofreading pass that fixes made-up words and wrong forms, useful for
  small models in languages other than English.

Nothing runs on its own: the local AI processes new mail only when you click
**Process new** in the top bar, and mail is only sent after you confirm it.

## 1. Google Cloud setup (for Gmail, once)

Skip this if you only use IMAP accounts.

1. Go to <https://console.cloud.google.com/> and create a project.
2. **APIs & Services → Library** → enable the **Gmail API**.
3. **APIs & Services → OAuth consent screen** → External, fill in an app name and your
   e-mail. Add the scopes `.../auth/gmail.modify`, `.../auth/userinfo.email`,
   `.../auth/userinfo.profile`, `openid`. (`gmail.modify` = read mail, mark read/unread,
   labels and send; it cannot permanently delete.) While the app is in *Testing* mode,
   add every Gmail address you want to connect under **Test users**.
4. **APIs & Services → Credentials → Create credentials → OAuth client ID**, type
   **Web application**, authorized redirect URI (exactly):

   ```
   http://localhost:8090/api/auth/google/callback
   ```

5. Copy the *Client ID* and *Client secret* into `.env` (next step).

## 2. Run it (Docker)

Requirements: Docker (Docker Desktop on macOS / Windows).

```bash
cp .env.example .env            # then edit: GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, UID/GID (id -u / id -g)
docker compose up -d --build
```

Then open <http://localhost:3000>.

| Service     | Purpose                                                   | Host address          |
| ----------- | --------------------------------------------------------- | --------------------- |
| `mysql`     | MySQL 8.4                                                 | 127.0.0.1:3307        |
| `api-init`  | composer install, APP_KEY, migrations                     | one-shot              |
| `api`       | PHP-FPM                                                   | –                     |
| `nginx`     | HTTP front for the API                                    | http://localhost:8090 |
| `queue`     | mail jobs: sync, send                                     | –                     |
| `queue-ai`  | local AI jobs: processing, re-sort, index                 | –                     |
| `scheduler` | fetches new mail every 5 min while the UI is open         | –                     |
| `web`       | Nuxt dev server                                           | http://localhost:3000 |

Everything is bound to `127.0.0.1` only. Health check: <http://localhost:8090/api/health>.
The first start installs Composer and npm dependencies inside the containers and takes
a couple of minutes. Your mail lives in the `mysql-data` volume; it survives restarts
and `docker compose down` (only `make reset` deletes it, after asking).

### Connecting Gmail

1. **Accounts → Connect Gmail** opens Google's consent screen in a new tab.
2. After consent Google redirects to the local API, which stores the encrypted tokens
   and queues the first sync (newest `MAIL_SYNC_INITIAL_LIMIT` messages, default 100).
   The tab closes itself and the accounts page picks up the new mailbox.
3. New mail is fetched when the UI opens and then every 5 minutes while it stays open.
   **Accounts → Fetch N older messages** back-fills further into the past.

### Connecting an IMAP/SMTP mailbox

**Accounts → Add IMAP account.** Pick a preset or enter your own servers. Most
providers require an *app password* instead of the login password. The API logs in to
both the IMAP and the SMTP server before anything is stored; credentials then live
encrypted (`APP_KEY`) and never appear in API responses.

Folders become labels (Inbox, Sent, Drafts, Trash, Junk are recognised by SPECIAL-USE
attributes or name), `\Seen`/`\Flagged` map to unread/starred, threads come from
`References`/`In-Reply-To`, new mail is pulled by UID above a per-folder cursor.
Sending goes through SMTP and the copy is appended to Sent. The backend never deletes,
moves or expunges anything.

## 3. Set up the local AI (optional)

1. Install [Ollama](https://ollama.com) and pull a chat model and an embedding model, e.g.

   ```bash
   ollama pull qwen3:8b      # chat: drafts, categories, summaries (16 GB RAM)
   ollama pull bge-m3        # embeddings: smart search, related conversations
   ```

   Bigger machines can use a bigger chat model. Prefer GGUF builds: the categories,
   urgency and summaries use Ollama's JSON-schema output.
2. Open **Configure AI** in Sovera Mail, keep the server URL
   (`http://host.docker.internal:11434`; `localhost` is rewritten to it because the API
   runs in Docker), press **Test connection**, pick the models and **Save**.
3. Click **Process new** in the top bar. One pass sorts new mail into categories,
   rates urgency, writes summaries, builds your writing style and what the AI knows
   about you, and indexes mail for smart search.

On Linux, Ollama must listen on an address Docker can reach, e.g. `OLLAMA_HOST=0.0.0.0`.
By default the model server has to be on this machine or your private network; set
`AI_ALLOW_REMOTE=true` only for a server you control (mail content is sent to it).

## Configuration

Root `.env` (used by docker compose):

| Variable                     | Default                  | Meaning                                              |
| ---------------------------- | ------------------------ | ---------------------------------------------------- |
| `GOOGLE_CLIENT_ID/SECRET`    | –                        | OAuth client (Web application)                       |
| `API_URL`                    | `http://localhost:8090`  | must match the redirect URI registered at Google     |
| `WEB_URL`                    | `http://localhost:3000`  | where the UI runs (CORS, OAuth return page)          |
| `MAIL_SYNC_INITIAL_LIMIT`    | `100`                    | how many newest messages the first sync pulls        |
| `MAIL_SYNC_INTERVAL_MINUTES` | `5`                      | interval of the scheduled fetch (only while the UI is open) |
| `MAIL_SYNC_BATCH_PAUSE_MS`   | `600`                    | pause between download batches (Gmail quota); rate-limit replies are retried with backoff |
| `AI_ALLOW_REMOTE`            | `false`                  | allow a model server outside this machine / private network |
| `SOVERA_TIMEZONE`            | `UTC`                    | your time zone, for "today" and deadlines in AI prompts |
| `API_KEY`                    | empty                    | optional shared secret (`X-Api-Key`) for the API     |
| `CHOKIDAR_USEPOLLING`        | `false`                  | set `true` if HMR misses file changes in Docker      |

## Development

```bash
make test        # Laravel test suite (in-memory SQLite)
make lint        # Pint
make restart     # restart api, queue workers and scheduler after backend changes
make logs        # follow all containers
make sync        # run a sync now and print stats
make shell       # bash inside the API container
make test-mail   # also run IMAP/SMTP integration tests against a throwaway GreenMail server
make remove-account EMAIL=you@gmail.com          # delete the local copy of a mailbox (Gmail untouched)
make remove-account EMAIL=you@gmail.com KEEP=1   # disconnect only; keep the mail on disk
```

The queue workers and the scheduler are long-running PHP processes: after changing
backend code run `make restart`, or they keep running the old code.

Useful artisan commands (inside the container):

```bash
php artisan sovera:sync-accounts --now                       # incremental, all accounts
php artisan sovera:sync-accounts --now --mode=backfill --limit=200
php artisan sovera:process                                   # same as "Process new"
```

`App\Services\Mail\Providers\MailProvider` is the one interface a mailbox backend
implements (`sync`, `send`, `saveDraft`, `fetch`, `setFlag`, `fetchAttachment`).
Everything above it (local store, idempotent sending, the no-delete guarantee,
attachment screening, AI) is shared, so another backend is one more class.

## Security & privacy model

Exactly this leaves your computer, and why:

| Destination                 | What                                             | When                                              |
| --------------------------- | ------------------------------------------------ | ------------------------------------------------- |
| `accounts.google.com`       | OAuth consent                                    | when you connect / reconnect a Gmail account      |
| `gmail.googleapis.com`      | mail download, read/star state, explicit sends   | while the UI is open, and sends only after confirmation |
| your IMAP / SMTP server     | mail download, read/flag state, explicit sends   | IMAP accounts only                                |
| `lh3.googleusercontent.com` | your own profile picture (no referrer)           | when the account avatar is shown                  |
| your Ollama server          | mail text for drafts, summaries, categories, index | only when you use an AI feature or click *Process new* |

No telemetry, no crash reporting, no update checks, no cloud sync.

Guarantees built into the code (covered by tests):

- **Nothing is ever sent automatically.** Sending is reachable only from the explicit
  Send actions; every send shows a confirmation, needs a one-use idempotency key and is
  rate-limited, so a network retry cannot send the same message twice. Sync, scheduler
  and AI never call the send path (a test scans the source for this).
- **Nothing is ever deleted.** The app has no delete or trash action and the API no
  `DELETE` for messages; the Gmail client refuses every label change except read/unread
  and starred. Mail deleted on the server is only hidden locally. **Remove account**
  asks whether to keep the local copy; the server mailbox is untouched either way.
- **The local API is fenced off from websites.** Every request needs an allow-listed
  `Host` (defeats DNS rebinding) and `Origin`, and every state-changing call the
  `X-Sovera-Client` header (forces a CORS preflight). Optional `API_KEY` on top.
- **AI stays on the device** unless you set `AI_ALLOW_REMOTE=true`. Retrieval is scoped
  to the account you write from; mailboxes are never mixed.
- **Tokens and passwords are encrypted at rest** (`APP_KEY` in `api/.env`: back it up,
  never commit it) and never appear in API responses.
- **Reading never changes the mailbox.** IMAP fetches with `BODY.PEEK[]` and issues no
  DELETE, EXPUNGE, COPY or MOVE.
- **Attachments are screened and never fetched on their own.** Sync stores metadata
  only; bytes are downloaded when you preview, download or forward. Executables,
  scripts, macro Office files, double extensions and the like are blocked; archives
  ask for confirmation; downloaded files are checked against their declared type.
- **HTML mail cannot run code**: sanitised, rendered in a sandboxed iframe with a strict
  CSP, remote images blocked until you load them.
- **Logs** contain account e-mail and counters, never message bodies or tokens.

Your responsibility: the `mysql-data` volume holds your mail in clear text, so use
full-disk encryption; change `DB_PASSWORD` from the default; keep `api/.env` private.

## How AI compose builds its prompt

Everything runs locally against the mailbox copy in MySQL and your Ollama server. The
knobs live on the **AI instructions** page.

For a **reply** the model receives:

1. **How you write**: a style profile from all your sent mail (greetings, sign-offs,
   length, phrases) plus the corrections you made to earlier AI drafts.
2. **What the AI knows about you** and a card about the person you write to.
3. **Style examples** (default 3): your latest mails to the same person from other
   threads, quoted history stripped.
4. **Similar conversations** (default 5): the mail you answer is compared against the
   vector index of the same account; one hit per thread, with the other half of that
   exchange attached.
5. **Conversation so far** (default 6 messages), then **the mail to answer**, anything
   you already typed and your one-off instructions.

The index embeds each message's own text (quoted history removed), cut at paragraph or
sentence ends into pieces of ~2000 characters, never attachment contents.

## License

[AGPL-3.0-or-later](LICENSE).
