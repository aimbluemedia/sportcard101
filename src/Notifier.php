<?php
declare(strict_types=1);

namespace Vipsvault;

use PDO;

/**
 * Sends notifications for newly found deals and marks listings as notified.
 * Email uses PHP's mail(); if disabled, deals still surface in the in-app feed.
 */
final class Notifier
{
    public function __construct(private PDO $pdo, private array $mailCfg) {}

    /**
     * @param array<int,array<string,mixed>> $deals New deals from DealFinder.
     */
    public function notify(array $deals): void
    {
        if (!$deals) {
            return;
        }

        if (!empty($this->mailCfg['enabled']) && !empty($this->mailCfg['to'])) {
            $this->emailDigest($deals);
        }

        // Mark these listings as notified so we don't alert twice.
        $stmt = $this->pdo->prepare(
            'UPDATE listings SET notified = 1 WHERE ebay_item_id = ?'
        );
        foreach ($deals as $d) {
            $stmt->execute([$d['ebay_item_id']]);
        }
    }

    private function emailDigest(array $deals): void
    {
        $count   = count($deals);
        $subject = "vipsvault: {$count} new PSA 10 deal" . ($count === 1 ? '' : 's');

        $lines = ["You have {$count} new deal" . ($count === 1 ? '' : 's') . " to consider:\n"];
        foreach ($deals as $d) {
            $label    = $d['search_label'] ?? '';
            $discount = isset($d['discount_pct']) ? rtrim(rtrim((string)$d['discount_pct'], '0'), '.') : '?';
            $price    = number_format((float)$d['price'], 2);
            $baseline = number_format((float)($d['baseline_price'] ?? 0), 2);
            $lines[]  = "• [{$label}] {$d['title']}";
            $lines[]  = "  \${$price} ({$discount}% below ~\${$baseline} market) — {$d['item_url']}";
            $lines[]  = "";
        }
        $body = implode("\n", $lines);

        $headers = 'From: ' . ($this->mailCfg['from'] ?? 'vipsvault@localhost') . "\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n";

        @mail($this->mailCfg['to'], $subject, $body, $headers);
    }
}
