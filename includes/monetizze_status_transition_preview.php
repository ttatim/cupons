<?php
declare(strict_types=1);

require_once __DIR__ . '/monetizze_webhook_review.php';

/**
 * Read-only triage of possible changes to Monetizze delivery observations.
 *
 * Inputs are UNTRUSTED candidate events. This is not upstream authentication,
 * a chronological status history, or financial reconciliation. No writes occur.
 */
final class MonetizzeStatusTransitionPreview
{
    /**
     * @param list<array<string,mixed>> $events
     * @return array<string,int|bool> Aggregate counters only; no identifiers/PII.
     */
    public static function compare(
        PDO $pdo,
        array $events,
        string $expectedAccountKey,
        string $hashKey
    ): array {
        if (strlen($hashKey) < 32 || strlen($hashKey) > 1024
            || preg_match('/[\x00-\x1f\x7f]/', $hashKey)) {
            throw new InvalidArgumentException('Chave de hash Monetizze inválida.');
        }
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('Prévia de transições Monetizze requer MySQL.');
        }
        // Validate the WHOLE batch before reading any stored observation.
        MonetizzeWebhookReview::preview($events, $expectedAccountKey);

        $accountHash = hash_hmac(
            'sha256', 'monetizze:account:' . strtolower($expectedAccountKey), $hashKey
        );
        $query = $pdo->prepare(
            'SELECT observed_status, observed_event FROM monetizze_event_observations
             WHERE account_hash = :account_hash AND event_hash = :event_hash'
        );
        $summary = [
            'new' => 0,
            'unchanged' => 0,
            'conflicting' => 0,
            'new_risk' => 0,
            'risk_escalations' => 0,
            'risk_reductions_unverified' => 0,
            'other_account' => 0,
            'duplicates' => 0,
            'authenticated' => false,
            'financial_ready' => false,
        ];
        $seen = [];
        foreach ($events as $event) {
            if (!hash_equals(strtolower($expectedAccountKey), strtolower($event['chave_unica']))) {
                $summary['other_account']++;
                continue;
            }
            $id = strtolower($event['id']);
            if (isset($seen[$id])) {
                $summary['duplicates']++;
                continue;
            }
            $seen[$id] = true;
            $status = (int) $event['codigo_status'];
            $eventCode = (int) $event['postback_evento'];
            $currentRisk = $eventCode === 9 || in_array($status, [3, 4, 5], true);
            $query->execute([
                ':account_hash' => $accountHash,
                ':event_hash' => hash_hmac('sha256', 'monetizze:event:' . $id, $hashKey),
            ]);
            $previous = $query->fetch(PDO::FETCH_ASSOC);
            $query->closeCursor();
            if ($previous === false) {
                $summary['new']++;
                if ($currentRisk) {
                    $summary['new_risk']++;
                }
                continue;
            }
            $oldStatus = (int) $previous['observed_status'];
            $oldEvent = (int) $previous['observed_event'];
            if ($oldStatus === $status && $oldEvent === $eventCode) {
                $summary['unchanged']++;
                continue;
            }
            $summary['conflicting']++;
            $oldRisk = $oldEvent === 9 || in_array($oldStatus, [3, 4, 5], true);
            if (!$oldRisk && $currentRisk) {
                $summary['risk_escalations']++;
            } elseif ($oldRisk && !$currentRisk) {
                // A positive-looking event NEVER cancels an earlier risk.
                $summary['risk_reductions_unverified']++;
            }
        }
        return $summary;
    }
}
