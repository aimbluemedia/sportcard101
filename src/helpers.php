<?php
declare(strict_types=1);

/**
 * Small view/helper functions used across the app.
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

/** Render a hidden CSRF input for forms. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Verify a submitted CSRF token; aborts on mismatch. */
function csrf_verify(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        exit('Invalid or expired form token. Go back and try again.');
    }
}

/** Redirect helper. */
function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

/** Format money for display. */
function money(?float $amount, string $currency = 'USD'): string
{
    if ($amount === null) {
        return '—';
    }
    $symbol = ['USD' => '$', 'GBP' => '£', 'EUR' => '€', 'CAD' => 'C$', 'AUD' => 'A$'][$currency] ?? '';
    return $symbol . number_format($amount, 2);
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
    $now  = new DateTime('now', new DateTimeZone('UTC'));
    $secs = $end->getTimestamp() - $now->getTimestamp();
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
