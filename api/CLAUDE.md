# Sovera Mail API (Laravel 13)

Local-first mail backend. Runs only inside Docker (`docker compose` at repo root); the host PHP is not used.

- Run tests: `make test` (root) or `docker compose run --rm --no-deps --entrypoint sh api -c "php artisan test"`.
- Lint: `make lint` (Pint).
- Key code: `app/Services/Gmail/*` (OAuth, API client, MIME parser), `app/Services/Sync/GmailSyncService.php` (full / incremental / backfill), `app/Jobs/SyncAccountJob.php`.
- Config: `config/sovera.php`. Env comes from the root `.env` via docker-compose; `api/.env` only holds `APP_KEY`.
- Tests run on in-memory SQLite (`phpunit.xml` forces it via `<server>` vars so container env cannot leak in).
- Do not install Laravel Boost or other tooling on the host; everything goes through the containers.
