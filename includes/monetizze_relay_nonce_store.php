<?php
declare(strict_types=1);

/**
 * Durable anti-replay claim for INTERNAL Monetizze relay envelopes.
 *
 * This only protects a relay HMAC envelope from being replayed. It does NOT
 * authenticate the Monetizze origin or authorize any financial operation.
 * All workers must share the same MySQL database and the migration must exist.
 */
final class MonetizzeRelayNonceStore
{
    /**
     * Claim a nonce once, atomically, across workers and process restarts.
     *
     * Nonces are deliberately never deleted automatically: retention and
     * cleanup require a separately approved policy. No payload, account,
     * credentials, event IDs or personal information is stored.
     */
    public static function claim(PDO $pdo, string $nonce, int $expiresAt): bool
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D', $nonce) || $expiresAt < 1) {
            throw new InvalidArgumentException('Reserva de nonce inválida.');
        }
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('Proteção contra repetição requer MySQL.');
        }

        try {
            // PRIMARY KEY(nonce) is the atomic cross-process replay barrier.
            // Never use SELECT-before-INSERT, INSERT IGNORE or REPLACE here.
            $statement = $pdo->prepare(
                'INSERT INTO monetizze_relay_nonces (nonce, expires_at) VALUES (:nonce, :expires_at)'
            );
            if (!$statement || !$statement->execute(['nonce' => $nonce, 'expires_at' => $expiresAt])) {
                throw new RuntimeException('Proteção contra repetição indisponível.');
            }
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Proteção contra repetição indisponível.');
            }
            return true;
        } catch (PDOException $exception) {
            // Duplicate primary key is the only accepted non-error outcome.
            // All other SQL errors fail closed, without leaking DB diagnostics.
            if (($exception->errorInfo[1] ?? null) === 1062) {
                return false;
            }
            throw new RuntimeException('Proteção contra repetição indisponível.', 0, $exception);
        }
    }

    /** @return Closure(string,int):bool */
    public static function callback(PDO $pdo): Closure
    {
        return static fn(string $nonce, int $expiresAt): bool =>
            self::claim($pdo, $nonce, $expiresAt);
    }
}
