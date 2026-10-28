<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Extraction provider
    |--------------------------------------------------------------------------
    | demo      - local rule-based parser. It reads the PDF text layer with
    |             regular expressions. It is NOT an AI model and every record
    |             it produces is flagged as demo output in the UI and exports.
    | openai    - live extraction via the OpenAI Chat Completions API.
    | anthropic - live extraction via the Anthropic Messages API.
    */
    'provider' => env('DOCAI_PUBLIC_DEMO', false) ? 'demo' : env('DOCAI_PROVIDER', 'demo'),

    /*
    |--------------------------------------------------------------------------
    | Public demo
    |--------------------------------------------------------------------------
    | Switch for a hosted showcase. When on: password login is disabled in favour
    | of a one-click reviewer session, the demo parser is forced (no API key is
    | ever used), uploads are capped, and `docai:demo-reset` may wipe the data.
    */
    'public_demo' => (bool) env('DOCAI_PUBLIC_DEMO', false),
    'demo_login_email' => 'reviewer@ledgerly.test',

    'providers' => [
        'openai' => [
            'key' => env('DOCAI_OPENAI_API_KEY'),
            'model' => env('DOCAI_OPENAI_MODEL', 'gpt-4o-mini'),
            // Scans are read visually, where the small model misread digits in testing; a larger model is used for them.
            'scan_model' => env('DOCAI_OPENAI_SCAN_MODEL', 'gpt-4o'),
            'endpoint' => 'https://api.openai.com/v1/chat/completions',
        ],
        'anthropic' => [
            'key' => env('DOCAI_ANTHROPIC_API_KEY'),
            'model' => env('DOCAI_ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
            'endpoint' => 'https://api.anthropic.com/v1/messages',
            'version' => '2023-06-01',
        ],
    ],

    'timeout' => (int) env('DOCAI_TIMEOUT_SECONDS', 60),

    // Live providers are attempted up to 3 times (1 try + 2 retries), only for rate limits, 5xx responses and connection errors.
    'retry_delay_ms' => 800,

    // Invoices by email: a mail-to-webhook service posts to /api/inbound/email. Disabled while the token is empty.
    'inbound' => [
        'token' => env('DOCAI_INBOUND_TOKEN'),
        'allowed_domains' => env('DOCAI_INBOUND_ALLOWED_DOMAINS', ''),   // comma separated sender domains; empty = any sender
        'user_email' => env('DOCAI_INBOUND_USER', 'inbox@ledgerly.test'),  // owner of emailed invoices; falls back to the first admin
    ],

    // Upload limits
    'max_upload_kb' => env('DOCAI_PUBLIC_DEMO', false) ? 2048 : (int) env('DOCAI_MAX_UPLOAD_KB', 10240),
    'max_files_per_upload' => env('DOCAI_PUBLIC_DEMO', false) ? 3 : 10,

    // Scanned PDFs are sent to a live provider as a file; larger files are refused.
    'max_scan_bytes' => 10 * 1024 * 1024,

    // Only the first N characters of PDF text are sent to a live provider.
    'max_text_chars' => 24000,

    // Validation
    'amount_tolerance' => 0.01,       // allowed rounding difference in totals
    'low_confidence' => 0.70,         // below this a field is flagged for attention
    'currencies' => [
        'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'JPY', 'CNY', 'INR', 'PKR',
        'AED', 'SAR', 'SEK', 'NOK', 'DKK', 'NZD', 'SGD', 'HKD', 'ZAR', 'MXN',
        'BRL', 'PLN', 'TRY',
    ],
];
