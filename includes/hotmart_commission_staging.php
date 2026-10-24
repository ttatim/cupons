<?php
declare(strict_types=1);
require_once __DIR__ . '/hotmart_commission_observation_history.php';

/**
 * Registro mínimo de observações de comissões Hotmart para revisão humana.
 * Não é extrato, conciliação, receita, saldo ou autorização de pagamento.
 * Não armazena UCODE, transação, comprador, nome, email ou token em claro.
 */
final class HotmartCommissionStaging
{
    private const STATUSES = ['APPROVED', 'COMPLETE', 'REFUNDED', 'CHARGEBACK', 'PARTIALLY_REFUNDED'];
    private const SOURCES = ['PRODUCER', 'COPRODUCER', 'AFFILIATE', 'ADDON'];

    public static function validStatus(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }

    private static function scope(string $ucode, string $key): string
    {
        if (!preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/iD', $ucode)) {
            throw new InvalidArgumentException('UCODE da conta inválido.');
        }
        if (strlen($key) < 32 || strlen($key) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $key)) {
            throw new InvalidArgumentException('Configure HOTMART_COMMISSION_HASH_KEY com pelo menos 32 caracteres aleatórios.');
        }
        return hash_hmac('sha256', 'hotmart:account:' . strtolower($ucode), $key);
    }

    private static function cents(mixed $value): int
    {
        if ((!is_string($value) && !is_int($value) && !is_float($value)) || (is_float($value) && !is_finite($value))) {
            throw new InvalidArgumentException('Valor de comissão inválido.');
        }
        $text = (string) $value;
        if (!preg_match('/\A(-?)([0-9]{1,12})(?:\.([0-9]{1,2}))?\z/D', $text, $parts)) {
            throw new InvalidArgumentException('Valor de comissão com precisão inválida.');
        }
        $amount = (int) $parts[2] * 100 + (int) str_pad($parts[3] ?? '', 2, '0', STR_PAD_RIGHT);
        return $parts[1] === '-' ? -$amount : $amount;
    }

    /**
     * Normaliza exclusivamente parcelas com UCODE coincidente. Rejeita página
     * ambígua inteira para não registrar dados financeiros parcialmente.
     * @return array{account_hash:string,rows:array,matched:int}
     */
    public static function normalize(array $items, string $ucode, string $key, string $status): array
    {
        $accountHash = self::scope($ucode, $key);
        if (!self::validStatus($status) || !array_is_list($items) || count($items) > 50) {
            throw new InvalidArgumentException('Status ou página Hotmart inválidos.');
        }
        $rows = [];
        $matched = 0;
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['transaction'] ?? null)
                || !preg_match('/\A[A-Z0-9_-]{6,64}\z/D', $item['transaction'])
                || !is_array($item['commissions'] ?? null)
                || !array_is_list($item['commissions']) || count($item['commissions']) > 100) {
                throw new InvalidArgumentException('Transação Hotmart inválida ou sem identificador estável.');
            }
            $transactionHash = hash_hmac('sha256', 'hotmart:transaction:' . $item['transaction'], $key);
            foreach ($item['commissions'] as $entry) {
                if (!is_array($entry)) {
                    throw new InvalidArgumentException('Parcela de comissão inválida.');
                }
                $entryUcode = is_array($entry['user'] ?? null) ? ($entry['user']['ucode'] ?? null) : null;
                if (!is_string($entryUcode)
                    || !preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/iD', $entryUcode)
                    || !hash_equals(strtolower($ucode), strtolower($entryUcode))) {
                    continue;
                }
                $source = $entry['source'] ?? null;
                $commission = $entry['commission'] ?? null;
                $currency = is_array($commission) ? ($commission['currency_value'] ?? null) : null;
                if (!is_string($source) || !in_array($source, self::SOURCES, true)
                    || !is_string($currency) || !preg_match('/\A[A-Z]{3}\z/D', $currency)) {
                    throw new InvalidArgumentException('Origem ou moeda da comissão coincidente inválida.');
                }
                $amount = self::cents($commission['value'] ?? null);
                $groupKey = $transactionHash . ':' . $source . ':' . $currency;
                if (!isset($rows[$groupKey])) {
                    $rows[$groupKey] = [
                        'transaction_hash' => $transactionHash,
                        'source' => $source,
                        'currency' => $currency,
                        'amount_cents' => 0,
                        'commission_count' => 0,
                    ];
                }
                $rows[$groupKey]['amount_cents'] += $amount;
                $rows[$groupKey]['commission_count']++;
                $matched++;
            }
        }
        return ['account_hash' => $accountHash, 'rows' => array_values($rows), 'matched' => $matched];
    }

    /**
     * Upsert idempotente por conta, transação, papel e moeda. Flags de
     * reembolso/estorno são permanentes até revisão externa explícita.
     * O status recebido é o filtro aplicado à API, NÃO uma prova de liquidação.
     */
    public static function stage(PDO $pdo, string $ucode, string $key, array $items, string $status): array
    {
        $normalized = self::normalize($items, $ucode, $key, $status);
        $review = in_array($status, ['REFUNDED', 'CHARGEBACK', 'PARTIALLY_REFUNDED'], true) ? 1 : 0;
        $sql = 'INSERT INTO hotmart_commission_staging
            (account_hash, transaction_hash, source, currency, amount_cents, commission_count, observed_status, review_required)
            VALUES (:account_hash, :transaction_hash, :source, :currency, :amount_cents, :commission_count, :observed_status, :review_required)
            ON DUPLICATE KEY UPDATE
            amount_cents = IF(review_required = 1 AND VALUES(review_required) = 0, amount_cents, VALUES(amount_cents)),
            commission_count = IF(review_required = 1 AND VALUES(review_required) = 0, commission_count, VALUES(commission_count)),
            observed_status = IF(review_required = 1 AND VALUES(review_required) = 0, observed_status, VALUES(observed_status)),
            review_required = GREATEST(review_required, VALUES(review_required)), updated_at = CURRENT_TIMESTAMP';
        $pdo->beginTransaction();
        $observationsRecorded = 0;
        try {
            $statement = $pdo->prepare($sql);
            foreach ($normalized['rows'] as $row) {
                $statement->execute([
                    ':account_hash' => $normalized['account_hash'],
                    ':transaction_hash' => $row['transaction_hash'],
                    ':source' => $row['source'],
                    ':currency' => $row['currency'],
                    ':amount_cents' => $row['amount_cents'],
                    ':commission_count' => $row['commission_count'],
                    ':observed_status' => $status,
                    ':review_required' => $review,
                ]);
            }
            // Histórico distinto por conteúdo, no mesmo commit da atualização do staging.
            // Não presume ordem cronológica da Hotmart nem altera saldo.
            $observationsRecorded = HotmartCommissionObservationHistory::record(
                $pdo, $normalized['account_hash'], $normalized['rows'], $key, $status
            );
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        return ['groups_staged' => count($normalized['rows']), 'commissions_matched' => $normalized['matched'], 'review_required' => $review === 1, 'observations_recorded' => $observationsRecorded];
    }

    /**
     * Fila agregada de alertas de reembolso/estorno da conta configurada.
     * Somente status conhecidos e contagens; não retorna hashes, valores ou PII.
     * Um alerta nunca é automaticamente marcado como resolvido.
     *
     * @return list<array{status:string,groups:int}>
     */
    public static function reviewBreakdown(PDO $pdo, string $ucode, string $key): array
    {
        $stmt = $pdo->prepare('SELECT observed_status, COUNT(*) AS groups_count
            FROM hotmart_commission_staging
            WHERE account_hash = :account_hash AND review_required = 1
            GROUP BY observed_status ORDER BY observed_status');
        $stmt->execute([':account_hash' => self::scope($ucode, $key)]);
        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $status = (string) ($row['observed_status'] ?? '');
            if (self::validStatus($status)) {
                $result[] = ['status' => $status, 'groups' => (int) $row['groups_count']];
            }
        }
        return $result;
    }

    /** Histórico local agregado; o horário de coleta não confirma a ordem dos eventos da API. */
    public static function observationBreakdown(PDO $pdo, string $ucode, string $key): array
    {
        return HotmartCommissionObservationHistory::breakdown($pdo, self::scope($ucode, $key));
    }

    /**
     * Contagens cumulativas por idade das observações. Apenas leitura:
     * a decisão sobre retenção e eventual exclusão permanece humana.
     */
    public static function retentionInventory(PDO $pdo, string $ucode, string $key): array
    {
        return HotmartCommissionObservationHistory::retentionInventory($pdo, self::scope($ucode, $key));
    }

    /** Diagnóstico local sem ajustes financeiros nem exposição de identificadores. */
    public static function integrityInventory(PDO $pdo, string $ucode, string $key): array
    {
        return HotmartCommissionObservationHistory::integrityInventory($pdo, self::scope($ucode, $key));
    }

    /** Somente contagens, sem hashes nem valores identificáveis de transação. */
    public static function counts(PDO $pdo, string $ucode, string $key): array
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) AS groups_total, COALESCE(SUM(review_required), 0) AS groups_to_review
            FROM hotmart_commission_staging WHERE account_hash = :account_hash');
        $stmt->execute([':account_hash' => self::scope($ucode, $key)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ['groups_total' => (int) ($row['groups_total'] ?? 0), 'groups_to_review' => (int) ($row['groups_to_review'] ?? 0)];
    }
}
