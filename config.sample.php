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
        'host'     => getenv('DB_HOST') ?: '127.0.0.1',
        'port'     => (int)(getenv('DB_PORT') ?: 3306),
        'name'     => getenv('DB_NAME') ?: 'vipsvault',
        'user'     => getenv('DB_USER') ?: 'root',
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
