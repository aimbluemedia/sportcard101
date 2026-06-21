<?php
declare(strict_types=1);

/**
 * Small view/helper functions shared across the public site, member area,
 * and superadmin area.
 */

/** HTML-escape a value for safe output. */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Generate (once per session) and return the CSRF token. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        exit('Invalid or expired form token. Go back and try again.');
    }
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

/** Format dollars from a float. */
function money(?float $amount, string $currency = 'USD'): string
{
    if ($amount === null) {
        return '—';
    }
    $symbol = ['USD' => '$', 'GBP' => '£', 'EUR' => '€', 'CAD' => 'C$', 'AUD' => 'A$'][$currency] ?? '';
    return $symbol . number_format($amount, 2);
}

/** Format dollars from an integer number of cents. */
function money_cents(int $cents): string
{
    return '$' . number_format($cents / 100, $cents % 100 === 0 ? 0 : 2);
}

/** URL-safe slug. */
function slugify(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-') ?: 'item';
}

/** Read a site setting (key/value table), with a per-request cache. */
function setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    global $pdo;
    if ($cache === null) {
        $cache = [];
        try {
            foreach ($pdo->query('SELECT skey, sval FROM settings')->fetchAll() as $r) {
                $cache[$r['skey']] = $r['sval'];
            }
        } catch (\Throwable $e) {
            // settings table may not exist yet during install
        }
    }
    return $cache[$key] ?? $default;
}

/**
 * Build the eBay client config, preferring values saved in the admin Settings
 * (DB) and falling back to config.php. Lets the superadmin manage keys in the UI.
 */
function ebay_config(array $fileCfg): array
{
    $endpoint = setting('ebay_endpoint', 'https://api.ebay.com') ?: 'https://api.ebay.com';
    return [
        'client_id'     => setting('ebay_app_id', (string)($fileCfg['client_id'] ?? '')),
        'client_secret' => setting('ebay_cert_id', (string)($fileCfg['client_secret'] ?? '')),
        'account_sid'   => setting('ebay_account_sid', ''),
        'auth_token'    => setting('ebay_auth_token', ''),
        'marketplace'   => setting('ebay_marketplace', (string)($fileCfg['marketplace'] ?? 'EBAY_US')),
        'campaign_id'   => setting('ebay_campaign_id', (string)($fileCfg['campaign_id'] ?? '')),
        'custom_id'     => setting('ebay_custom_id', ''),
        'endpoint'      => $endpoint,
        'environment'   => str_contains($endpoint, 'sandbox') ? 'sandbox' : 'production',
        'cache_hours'   => (int)(setting('ebay_cache_hours', '12') ?? 12),
    ];
}

/** Human-friendly "time left" for an auction end time (UTC string). */
function time_left(?string $endTimeUtc): string
{
    if (!$endTimeUtc) {
        return '—';
    }
    try {
        $end = new DateTime($endTimeUtc, new DateTimeZone('UTC'));
    } catch (\Exception $e) {
        return '—';
    }
    $secs = $end->getTimestamp() - (new DateTime('now', new DateTimeZone('UTC')))->getTimestamp();
    if ($secs <= 0) {
        return 'ended';
    }
    $d = intdiv($secs, 86400);
    $h = intdiv($secs % 86400, 3600);
    $m = intdiv($secs % 3600, 60);
    if ($d > 0) return "{$d}d {$h}h";
    if ($h > 0) return "{$h}h {$m}m";
    return "{$m}m";
}
