<?php
/**
 * vipsvault configuration sample.
 *
 * Copy this file to config.php and fill in your values:
 *     cp config.sample.php config.php
 *
 * config.php is git-ignored so your secrets never get committed.
 */

return [
    // --- Database (MySQL / MariaDB) ---
    'db' => [
        // On shared hosting (e.g. Hostinger) host is usually 'localhost'.
        'host'     => getenv('DB_HOST') ?: 'localhost',
        'port'     => (int)(getenv('DB_PORT') ?: 3306),
        'name'     => getenv('DB_NAME') ?: 'u312278121_adminvip',
        'user'     => getenv('DB_USER') ?: 'u312278121_adminvip',
        'password' => getenv('DB_PASS') ?: '',
        'charset'  => 'utf8mb4',
    ],

    // --- App / session ---
    'app' => [
        // Used to sign/secure the session. Change this to a long random string.
        'secret'   => getenv('APP_SECRET') ?: 'change-me-to-a-long-random-string',
        'base_url' => getenv('APP_BASE_URL') ?: '',
        // Timezone used for displaying auction end times.
        'timezone' => getenv('APP_TZ') ?: 'America/New_York',
    ],

    // --- eBay Browse API credentials ---
    // Create an app at https://developer.ebay.com/ to get these.
    // Leave blank to run in MOCK mode (sample data, no network calls).
    'ebay' => [
        'client_id'     => getenv('EBAY_CLIENT_ID') ?: '',
        'client_secret' => getenv('EBAY_CLIENT_SECRET') ?: '',
        'marketplace'   => getenv('EBAY_MARKETPLACE') ?: 'EBAY_US',
        // 'production' or 'sandbox'
        'environment'   => getenv('EBAY_ENV') ?: 'production',
        // Optional eBay Partner Network campaign id for affiliate links.
        'campaign_id'   => getenv('EBAY_CAMPAIGN_ID') ?: '',
    ],

    // --- AI (Anthropic Claude) — powers the buy/sell Opportunity Engine ---
    // Get a key at https://console.anthropic.com/. Leave blank to run the AI
    // in MOCK mode (heuristic scoring, no API calls).
    'ai' => [
        'api_key' => getenv('ANTHROPIC_API_KEY') ?: '',
        'model'   => getenv('ANTHROPIC_MODEL') ?: 'claude-opus-4-8',
        // Max deal listings sent to the AI per search scan (cost control).
        'max_per_scan' => (int)(getenv('AI_MAX_PER_SCAN') ?: 15),
    ],

    // --- Email notifications (optional) ---
    // Uses PHP's mail() by default. For Gmail/SMTP, configure your server's
    // sendmail or set up an SMTP relay. Leave 'to' blank to disable email.
    'mail' => [
        'enabled' => (bool)(getenv('MAIL_ENABLED') ?: false),
        'to'      => getenv('MAIL_TO') ?: '',
        'from'    => getenv('MAIL_FROM') ?: 'vipsvault@localhost',
    ],

    // --- Default deal rules ---
    'deals' => [
        // Flag a listing as a deal when its price is at least this % below
        // the computed market baseline for that search.
        'default_threshold_pct' => (int)(getenv('DEAL_THRESHOLD') ?: 25),
        // How many listings to pull per search scan.
        'scan_limit'            => (int)(getenv('SCAN_LIMIT') ?: 100),
    ],
];
