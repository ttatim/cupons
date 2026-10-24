<?php
declare(strict_types=1);

/**
 * Histórico mínimo e imutável das observações importadas da Hotmart.
 * O horário é da coleta local; não representa a data do evento na Hotmart.
 * Não constitui extrato, conciliação, saldo nem prova de titularidade.
 */
final class HotmartCommissionObservationHistory
{
    /**
     * Deve ser chamado dentro da mesma transação PDO do upsert de staging.
     * Reimportar a mesma observação não cria um segundo evento.
     * @param list<array{transaction_hash:string,source:string,currency:string,amount_cents:int,commission_count:int}> $rows
     */
    public static function record(PDO $pdo, string $accountHash, array $rows, string $key, string $status): int
    {
        $statement = $pdo->prepare('INSERT INTO hotmart_commission_observations
            (account_hash, transaction_hash, source, currency, observed_status, amount_cents, commission_count, observation_hash)
            VALUES (:account_hash, :transaction_hash, :source, :currency, :observed_status, :amount_cents, :commission_count, :observation_hash)
            ON DUPLICATE KEY UPDATE observation_hash = observation_hash');
        $created = 0;
        foreach ($rows as $row) {
            $fingerprint = hash_hmac('sha256', json_encode([
                $accountHash, $row['transaction_hash'], $row['source'], $row['currency'],
                $status, $row['amount_cents'], $row['commission_count'],
            ], JSON_THROW_ON_ERROR), $key);
            $statement->execute([
                ':account_hash' => $accountHash,
                ':transaction_hash' => $row['transaction_hash'],
                ':source' => $row['source'],
                ':currency' => $row['currency'],
                ':observed_status' => $status,
                ':amount_cents' => $row['amount_cents'],
                ':commission_count' => $row['commission_count'],
                ':observation_hash' => $fingerprint,
            ]);
            $created += $statement->rowCount() === 1 ? 1 : 0;
        }
        return $created;
    }

    /**
     * Apenas contagens por status; não retorna hashes, valores ou identificadores.
     * @return list<array{status:string,observations:int}>
     */
    public static function breakdown(PDO $pdo, string $accountHash): array
    {
        $statement = $pdo->prepare('SELECT observed_status, COUNT(*) AS observations_count
            FROM hotmart_commission_observations WHERE account_hash = :account_hash
            GROUP BY observed_status ORDER BY observed_status');
        $statement->execute([':account_hash' => $accountHash]);
        $result = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $status = (string) ($row['observed_status'] ?? '');
            if (in_array($status, ['APPROVED', 'COMPLETE', 'REFUNDED', 'CHARGEBACK', 'PARTIALLY_REFUNDED'], true)) {
                $result[] = ['status' => $status, 'observations' => (int) $row['observations_count']];
            }
        }
        return $result;
    }

    /**
     * Inventário não destrutivo de retenção para subsidiar decisão do proprietário.
     * Faixas são cumulativas e calculadas sobre o horário local de coleta.
     * Não expõe hashes, valores, transações ou outros identificadores.
     *
     * @return array{total:int,older_30_days:int,older_90_days:int,older_365_days:int}
     */
    public static function retentionInventory(PDO $pdo, string $accountHash): array
    {
        if (!preg_match('/\\A[a-f0-9]{64}\\z/D', $accountHash)) {
            throw new InvalidArgumentException('Escopo de conta inválido.');
        }
        $statement = $pdo->prepare('SELECT COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN observed_at < CURRENT_TIMESTAMP - INTERVAL 30 DAY THEN 1 ELSE 0 END), 0) AS older_30_days,
            COALESCE(SUM(CASE WHEN observed_at < CURRENT_TIMESTAMP - INTERVAL 90 DAY THEN 1 ELSE 0 END), 0) AS older_90_days,
            COALESCE(SUM(CASE WHEN observed_at < CURRENT_TIMESTAMP - INTERVAL 365 DAY THEN 1 ELSE 0 END), 0) AS older_365_days
            FROM hotmart_commission_observations WHERE account_hash = :account_hash');
        $statement->execute([':account_hash' => $accountHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'total' => (int) ($row['total'] ?? 0),
            'older_30_days' => (int) ($row['older_30_days'] ?? 0),
            'older_90_days' => (int) ($row['older_90_days'] ?? 0),
            'older_365_days' => (int) ($row['older_365_days'] ?? 0),
        ];
    }

    /**
     * Diagnóstico de integridade local, somente leitura, sem transações ou PII.
     * Detecta inconsistências entre staging e histórico; não valida titularidade,
     * ordem de eventos, liquidação nem saldo da Hotmart.
     *
     * @return array{staged_without_history:int,orphan_observations:int,flagged_without_risk_history:int,unflagged_with_risk_history:int}
     */
    public static function integrityInventory(PDO $pdo, string $accountHash): array
    {
        if (strlen($accountHash) !== 64 || !ctype_xdigit($accountHash) || strtolower($accountHash) !== $accountHash) {
            throw new InvalidArgumentException('Escopo de conta inválido.');
        }
        $joins = 'o.account_hash = s.account_hash
            AND o.transaction_hash = s.transaction_hash
            AND o.source = s.source AND o.currency = s.currency';
        $risk = "o.observed_status IN ('REFUNDED', 'CHARGEBACK', 'PARTIALLY_REFUNDED')";
        $checks = [
            'staged_without_history' => 'SELECT COUNT(*) FROM hotmart_commission_staging s
                WHERE s.account_hash = :account_hash AND NOT EXISTS (
                    SELECT 1 FROM hotmart_commission_observations o WHERE ' . $joins . ')',
            'orphan_observations' => 'SELECT COUNT(*) FROM hotmart_commission_observations o
                WHERE o.account_hash = :account_hash AND NOT EXISTS (
                    SELECT 1 FROM hotmart_commission_staging s
                    WHERE s.account_hash = o.account_hash AND s.transaction_hash = o.transaction_hash
                    AND s.source = o.source AND s.currency = o.currency)',
            'flagged_without_risk_history' => 'SELECT COUNT(*) FROM hotmart_commission_staging s
                WHERE s.account_hash = :account_hash AND s.review_required = 1 AND NOT EXISTS (
                    SELECT 1 FROM hotmart_commission_observations o WHERE ' . $joins . ' AND ' . $risk . ')',
            'unflagged_with_risk_history' => 'SELECT COUNT(*) FROM hotmart_commission_staging s
                WHERE s.account_hash = :account_hash AND s.review_required = 0 AND EXISTS (
                    SELECT 1 FROM hotmart_commission_observations o WHERE ' . $joins . ' AND ' . $risk . ')',
        ];
        $inventory = [];
        foreach ($checks as $name => $sql) {
            $statement = $pdo->prepare($sql);
            $statement->execute([':account_hash' => $accountHash]);
            $inventory[$name] = (int) $statement->fetchColumn();
        }
        return $inventory;
    }

}
