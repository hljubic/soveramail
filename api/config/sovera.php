<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Web UI
    |--------------------------------------------------------------------------
    */
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    /*
    |--------------------------------------------------------------------------
    | Optional shared secret between the web UI and this local API.
    | Empty = disabled.
    |--------------------------------------------------------------------------
    */
    'api_key' => env('API_KEY', ''),

    // Additional Host header values accepted by the local API (comma separated).
    'extra_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('API_EXTRA_HOSTS', ''))))),

    /*
    |--------------------------------------------------------------------------
    | Local AI
    |--------------------------------------------------------------------------
    | By default the model server must live on this machine / LAN. Set
    | AI_ALLOW_REMOTE=true only if you knowingly want mail content to leave
    | the device (e.g. your own inference box behind a VPN).
    */
    'ai' => [
        'allow_remote' => (bool) env('AI_ALLOW_REMOTE', false),

        // Models stay in RAM until the user clicks "Unload" (keep_alive -1, see OllamaClient).

        // The user's local time zone: "today" and mail dates in prompts (urgency, deadlines).
        // The app itself stores UTC.
        'timezone' => env('SOVERA_TIMEZONE', 'UTC'),

        // "Process new mail" looks at conversations active in the last N days (older
        // mail: "Re-sort" on the Categories page, "Summarize" on click).
        'process_days' => (int) env('AI_PROCESS_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google / Gmail
    |--------------------------------------------------------------------------
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI', rtrim(env('APP_URL', 'http://localhost:8090'), '/').'/api/auth/google/callback'),
        // gmail.modify = read + mark read/unread + labels + send (no permanent delete).
        'scopes' => [
            'openid',
            'https://www.googleapis.com/auth/userinfo.email',
            'https://www.googleapis.com/auth/userinfo.profile',
            'https://www.googleapis.com/auth/gmail.modify',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync behaviour
    |--------------------------------------------------------------------------
    */
    'sync' => [
        // How many newest messages the first sync pulls.
        'initial_limit' => (int) env('MAIL_SYNC_INITIAL_LIMIT', 100),
        // Hard cap for any single sync/backfill request.
        'max_limit' => (int) env('MAIL_SYNC_MAX_LIMIT', 2000),
        // Gmail batch size for messages.get (Google recommends <= 50).
        'batch_size' => (int) env('MAIL_SYNC_BATCH_SIZE', 25),
        // Pause between batches (ms): 25 messages x 5 units every 0.6 s stays under Gmail's 15 000 units/min/user.
        'batch_pause_ms' => (int) env('MAIL_SYNC_BATCH_PAUSE_MS', 600),
        // Scheduler: incremental sync of all accounts every N minutes.
        'interval_minutes' => (int) env('MAIL_SYNC_INTERVAL_MINUTES', 5),
    ],
];
