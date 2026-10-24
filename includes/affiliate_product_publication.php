<?php
declare(strict_types=1);
require_once __DIR__ . '/affiliate_product_review.php';
require_once __DIR__ . '/offer_metadata_importer.php';

/**
 * Publicação exclusivamente manual de um produto previamente revisado.
 * Nunca consulta URLs externas nem lê credenciais de afiliados.
 */
final class AffiliateProductPublication
{
    /** @return array{description:string,price:string,original_price:?string,checkout_url:string,image_url:?string,store_id:int} */
    public static function validateReviewed(array $row): array
    {
        if (strtolower((string) ($row['status'] ?? '')) !== 'active') {
            throw new DomainException('Produto não está ativo na origem.');
        }
        if (strtoupper((string) ($row['currency'] ?? '')) !== 'BRL') {
            throw new DomainException('Publicação exige moeda BRL confirmada.');
        }
        $fields = AffiliateProductReview::validate([
            'description' => $row['review_description'] ?? '',
            'price' => $row['review_price'] ?? '',
            'original_price' => $row['review_original_price'] ?? '',
            'checkout_url' => $row['review_checkout_url'] ?? '',
            'image_url' => $row['review_image_url'] ?? '',
            'store_id' => $row['review_store_id'] === null ? '' : (string) $row['review_store_id'],
        ]);
        if ($fields['description'] === null || mb_strlen($fields['description'], 'UTF-8') < 20
            || $fields['price'] === null || $fields['checkout_url'] === null
            || $fields['store_id'] === null) {
            throw new DomainException('Preencha descrição (mínimo 20 caracteres), preço, checkout HTTPS e loja antes de publicar.');
        }
        // O destino também deve ser aceito pelo redirecionador público go.php.
        $checkout = OfferMetadataImporter::validatePublicUrl($fields['checkout_url']);
        return [
            'description' => $fields['description'],
            'price' => $fields['price'],
            'original_price' => $fields['original_price'],
            'checkout_url' => $checkout,
            'image_url' => $fields['image_url'],
            'store_id' => $fields['store_id'],
        ];
    }

    /** @return array<string,int> Mapeia somente publicações da conta configurada. */
    public static function publicationMap(PDO $pdo, string $accountId): array
    {
        if (!preg_match('/\\A[a-zA-Z0-9_-]{3,128}\\z/D', $accountId)) {
            throw new InvalidArgumentException('Conta Kiwify inválida.');
        }
        $statement = $pdo->prepare("SELECT external_id, published_product_id
            FROM affiliate_product_staging WHERE provider = 'kiwify' AND account_hash = :hash
            AND published_product_id IS NOT NULL ORDER BY id DESC LIMIT 50");
        $statement->execute([':hash' => hash('sha256', $accountId)]);
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['external_id']] = (int) $row['published_product_id'];
        }
        return $result;
    }

    public static function publishKiwify(PDO $pdo, string $accountId, string $externalId): int
    {
        if (!preg_match('/\A[a-zA-Z0-9_-]{3,128}\z/D', $accountId)
            || $externalId === '' || strlen($externalId) > 128
            || preg_match('/[\x00-\x1f\x7f]/', $externalId)) {
            throw new InvalidArgumentException('Conta ou identificador externo inválido.');
        }
        $pdo->beginTransaction();
        try {
            $select = $pdo->prepare("SELECT * FROM affiliate_product_staging
                WHERE provider = 'kiwify' AND account_hash = :hash AND external_id = :external
                FOR UPDATE");
            $select->execute([':hash' => hash('sha256', $accountId), ':external' => $externalId]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw new DomainException('Produto não encontrado na conta Kiwify configurada.');
            }
            if ($row['published_product_id'] !== null) {
                throw new DomainException('Este produto já foi publicado. Não serão criadas duplicações.');
            }
            $fields = self::validateReviewed($row);
            $store = $pdo->prepare('SELECT id FROM lojas WHERE id = :id LIMIT 1');
            $store->execute([':id' => $fields['store_id']]);
            if ($store->fetchColumn() === false) {
                throw new DomainException('Loja selecionada não existe.');
            }
            $images = $fields['image_url'] === null ? '[]'
                : json_encode([$fields['image_url']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $insert = $pdo->prepare('INSERT INTO produtos
                (loja_id, nome, descricao, preco, preco_original, link_afiliado, imagens, ativo)
                VALUES (:store, :name, :description, :price, :original, :checkout, :images, 1)');
            $insert->execute([
                ':store' => $fields['store_id'],
                ':name' => $row['name'],
                ':description' => $fields['description'],
                ':price' => $fields['price'],
                ':original' => $fields['original_price'],
                ':checkout' => $fields['checkout_url'],
                ':images' => $images,
            ]);
            $id = (int) $pdo->lastInsertId();
            if ($id < 1) {
                throw new RuntimeException('Falha ao registrar o produto publicado.');
            }
            $update = $pdo->prepare('UPDATE affiliate_product_staging
                SET published_product_id = :product, published_at = CURRENT_TIMESTAMP
                WHERE id = :id AND published_product_id IS NULL');
            $update->execute([':product' => $id, ':id' => $row['id']]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Não foi possível confirmar a publicação.');
            }
            $pdo->commit();
            return $id;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
