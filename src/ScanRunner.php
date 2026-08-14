<?php
declare(strict_types=1);

namespace SportCard101;

use PDO;

/**
 * The scan and daily-playbook sequences, extracted so they can be driven from
 * more than one place: cron.php (host scheduler / external cron) and the
 * traffic fallback in bootstrap.php when no scheduler is firing at all.
 *
 * Neither method echoes — callers decide how to report.
 */
final class ScanRunner
{
    /** The superadmin whose channels the scanner runs. 0 when none exists. */
    public static function ownerId(PDO $pdo): int
    {
        try {
            return (int) ($pdo->query("SELECT id FROM users WHERE role = 'superadmin' ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * One full 30-minute pass: scan channels, record closed auctions, send deal
     * alerts, grade playbook picks, sweep lots, flag error cards. Updates the
     * heartbeat settings the Settings panel reads.
     *
     * @return array{deals:int,comps:int,alerts:int,graded:int,lots:array,lot_alerts:int,error_alerts:int,secs:float,mock:bool}
     */
    public static function run(PDO $pdo, array $config, ?int $uid = null): array
    {
        $uid = $uid ?? self::ownerId($pdo);
        if ($uid === 0) {
            throw new \RuntimeException('No superadmin user found.');
        }

        $ebay   = new EbayClient(\ebay_config($config['ebay']));
        $ai     = new AiAnalyst(\ai_config($config['ai']));
        $finder = new DealFinder($pdo, $ebay, (int)($config['deals']['scan_limit'] ?? 100), $ai);

        $started  = microtime(true);
        $newDeals = $finder->scanSelected($uid, null, null);
        $recorded = Comps::recordClosed($pdo);     // lock in auctions that just closed
        $alerts   = DealAlerts::run($pdo);          // email comp-beating auctions FIRST
        $graded   = Playbook::gradeClosed($pdo);   // then grade picks (never blocks alerts)

        // Bulk-lot sweep + BUY-lot alerts — best-effort, never breaks the scan.
        $lots = ['found' => 0, 'new' => 0, 'analyzed' => 0];
        $lotAlerts = 0;
        try {
            $lots = LotFinder::scan($pdo, $ebay, $ai);
            $lotAlerts = LotFinder::alert($pdo);
        } catch (\Throwable $e) {
        }

        // Possible error cards — no eBay calls, matches the catalog against
        // listings already captured.
        $errorAlerts = 0;
        try {
            $errorAlerts = ErrorCards::alert($pdo);
        } catch (\Throwable $e) {
        }

        $secs = round(microtime(true) - $started, 1);

        // Heartbeat — the Settings panel reads these.
        \set_setting('cron_last_run', date('Y-m-d H:i:s'));
        \set_setting('cron_last_status', sprintf(
            'OK — %d deals flagged, %d sold comps, %d alerts sent, %ss (%s)',
            count($newDeals), $recorded, count($alerts), $secs, $ebay->isMock() ? 'mock' : 'live'
        ));

        return [
            'deals' => count($newDeals), 'comps' => $recorded, 'alerts' => count($alerts),
            'graded' => $graded, 'lots' => $lots, 'lot_alerts' => $lotAlerts,
            'error_alerts' => $errorAlerts, 'secs' => $secs, 'mock' => $ebay->isMock(),
        ];
    }

    /**
     * The daily task: refresh comps, grade picks, build + email the Morning
     * Playbook, then send the Bulk Auctions digest as its own email.
     *
     * @return array{buys:int,watch:int,exposure:float,comps:int,graded:int,ai:string,sent:bool,to:string,lot_digest:int}
     */
    public static function daily(PDO $pdo, array $config): array
    {
        $ai = new AiAnalyst(\ai_config($config['ai']));

        // Freshly closed auctions and yesterday's grades first, so this
        // morning's comps and scorecard are current.
        $recorded = Comps::recordClosed($pdo);
        $graded   = Playbook::gradeClosed($pdo);
        $res      = Playbook::build($pdo, $ai);
        $plan     = Playbook::load($pdo, date('Y-m-d'));
        $score    = Playbook::scorecard($pdo);

        $sent = false;
        $to   = trim((string) \setting('notify_email', ''));
        if ($to !== '' && $plan) {
            $sells   = Playbook::sellActions($pdo);
            $subject = 'Morning Playbook — ' . date('D, M j') . ': '
                     . ($res['buys'] > 0 ? $res['buys'] . ' buy target' . ($res['buys'] === 1 ? '' : 's') : 'no qualified buys');
            $sent = Mailer::send($to, $subject,
                Playbook::emailText($plan, $sells, $score),
                Playbook::emailHtml($plan, $sells, $score));
        }

        // Bulk Auctions digest — its own email, so lots never crowd the playbook.
        $lotDigest = 0;
        try {
            $lotDigest = LotFinder::dailyDigest($pdo);
        } catch (\Throwable $e) {
        }

        return [
            'buys' => $res['buys'], 'watch' => $res['watch'], 'exposure' => $res['exposure'],
            'comps' => $recorded, 'graded' => $graded, 'ai' => $res['ai'],
            'sent' => $sent, 'to' => $to, 'lot_digest' => $lotDigest,
        ];
    }
}
