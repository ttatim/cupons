<?php
declare(strict_types=1);

/**
 * Internal, READ-ONLY review queue for unverified Monetizze relay candidates.
 *
 * This is NOT a public endpoint or an approval workflow. The caller MUST
 * authenticate the operator and authorize access to the exact account before
 * supplying its key. Relay HMAC does not prove upstream Monetizze origin.
 */
final class MonetizzeReviewQueue
{
    /**
     * Keyset pagination scoped to one caller-authorized account.
     *
     * No external IDs, account hashes, event hashes, buyers, amounts or tokens
     * are returned. The local review_id is only an opaque row reference.
     *
     * @return array{items:list<array<string,int|string>>,next_after_id:int,has_more:bool,authenticated:bool,financial_ready:bool,read_only:bool}
     */
    public static function listForAccount(
        PDO $pdo,
        string $accountKey,
        string $hashKey,
        int $afterId = 0,
        int $limit = 25
    ): array {
        if (!preg_match('/\A[a-fA-F0-9]{32}\z/D', $accountKey)) {
            throw new InvalidArgumentException('Conta Monetizze inválida.');
        }
        if (strlen($hashKey) < 32 || strlen($hashKey) > 1024
            || preg_match('/[\x00-\x1f\x7f]/', $hashKey)) {
            throw new InvalidArgumentException('Chave de hash Monetizze inválida.');
        }
        if ($afterId < 0 || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Paginação Monetizze inválida.');
        }
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new RuntimeException('Fila Monetizze requer MySQL em modo de exceção.');
        }

        $accountHash = hash_hmac(
            'sha256', 'monetizze:account:' . strtolower($accountKey), $hashKey
        );
        // Bind integers explicitly; LIMIT must not be string-interpolated.
        $query = $pdo->prepare(
            'SELECT id, original_status, original_event, candidate_status,
                    candidate_event, risk_direction, first_seen_at
             FROM monetizze_review_candidates
             WHERE account_hash = :account_hash AND id > :after_id
             ORDER BY id ASC LIMIT :page_limit'
        );
        $query->bindValue(':account_hash', $accountHash, PDO::PARAM_STR);
        $query->bindValue(':after_id', $afterId, PDO::PARAM_INT);
        $query->bindValue(':page_limit', $limit + 1, PDO::PARAM_INT);
        $query->execute();
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $items = [];
        $cursor = $afterId;
        foreach ($rows as $row) {
            $cursor = (int) $row['id'];
            $items[] = [
                'review_id' => $cursor,
                'original_status' => (int) $row['original_status'],
                'original_event' => (int) $row['original_event'],
                'candidate_status' => (int) $row['candidate_status'],
                'candidate_event' => (int) $row['candidate_event'],
                'risk_direction' => (string) $row['risk_direction'],
                'first_seen_at' => (string) $row['first_seen_at'],
            ];
        }
        return [
            'items' => $items,
            'next_after_id' => $cursor,
            'has_more' => $hasMore,
            'authenticated' => false,
            'financial_ready' => false,
            'read_only' => true,
        ];
    }
}
