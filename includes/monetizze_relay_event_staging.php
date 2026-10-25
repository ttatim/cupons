<?php
declare(strict_types=1);

require_once __DIR__ . '/monetizze_signed_relay_review.php';
require_once __DIR__ . '/monetizze_relay_nonce_store.php';

/**
 * Durable, MINIMAL observations from an internally signed relay.
 * Relay HMAC is NOT proof of Monetizze origin. No financial action is allowed.
 * There is no public HTTP endpoint or automatic event ingestion.
 */
final class MonetizzeRelayEventStaging
{
    /**
     * Verify relay signature and reserve nonce and event IDs in ONE transaction.
     * Only HMAC hashes, numeric status/event and review flag are stored.
     * @return array<string,int|bool> Counts; no event identifiers or PII.
     */
    public static function verifyAndStage(
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
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $pdo->inTransaction()) {
            throw new RuntimeException('Staging Monetizze requer MySQL e transação exclusiva.');
        }
        $pdo->beginTransaction();
        try {
            $summary = MonetizzeSignedRelayReview::verifyAndPreview(
                $rawBody, $timestamp, $nonce, $signature, $relaySecret,
                $expectedAccountKey, MonetizzeRelayNonceStore::callback($pdo), $now
            );
            // The signed relay verifier already validated every event in this list.
            $events = json_decode($rawBody, true, 16, JSON_THROW_ON_ERROR);
            $accountHash = hash_hmac('sha256', 'monetizze:account:' . strtolower($expectedAccountKey), $hashKey);
            $insert = $pdo->prepare('INSERT INTO monetizze_event_observations
                (account_hash, event_hash, observed_status, observed_event, review_required)
                VALUES (:account_hash, :event_hash, :observed_status, :observed_event, :review_required)');
            $seen = [];
            $recorded = 0;
            $existing = 0;
            foreach ($events as $event) {
                if (!hash_equals(strtolower($expectedAccountKey), strtolower($event['chave_unica']))) {
                    continue;
                }
                $id = strtolower($event['id']);
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $status = (int) $event['codigo_status'];
                $code = (int) $event['postback_evento'];
                $risk = $code === 9 || in_array($status, [3, 4, 5], true);
                try {
                    $insert->execute([
                        ':account_hash' => $accountHash,
                        ':event_hash' => hash_hmac('sha256', 'monetizze:event:' . $id, $hashKey),
                        ':observed_status' => $status,
                        ':observed_event' => $code,
                        ':review_required' => $risk ? 1 : 0,
                    ]);
                    if ($insert->rowCount() !== 1) {
                        throw new RuntimeException('Persistência Monetizze indisponível.');
                    }
                    $recorded++;
                } catch (PDOException $exception) {
                    if (($exception->errorInfo[1] ?? null) !== 1062) {
                        throw new RuntimeException('Persistência Monetizze indisponível.', 0, $exception);
                    }
                    // Immutable first observation: do not overwrite earlier evidence.
                    $existing++;
                }
            }
            $pdo->commit();
            $summary['observations_recorded'] = $recorded;
            $summary['observations_existing'] = $existing;
            return $summary;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof InvalidArgumentException || $exception instanceof RuntimeException) {
                throw $exception;
            }
            throw new RuntimeException('Persistência Monetizze indisponível.', 0, $exception);
        }
    }
}
