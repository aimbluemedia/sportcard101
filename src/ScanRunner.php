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
    /** How often the (expensive) bulk-lot eBay sweep runs, in seconds. */
    public const LOT_SWEEP_INTERVAL = 7200; // 2 hours

    /**
     * Seconds of channel scanning per run before the rest is deferred to the
     * next one. Kept well under the ~60s gateway timeout typical of shared
     * hosts, so a run always finishes before anything gives up on it.
     */
    public const CHANNEL_TIME_BUDGET = 25;

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
        // Scans outgrow the default 30s web limit as channels are added, and a
        // timeout kill is an uncatchable fatal. Raise it for every caller.
        @set_time_limit(600);

        $uid = $uid ?? self::ownerId($pdo);
        if ($uid === 0) {
            throw new \RuntimeException('No superadmin user found.');
        }

        $ebay   = new EbayClient(\ebay_config($config['ebay']));
        $ai     = new AiAnalyst(\ai_config($config['ai']));
        $finder = new DealFinder($pdo, $ebay, (int)($config['deals']['scan_limit'] ?? 100), $ai);

        $started = microtime(true);
        $t = fn () => round(microtime(true) - $started, 1) . 's';
        Diag::log('SCAN START (uid ' . $uid . ', ebay ' . ($ebay->isMock() ? 'mock' : 'live')
            . ', ai ' . ($ai->isMock() ? 'mock' : 'live') . ', limit ' . (string)@ini_get('max_execution_time') . 's)');

        // Channels are scanned in slices, resuming where the last run stopped.
        // Scanning every channel in one request grew past 60s, which trips
        // gateway timeouts (504) regardless of PHP's own limit. Each run now
        // works to a time budget and hands the rest to the next run — with a
        // scan every 30 minutes, every channel still gets covered many times
        // a day.
        $newDeals  = [];
        $scanned   = 0;
        $totalChan = 0;
        try {
            $stmt = $pdo->prepare('SELECT * FROM searches WHERE user_id = ? AND active = 1 ORDER BY id ASC');
            $stmt->execute([$uid]);
            $channels  = $stmt->fetchAll();
            $totalChan = count($channels);

            if ($totalChan > 0) {
                $cursor = ((int) (\setting('scan_cursor', '0') ?: 0)) % $totalChan;
                for ($i = 0; $i < $totalChan; $i++) {
                    // Always do at least one channel, then respect the budget.
                    if ($i > 0 && (microtime(true) - $started) > self::CHANNEL_TIME_BUDGET) {
                        break;
                    }
                    $search = $channels[($cursor + $i) % $totalChan];
                    foreach ($finder->scanSearch($search) as $deal) {
                        $deal['search_label'] = $search['label'];
                        $newDeals[] = $deal;
                    }
                    $scanned++;
                    $pdo = Database::alive($pdo); // each channel does eBay + AI work
                }
                \set_setting('scan_cursor', (string) (($cursor + $scanned) % $totalChan));
            }
        } catch (\Throwable $e) {
            Diag::log('  channel scan FAILED: ' . $e->getMessage());
            throw $e;
        }
        Diag::log("  channels scanned {$scanned}/{$totalChan} — " . count($newDeals) . ' deals @ ' . $t());
        // Long eBay/AI calls above can outlive MySQL's idle timeout.
        $pdo = Database::alive($pdo);
        $recorded = Comps::recordClosed($pdo);     // lock in auctions that just closed
        Diag::log('  comps recorded: ' . $recorded . ' @ ' . $t());
        $alerts   = DealAlerts::run($pdo);          // email comp-beating auctions FIRST
        Diag::log('  deal alerts sent: ' . count($alerts) . ' @ ' . $t());
        $graded   = Playbook::gradeClosed($pdo);   // then grade picks (never blocks alerts)
        Diag::log('  picks graded: ' . $graded . ' @ ' . $t());

        $coreSecs = round(microtime(true) - $started, 1);
        \set_setting('cron_last_run', date('Y-m-d H:i:s'));
        \set_setting('cron_last_status', sprintf(
            'OK — %d deals flagged, %d sold comps, %d alerts sent, %ss (%s)',
            count($newDeals), $recorded, count($alerts), $coreSecs, $ebay->isMock() ? 'mock' : 'live'
        ));

        // Bulk-lot sweep — the most expensive extra (4 eBay searches + an AI
        // pass), so it runs on its own slower cadence rather than every scan.
        // The alert step is DB-only and stays on every pass.
        $lots = ['found' => 0, 'new' => 0, 'analyzed' => 0];
        $lotAlerts = 0;
        try {
            $lastSweep = (int) (\setting('lots_last_sweep', '0') ?: 0);
            $elapsed   = microtime(true) - $started;
            if (time() - $lastSweep < self::LOT_SWEEP_INTERVAL) {
                Diag::log('  lot sweep skipped (2h cadence) @ ' . $t());
            } elseif ($elapsed > self::CHANNEL_TIME_BUDGET) {
                // Out of budget — leave lots_last_sweep alone so the next run
                // picks it up rather than skipping the sweep for another 2h.
                Diag::log('  lot sweep deferred (run already at ' . $t() . ')');
            } else {
                \set_setting('lots_last_sweep', (string) time());
                Diag::log('  lot sweep starting… @ ' . $t());
                $lots = LotFinder::scan($pdo, $ebay, $ai);
                $pdo  = Database::alive($pdo); // sweep makes eBay + AI calls
                Diag::log('  lot sweep done: ' . $lots['found'] . ' found @ ' . $t());
            }
            $lotAlerts = LotFinder::alert($pdo);
        } catch (\Throwable $e) {
            Diag::log('  lots FAILED: ' . $e->getMessage());
        }

        // Possible error cards — no eBay calls, matches the catalog against
        // listings already captured.
        $errorAlerts = 0;
        try {
            $errorAlerts = ErrorCards::alert($pdo);
            Diag::log('  error-card alerts: ' . $errorAlerts . ' @ ' . $t());
        } catch (\Throwable $e) {
            Diag::log('  error cards FAILED: ' . $e->getMessage());
        }

        $secs = round(microtime(true) - $started, 1);
        Diag::log('SCAN COMPLETE in ' . $secs . 's');

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
        @set_time_limit(600);
        $ai = new AiAnalyst(\ai_config($config['ai']));

        // Freshly closed auctions and yesterday's grades first, so this
        // morning's comps and scorecard are current.
        $recorded = Comps::recordClosed($pdo);
        $graded   = Playbook::gradeClosed($pdo);
        $res      = Playbook::build($pdo, $ai);   // includes an AI narrative call
        $pdo      = Database::alive($pdo);
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
