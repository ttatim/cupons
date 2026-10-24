<?php
declare(strict_types=1);

/**
 * Enriquecimento manual de produtos Kiwify importados para staging.
 * Nunca publica ofertas e não efetua requisições HTTP às URLs informadas.
 */
final class AffiliateProductReview
{
    private static function accountHash(string $accountId): string
    {
        if (!preg_match('/\A[a-zA-Z0-9_-]{3,128}\z/D', $accountId)) {
            throw new InvalidArgumentException('Conta Kiwify inválida.');
        }
        return hash('sha256', $accountId);
    }

    private static function money(mixed $value, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || !preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/D', $value)) {
            throw new InvalidArgumentException($label . ': use número positivo com ponto decimal e até duas casas.');
        }
        $parts = explode('.', $value, 2);
        return $parts[0] . '.' . str_pad($parts[1] ?? '', 2, '0');
    }

    private static function cents(string $value): int
    {
        [$whole, $fraction] = explode('.', $value, 2);
        return (int) $whole * 100 + (int) $fraction;
    }

    private static function httpsUrl(mixed $value, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException($label . ': URL inválida.');
        }
        $value = trim($value);
        if (strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f]/', $value)
            || filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException($label . ': URL inválida.');
        }
        $parts = parse_url($value);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || empty($parts['host'])) {
            throw new InvalidArgumentException($label . ': informe URL HTTPS sem credenciais ou fragmentos.');
        }
        $host = strtolower((string) $parts['host']);
        if ($host === 'localhost' || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            throw new InvalidArgumentException($label . ': destino não permitido.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new InvalidArgumentException($label . ': IP privado ou reservado não permitido.');
            }
        } elseif (!str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new InvalidArgumentException($label . ': domínio inválido.');
        }
        return $value;
    }

    /** @return array{description:?string,price:?string,original_price:?string,checkout_url:?string,image_url:?string,store_id:?int} */
    public static function validate(array $input): array
    {
        $description = $input['description'] ?? '';
        if (!is_string($description) || strlen(trim($description)) > 1500) {
            throw new InvalidArgumentException('Descrição deve ter até 1500 bytes.');
        }
        $description = trim($description);
        $price = self::money($input['price'] ?? '', 'Preço');
        $original = self::money($input['original_price'] ?? '', 'Preço original');
        if ($price !== null && self::cents($price) === 0) {
            throw new InvalidArgumentException('Preço deve ser maior que zero.');
        }
        if ($price !== null && $original !== null && self::cents($original) < self::cents($price)) {
            throw new InvalidArgumentException('Preço original não pode ser inferior ao preço.');
        }
        $store = $input['store_id'] ?? '';
        if ($store !== '' && $store !== null
            && (!is_string($store) || !preg_match('/\A[1-9][0-9]{0,9}\z/D', $store)
                || (float) $store > PHP_INT_MAX)) {
            throw new InvalidArgumentException('Loja inválida.');
        }
        return [
            'description' => $description === '' ? null : $description,
            'price' => $price,
            'original_price' => $original,
            'checkout_url' => self::httpsUrl($input['checkout_url'] ?? '', 'Checkout'),
            'image_url' => self::httpsUrl($input['image_url'] ?? '', 'Imagem'),
            'store_id' => $store === '' || $store === null ? null : (int) $store,
        ];
    }

    /**
     * Atualiza somente o registro pertencente à conta Kiwify ativa.
     * Exige migração 20261024_affiliate_product_review.sql.
     */
    public static function saveKiwify(PDO $pdo, string $accountId, string $externalId, array $input): void
    {
        $hash = self::accountHash($accountId);
        if ($externalId === '' || strlen($externalId) > 128 || preg_match('/[\x00-\x1f\x7f]/', $externalId)) {
            throw new InvalidArgumentException('Identificador externo inválido.');
        }
        $fields = self::validate($input);
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare("SELECT id FROM affiliate_product_staging
                WHERE provider = 'kiwify' AND account_hash = :account AND external_id = :external
                FOR UPDATE");
            $statement->execute([':account' => $hash, ':external' => $externalId]);
            $id = $statement->fetchColumn();
            if ($id === false) {
                throw new DomainException('Produto não encontrado na revisão desta conta.');
            }
            if ($fields['store_id'] !== null) {
                $store = $pdo->prepare('SELECT id FROM lojas WHERE id = :id');
                $store->execute([':id' => $fields['store_id']]);
                if ($store->fetchColumn() === false) {
                    throw new InvalidArgumentException('Loja não cadastrada.');
                }
            }
            $update = $pdo->prepare("UPDATE affiliate_product_staging SET
                review_description = :description, review_price = :price,
                review_original_price = :original_price, review_checkout_url = :checkout_url,
                review_image_url = :image_url, review_store_id = :store_id,
                updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $update->execute([
                ':description' => $fields['description'],
                ':price' => $fields['price'],
                ':original_price' => $fields['original_price'],
                ':checkout_url' => $fields['checkout_url'],
                ':image_url' => $fields['image_url'],
                ':store_id' => $fields['store_id'],
                ':id' => $id,
            ]);
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }
}
