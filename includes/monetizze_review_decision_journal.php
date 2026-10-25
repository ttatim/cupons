<?php
declare(strict_types=1);

/**
 * Internal append-only HUMAN TRIAGE of UNVERIFIED Monetizze candidates.
 * Caller MUST derive operatorRef from authenticated server-side identity
 * and authorize the exact account. No HTTP route or financial effects.
 */
final class MonetizzeReviewDecisionJournal
{
    private const ACTIONS = ['request_evidence', 'escalate', 'acknowledge_unverified'];

    /** @return array{decision_id:int,recorded:bool,authenticated:bool,financial_ready:bool} */
    public static function record(
        PDO $pdo, string $accountKey, string $hashKey, string $operatorRef,
        Closure $authorize, int $reviewId, string $requestId, string $action
    ): array {
        self::validate($pdo, $accountKey, $hashKey, $operatorRef);
        if ($reviewId < 1 || !preg_match('/\A[a-fA-F0-9]{32}\z/D', $requestId)
            || !in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('Decisão Monetizze inválida.');
        }
        if ($pdo->inTransaction()) {
            throw new RuntimeException('Decisão Monetizze requer transação exclusiva.');
        }
        self::requireAuthorization($authorize, $accountKey, $operatorRef);
        $accountHash = self::hash($accountKey, $hashKey, 'account');
        $operatorHash = self::hash($operatorRef, $hashKey, 'operator');
        $requestHash = self::hash(strtolower($requestId), $hashKey, 'decision-request');
        try {
            $pdo->beginTransaction();
            $candidate = $pdo->prepare('SELECT id FROM monetizze_review_candidates
                WHERE id = :id AND account_hash = :account_hash FOR UPDATE');
            $candidate->execute([':id' => $reviewId, ':account_hash' => $accountHash]);
            if ($candidate->fetchColumn() === false) {
                throw new RuntimeException('Candidato Monetizze indisponível para esta conta.');
            }
            $candidate->closeCursor();
            $recorded = true;
            try {
                $insert = $pdo->prepare('INSERT INTO monetizze_review_decisions
                    (account_hash, candidate_id, operator_hash, request_hash, action)
                    VALUES (:account_hash, :candidate_id, :operator_hash, :request_hash, :action)');
                $insert->execute([
                    ':account_hash' => $accountHash,
                    ':candidate_id' => $reviewId,
                    ':operator_hash' => $operatorHash,
                    ':request_hash' => $requestHash,
                    ':action' => $action,
                ]);
                $decisionId = (int) $pdo->lastInsertId();
            } catch (PDOException $exception) {
                if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                    throw $exception;
                }
                $existing = $pdo->prepare('SELECT id, candidate_id, operator_hash, action
                    FROM monetizze_review_decisions
                    WHERE account_hash = :account_hash AND request_hash = :request_hash');
                $existing->execute([':account_hash' => $accountHash, ':request_hash' => $requestHash]);
                $row = $existing->fetch(PDO::FETCH_ASSOC);
                if ($row === false || (int) $row['candidate_id'] !== $reviewId
                    || !hash_equals((string) $row['operator_hash'], $operatorHash)
                    || $row['action'] !== $action) {
                    throw new RuntimeException('Reutilização divergente de solicitação Monetizze.');
                }
                $decisionId = (int) $row['id'];
                $recorded = false;
            }
            $pdo->commit();
            return [
                'decision_id' => $decisionId, 'recorded' => $recorded,
                'authenticated' => false, 'financial_ready' => false,
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof InvalidArgumentException
                || ($exception instanceof RuntimeException && !($exception instanceof PDOException))) {
                throw $exception;
            }
            throw new RuntimeException('Registro de revisão Monetizze indisponível.', 0, $exception);
        }
    }

    /** @return array{items:list<array{decision_id:int,action:string,recorded_at:string}>,next_after_id:int,has_more:bool,authenticated:bool,financial_ready:bool,read_only:bool} */
    public static function historyForCandidate(
        PDO $pdo, string $accountKey, string $hashKey, string $operatorRef,
        Closure $authorize, int $reviewId, int $afterId = 0, int $limit = 25
    ): array {
        self::validate($pdo, $accountKey, $hashKey, $operatorRef);
        if ($reviewId < 1 || $afterId < 0 || $limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Consulta de decisões Monetizze inválida.');
        }
        self::requireAuthorization($authorize, $accountKey, $operatorRef);
        $accountHash = self::hash($accountKey, $hashKey, 'account');
        try {
            $candidate = $pdo->prepare('SELECT id FROM monetizze_review_candidates
            WHERE id = :id AND account_hash = :account_hash');
        $candidate->execute([':id' => $reviewId, ':account_hash' => $accountHash]);
        if ($candidate->fetchColumn() === false) {
            throw new RuntimeException('Candidato Monetizze indisponível para esta conta.');
        }
        $query = $pdo->prepare('SELECT id, action, recorded_at FROM monetizze_review_decisions
            WHERE account_hash = :account_hash AND candidate_id = :candidate_id
              AND id > :after_id ORDER BY id ASC LIMIT :page_limit');
        $query->bindValue(':account_hash', $accountHash, PDO::PARAM_STR);
        $query->bindValue(':candidate_id', $reviewId, PDO::PARAM_INT);
        $query->bindValue(':after_id', $afterId, PDO::PARAM_INT);
        $query->bindValue(':page_limit', $limit + 1, PDO::PARAM_INT);
        $query->execute();
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        $hasMore = count($rows) > $limit;
        $items = [];
        $cursor = $afterId;
        foreach (array_slice($rows, 0, $limit) as $row) {
            $cursor = (int) $row['id'];
            $items[] = [
                'decision_id' => $cursor,
                'action' => (string) $row['action'],
                'recorded_at' => (string) $row['recorded_at'],
            ];
        }
        return [
            'items' => $items, 'next_after_id' => $cursor, 'has_more' => $hasMore,
            'authenticated' => false, 'financial_ready' => false, 'read_only' => true,
            ];
        } catch (PDOException $exception) {
            throw new RuntimeException('Consulta de revisão Monetizze indisponível.', 0, $exception);
        }
    }

    private static function validate(PDO $pdo, string $accountKey, string $hashKey, string $operatorRef): void
    {
        if (!preg_match('/\A[a-fA-F0-9]{32}\z/D', $accountKey)
            || strlen($hashKey) < 32 || strlen($hashKey) > 1024
            || preg_match('/[\x00-\x1f\x7f]/', $hashKey)
            || !preg_match('/\A[a-zA-Z0-9_.:@-]{1,128}\z/D', $operatorRef)) {
            throw new InvalidArgumentException('Identidade ou chave Monetizze inválida.');
        }
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new RuntimeException('Revisão Monetizze requer MySQL em modo de exceção.');
        }
    }

    private static function requireAuthorization(Closure $authorize, string $accountKey, string $operatorRef): void
    {
        try {
            if ($authorize($accountKey, $operatorRef) !== true) {
                throw new RuntimeException('Acesso à revisão Monetizze não autorizado.');
            }
        } catch (Throwable $exception) {
            throw new RuntimeException('Acesso à revisão Monetizze não autorizado.');
        }
    }

    private static function hash(string $value, string $key, string $domain): string
    {
        return hash_hmac('sha256', 'monetizze:' . $domain . ':'
            . ($domain === 'operator' ? $value : strtolower($value)), $key);
    }
}
