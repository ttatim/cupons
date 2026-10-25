<?php
declare(strict_types=1);

require_once __DIR__ . '/monetizze_signed_relay_review.php';
require_once __DIR__ . '/monetizze_relay_nonce_store.php';

/**
 * Append-only LOCAL first-seen journal for candidate status divergences.
 *
 * The HMAC proves only an INTERNAL relay, not Monetizze's upstream origin.
 * Entries are unverified evidence for future human review, never approval,
 * payment, revenue recognition or a chronological upstream event history.
 * No public HTTP route or automatic ingestion is provided.
 */
final class MonetizzeReviewCandidateJournal
{
    /**
     * Reserve a relay nonce and record only conflicting candidates atomically.
     * The first observation remains immutable. Identical candidates are idempotent.
     *
     * @return array<string,int|bool> Aggregate counters only, without PII or IDs.
     */
    public static function verifyAndRecord(
        PDO $pdo,
        string $rawBody,
        string $timestamp,
        string $nonce,
        string $signature,
        string $relaySecret,
        string $expectedAccountKey,
        string $hashKey,
        ?int $now = null
    ): array {
        if (strlen($hashKey) < 32 || strlen($hashKey) > 1024
            || preg_match('/[\x00-\x1f\x7f]/', $hashKey)) {
            throw new InvalidArgumentException('Chave de hash Monetizze inválida.');
        }
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION
            || $pdo->inTransaction()) {
            throw new RuntimeException('Diário Monetizze requer MySQL e transação exclusiva.');
        }

        $pdo->beginTransaction();
        try {
            // The verifier validates the entire signed batch before claiming
            // the nonce, which is persisted inside this same transaction.
            $summary = MonetizzeSignedRelayReview::verifyAndPreview(
                $rawBody, $timestamp, $nonce, $signature, $relaySecret,
                $expectedAccountKey, MonetizzeRelayNonceStore::callback($pdo), $now
            );
            $events = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
            $accountHash = hash_hmac(
                'sha256', 'monetizze:account:' . strtolower($expectedAccountKey), $hashKey
            );
            $lookup = $pdo->prepare('SELECT observed_status, observed_event
                FROM monetizze_event_observations
                WHERE account_hash = :account_hash AND event_hash = :event_hash');
            $insert = $pdo->prepare('INSERT INTO monetizze_review_candidates
                (account_hash, event_hash, candidate_status, candidate_event,
                 original_status, original_event, risk_direction)
                VALUES (:account_hash, :event_hash, :candidate_status, :candidate_event,
                        :original_status, :original_event, :risk_direction)');
            $counts = [
                'candidates_recorded' => 0, 'candidates_existing' => 0,
                'unchanged' => 0, 'untracked' => 0,
                'risk_escalations' => 0, 'risk_reductions_unverified' => 0,
                'other_changes' => 0,
            ];
            $seen = [];
            foreach ($events as $event) {
                if (!hash_equals(strtolower($expectedAccountKey), strtolower($event['chave_unica']))) {
                    continue;
                }
                $id = strtolower($event['id']);
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $eventHash = hash_hmac('sha256', 'monetizze:event:' . $id, $hashKey);
                $lookup->execute([':account_hash' => $accountHash, ':event_hash' => $eventHash]);
                $original = $lookup->fetch(PDO::FETCH_ASSOC);
                $lookup->closeCursor();
                if ($original === false) {
                    // No trusted baseline exists: do not invent one here.
                    $counts['untracked']++;
                    continue;
                }
                $status = (int) $event['codigo_status'];
                $eventCode = (int) $event['postback_evento'];
                $oldStatus = (int) $original['observed_status'];
                $oldEvent = (int) $original['observed_event'];
                if ($status === $oldStatus && $eventCode === $oldEvent) {
                    $counts['unchanged']++;
                    continue;
                }
                $oldRisk = $oldEvent === 9 || in_array($oldStatus, [3, 4, 5], true);
                $newRisk = $eventCode === 9 || in_array($status, [3, 4, 5], true);
                $direction = !$oldRisk && $newRisk ? 'escalation'
                    : ($oldRisk && !$newRisk ? 'reduction_unverified' : 'changed');
                if ($direction === 'escalation') {
                    $counts['risk_escalations']++;
                } elseif ($direction === 'reduction_unverified') {
                    $counts['risk_reductions_unverified']++;
                } else {
                    $counts['other_changes']++;
                }
                try {
                    $insert->execute([
                        ':account_hash' => $accountHash,
                        ':event_hash' => $eventHash,
                        ':candidate_status' => $status,
                        ':candidate_event' => $eventCode,
                        ':original_status' => $oldStatus,
                        ':original_event' => $oldEvent,
                        ':risk_direction' => $direction,
                    ]);
                    if ($insert->rowCount() !== 1) {
                        throw new RuntimeException('Diário Monetizze indisponível.');
                    }
                    $counts['candidates_recorded']++;
                } catch (PDOException $exception) {
                    if (($exception->errorInfo[1] ?? null) !== 1062) {
                        throw new RuntimeException('Diário Monetizze indisponível.', 0, $exception);
                    }
                    // Unique (account,event,status,code) makes a repeated candidate
                    // idempotent across workers, without overwriting the first entry.
                    $counts['candidates_existing']++;
                }
            }
            $pdo->commit();
            return array_merge($summary, $counts);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof InvalidArgumentException || $exception instanceof RuntimeException) {
                throw $exception;
            }
            throw new RuntimeException('Diário Monetizze indisponível.', 0, $exception);
        }
    }
}
