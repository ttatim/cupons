<?php
declare(strict_types=1);

/**
 * Armazena exclusivamente metadados de catálogo em área de revisão.
 * Nenhum registro desta tabela é publicado na vitrine ou recebe link de checkout.
 */
final class AffiliateProductStaging
{
    public static function normalizeKiwify(array $product): array
    {
        $id = $product['id'] ?? null;
        if (!is_string($id) && !is_int($id)) {
            throw new InvalidArgumentException('Identificador de produto ausente.');
        }
        $id = trim((string) $id);
        $name = $product['name'] ?? null;
        if ($id === '' || strlen($id) > 128 || preg_match('/[\x00-\x1f\x7f]/', $id)
            || !is_string($name) || trim($name) === '') {
            throw new InvalidArgumentException('Produto sem identificador ou nome válido.');
        }
        $name = trim($name);
        if (strlen($name) > 255) {
            throw new InvalidArgumentException('Nome de produto excede 255 bytes.');
        }
        $status = $product['status'] ?? 'desconhecido';
        $status = is_string($status) ? trim($status) : 'desconhecido';
        if ($status === '') {
            $status = 'desconhecido';
        }
        if (strlen($status) > 40 || preg_match('/[\x00-\x1f\x7f]/', $status)) {
            throw new InvalidArgumentException('Status de produto inválido.');
        }
        $currency = $product['currency'] ?? null;
        $currency = is_string($currency) ? strtoupper(trim($currency)) : null;
        if ($currency === '' || ($currency !== null && !preg_match('/\A[A-Z]{3}\z/D', $currency))) {
            $currency = null;
        }
        return [
            'external_id' => $id,
            'name' => $name,
            'status' => $status,
            'currency' => $currency,
        ];
    }

    private static function accountHash(string $accountId): string
    {
        if (!preg_match('/\A[a-zA-Z0-9_-]{3,128}\z/D', $accountId)) {
            throw new InvalidArgumentException('Identificador da conta Kiwify inválido.');
        }
        return hash('sha256', $accountId);
    }

    /**
     * Upsert transacional; recusa lotes grandes e descarta campos não permitidos.
     * @return array{processed:int,rejected:int}
     */
    public static function stageKiwify(PDO $pdo, string $accountId, array $products): array
    {
        $accountHash = self::accountHash($accountId);
        if (count($products) > 50) {
            throw new InvalidArgumentException('Lote de produtos excede o limite de 50.');
        }
        $normalized = [];
        $rejected = 0;
        foreach ($products as $product) {
            if (!is_array($product)) {
                $rejected++;
                continue;
            }
            try {
                $normalized[] = self::normalizeKiwify($product);
            } catch (InvalidArgumentException $exception) {
                $rejected++;
            }
        }
        $sql = 'INSERT INTO affiliate_product_staging
            (provider, account_hash, external_id, name, status, currency)
            VALUES (\'kiwify\', :account_hash, :external_id, :name, :status, :currency)
            ON DUPLICATE KEY UPDATE name = VALUES(name), status = VALUES(status),
            currency = VALUES(currency), updated_at = CURRENT_TIMESTAMP';
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare($sql);
            foreach ($normalized as $item) {
                $statement->execute([
                    ':account_hash' => $accountHash,
                    ':external_id' => $item['external_id'],
                    ':name' => $item['name'],
                    ':status' => $item['status'],
                    ':currency' => $item['currency'],
                ]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        return ['processed' => count($normalized), 'rejected' => $rejected];
    }

    /** Lista apenas a conta configurada, sem expor credenciais ou URLs. */
    public static function listKiwify(PDO $pdo, string $accountId, int $limit = 20): array
    {
        $accountHash = self::accountHash($accountId);
        if ($limit < 1 || $limit > 50) {
            throw new InvalidArgumentException('Limite de listagem inválido.');
        }
        $statement = $pdo->prepare('SELECT external_id, name, status, currency, updated_at, review_description, review_price, review_original_price, review_checkout_url, review_image_url, review_store_id
            FROM affiliate_product_staging WHERE provider = :provider AND account_hash = :account_hash
            ORDER BY updated_at DESC, external_id ASC LIMIT ' . $limit);
        $statement->execute([':provider' => 'kiwify', ':account_hash' => $accountHash]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
