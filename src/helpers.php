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

/**
 * Wrap an eBay URL with eBay Partner Network affiliate tracking, using the
 * Campaign ID / Custom ID saved in admin Settings. Works for any eBay item or
 * search URL — no API call required (this is EPN's standard link format).
 * Returns the URL unchanged if no Campaign ID is set or it's already tracked.
 */
function epn_link(string $url): string
{
    $url = trim($url);
    if ($url === '' || !str_contains($url, 'ebay.')) {
        return $url;
    }
    if (str_contains($url, 'campid=')) {
        return $url; // already an affiliate link
    }
    $campid = (string) setting('ebay_campaign_id', '');
    if ($campid === '') {
        return $url; // tracking not configured yet
    }

    // siteid + rotation id (mkrid) pair, by marketplace. Override via setting.
    $mp = (string) setting('ebay_marketplace', 'EBAY_US');
    [$siteid, $mkrid] = match ($mp) {
        'EBAY_GB' => ['3', '710-53481-19255-0'],
        'EBAY_CA' => ['2', '706-53473-19255-0'],
        'EBAY_AU' => ['15', '705-53470-19255-0'],
        default   => ['0', '711-53200-19255-0'], // EBAY_US
    };
    $rot = (string) setting('ebay_rotation_id', '');
    if ($rot !== '') {
        $mkrid = $rot;
    }

    $params = [
        'mkcid'  => '1',          // eBay Partner Network
        'mkrid'  => $mkrid,
        'siteid' => $siteid,
        'campid' => $campid,
        'toolid' => '10001',
        'mkevt'  => '1',
    ];
    $custom = (string) setting('ebay_custom_id', '');
    if ($custom !== '') {
        $params['customid'] = $custom;
    }

    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
}

/** Build a tracked eBay search URL for a keyword phrase. */
function epn_search_link(string $keywords): string
{
    $base = 'https://www.ebay.com/sch/i.html?' . http_build_query(['_nkw' => $keywords]);
    return epn_link($base);
}

/** Sports we organise auction channels by: key => [label, emoji]. */
function card_sports(): array
{
    return [
        'baseball'   => ['label' => 'Baseball',   'emoji' => '⚾'],
        'basketball' => ['label' => 'Basketball', 'emoji' => '🏀'],
        'football'   => ['label' => 'Football',   'emoji' => '🏈'],
        'hockey'     => ['label' => 'Hockey',     'emoji' => '🏒'],
        'golf'       => ['label' => 'Golf',       'emoji' => '⛳'],
    ];
}

/** Grading companies: code (used in the eBay query) => display label. */
function card_companies(): array
{
    return [
        'PSA' => 'PSA',
        'BGS' => 'Beckett (BGS)',
        'SGC' => 'SGC',
        'CGC' => 'CGC',
    ];
}

/** Grade numbers we let users pick. Half grades apply to BGS/SGC/CGC. */
function card_grade_nums(): array
{
    return ['10', '9.5', '9', '8.5', '8', '7'];
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
