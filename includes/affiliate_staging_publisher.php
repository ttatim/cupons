<?php
declare(strict_types=1);
require_once __DIR__ . '/affiliate_product_staging.php';

/**
 * Publicação explícita e transacional de um produto Kiwify em revisão.
 * Não usa checkout retornado pela API; exige URL e preço confirmados pelo editor.
 */
final class AffiliateStagingPublisher
{
    public static function validateCheckoutUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048
            || preg_match('/[\\x00-\\x20\\x7f]/', $url)
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Informe uma URL de checkout válida, sem espaços ou caracteres de controle.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw new InvalidArgumentException('O checkout deve usar HTTPS, sem credenciais ou portas alternativas.');
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
            throw new InvalidArgumentException('O checkout precisa ter domínio público.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new InvalidArgumentException('Endereço privado ou reservado não é permitido.');
            }
        } elseif (!str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new InvalidArgumentException('Domínio de checkout inválido.');
        }
        return $url;
    }

    public static function validatePrice(string $price): string
    {
        $price = trim($price);
        if (!preg_match('/\\A(?:0|[1-9][0-9]{0,7})(?:\\.[0-9]{1,2})?\\z/D', $price)
            || (float) $price <= 0) {
            throw new InvalidArgumentException('Informe preço positivo em reais, com ponto decimal e até duas casas.');
        }
        return number_format((float) $price, 2, '.', '');
    }

    public static function publishKiwify(
        PDO $pdo,
        string $accountId,
        string $externalId,
        int $storeId,
        string $description,
        string $price,
        string $checkoutUrl
    ): int {
        $accountHash = AffiliateProductStaging::accountHash($accountId);
        $externalId = trim($externalId);
        $description = trim($description);
        if ($externalId === '' || strlen($externalId) > 128 || preg_match('/[\\x00-\\x1f\\x7f]/', $externalId)) {
            throw new InvalidArgumentException('Identificador externo inválido.');
        }
        if ($storeId < 1 || mb_strlen($description, 'UTF-8') < 20
            || mb_strlen($description, 'UTF-8') > 1500) {
            throw new InvalidArgumentException('Selecione uma loja e descreva o produto entre 20 e 1500 caracteres.');
        }
        $price = self::validatePrice($price);
        $checkoutUrl = self::validateCheckoutUrl($checkoutUrl);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id, name, currency, published_product_id
                FROM affiliate_product_staging
                WHERE provider = :provider AND account_hash = :account_hash AND external_id = :external_id
                FOR UPDATE');
            $stmt->execute([
                ':provider' => 'kiwify',
                ':account_hash' => $accountHash,
                ':external_id' => $externalId,
            ]);
            $staged = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$staged) {
                throw new DomainException('Produto não encontrado na conta Kiwify configurada.');
            }
            if ($staged['published_product_id'] !== null) {
                throw new DomainException('Este produto já foi publicado. Evite cadastrar ofertas duplicadas.');
            }
            if ($staged['currency'] !== null && $staged['currency'] !== 'BRL') {
                throw new DomainException('Moeda diferente de BRL. Revise a conversão antes de publicar.');
            }
            $stmt = $pdo->prepare('SELECT id FROM lojas WHERE id = :id AND ativo = 1');
            $stmt->execute([':id' => $storeId]);
            if (!$stmt->fetchColumn()) {
                throw new DomainException('Selecione uma loja ativa para publicar o produto.');
            }
            $slug = 'kiwify-' . bin2hex(random_bytes(12));
            $stmt = $pdo->prepare('INSERT INTO produtos
                (loja_id, nome, slug, descricao, preco, link_afiliado, imagens, ativo)
                VALUES (:store, :name, :slug, :description, :price, :checkout, :images, 1)');
            $stmt->execute([
                ':store' => $storeId,
                ':name' => $staged['name'],
                ':slug' => $slug,
                ':description' => $description,
                ':price' => $price,
                ':checkout' => $checkoutUrl,
                ':images' => '[]',
            ]);
            $productId = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare('UPDATE affiliate_product_staging
                SET published_product_id = :product_id, published_at = NOW()
                WHERE id = :id AND published_product_id IS NULL');
            $stmt->execute([':product_id' => $productId, ':id' => $staged['id']]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Falha na marcação da publicação.');
            }
            $pdo->commit();
            return $productId;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
