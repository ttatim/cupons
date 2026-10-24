<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/affiliate_product_publication.php';
require_once __DIR__ . '/../includes/affiliate_product_staging.php';

function checkPublication(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('Falhou: ' . $label);
    }
}
$review = [
    'name' => 'Produto Kiwify revisado', 'status' => 'active', 'currency' => 'BRL',
    'review_description' => 'Descrição validada para publicação supervisionada.',
    'review_price' => '39.90', 'review_original_price' => '49.90',
    'review_checkout_url' => 'https://checkout.example.com/pay?affiliate=123',
    'review_image_url' => 'https://cdn.example.com/item.jpg',
    'review_store_id' => 77,
];
$validated = AffiliateProductPublication::validateReviewed($review);
checkPublication($validated['price'] === '39.90' && $validated['store_id'] === 77, 'validação de revisão completa');
foreach ([
    ['status' => 'draft'],
    ['currency' => 'USD'],
    ['review_description' => 'Curta'],
    ['review_price' => null],
    ['review_checkout_url' => 'http://example.com/checkout'],
    ['review_checkout_url' => 'https://127.0.0.1/checkout'],
    ['review_checkout_url' => 'https://checkout.example.invalid/checkout'],
    ['review_store_id' => null],
] as $change) {
    try {
        AffiliateProductPublication::validateReviewed(array_merge($review, $change));
        throw new RuntimeException('Publicação incompleta ou insegura aceita: ' . json_encode($change));
    } catch (InvalidArgumentException|DomainException $expected) {
        // Entrada rejeitada.
    }
}
$dsn = (string) getenv('TEST_DB_DSN');
if ($dsn === '') {
    echo "Publicação: validação local aprovada; integração MySQL não executada.\n";
    exit(0);
}
if (!str_contains($dsn, 'dbname=cupons_test')) {
    throw new RuntimeException('Banco de teste obrigatório: cupons_test.');
}
$pdo = new PDO($dsn, (string) getenv('TEST_DB_USER'), (string) getenv('TEST_DB_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("CREATE TABLE IF NOT EXISTS lojas (id INT PRIMARY KEY, nome VARCHAR(100) NOT NULL) ENGINE=InnoDB");
$pdo->exec("CREATE TABLE IF NOT EXISTS produtos (
    id INT PRIMARY KEY AUTO_INCREMENT, loja_id INT, nome VARCHAR(255) NOT NULL,
    descricao TEXT, preco DECIMAL(10,2), preco_original DECIMAL(10,2),
    link_afiliado TEXT, imagens JSON, ativo BOOLEAN DEFAULT TRUE
) ENGINE=InnoDB");
$pdo->exec((string) file_get_contents(__DIR__ . '/../sql/updates/20261024_affiliate_product_staging.sql'));
if (!$pdo->query("SHOW COLUMNS FROM affiliate_product_staging LIKE 'review_description'")->fetch(PDO::FETCH_ASSOC)) {
    $pdo->exec((string) file_get_contents(__DIR__ . '/../sql/updates/20261024_affiliate_product_review.sql'));
}
if (!$pdo->query("SHOW COLUMNS FROM affiliate_product_staging LIKE 'published_product_id'")->fetch(PDO::FETCH_ASSOC)) {
    $pdo->exec((string) file_get_contents(__DIR__ . '/../sql/updates/20261024_affiliate_product_publication.sql'));
}
$pdo->exec('DELETE FROM affiliate_product_staging');
$pdo->exec('DELETE FROM produtos');
$pdo->exec("INSERT INTO lojas (id, nome) VALUES (77, 'Loja de testes') ON DUPLICATE KEY UPDATE nome = VALUES(nome)");
$account = 'kiwify-test-account-a';
$product = ['id' => 'kiwify-101', 'name' => 'Produto Kiwify revisado', 'status' => 'active', 'currency' => 'BRL'];
AffiliateProductStaging::stageKiwify($pdo, $account, [$product]);
AffiliateProductReview::saveKiwify($pdo, $account, 'kiwify-101', [
    'description' => $review['review_description'], 'price' => $review['review_price'],
    'original_price' => $review['review_original_price'], 'checkout_url' => $review['review_checkout_url'],
    'image_url' => $review['review_image_url'], 'store_id' => '77',
]);
try {
    AffiliateProductPublication::publishKiwify($pdo, 'kiwify-test-account-b', 'kiwify-101');
    throw new RuntimeException('Permitiu publicar produto de outra conta.');
} catch (DomainException $expected) {}
checkPublication((int) $pdo->query('SELECT COUNT(*) FROM produtos')->fetchColumn() === 0, 'falha não publica produto');
$id = AffiliateProductPublication::publishKiwify($pdo, $account, 'kiwify-101');
checkPublication($id > 0, 'produto publicado');
$row = $pdo->query('SELECT * FROM produtos WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
checkPublication($row['nome'] === $product['name'] && $row['preco'] === '39.90'
    && $row['preco_original'] === '49.90'
    && $row['link_afiliado'] === $review['review_checkout_url']
    && json_decode($row['imagens'], true) === [$review['review_image_url']]
    && (int) $row['ativo'] === 1, 'dados publicados corretamente');
checkPublication(AffiliateProductPublication::publicationMap($pdo, $account)['kiwify-101'] === $id, 'mapa da conta');
checkPublication(AffiliateProductPublication::publicationMap($pdo, 'kiwify-test-account-b') === [], 'isolamento de mapa');
try {
    AffiliateProductPublication::publishKiwify($pdo, $account, 'kiwify-101');
    throw new RuntimeException('Permitiu publicação duplicada.');
} catch (DomainException $expected) {}
AffiliateProductStaging::stageKiwify($pdo, $account, [$product]);
checkPublication(AffiliateProductPublication::publicationMap($pdo, $account)['kiwify-101'] === $id, 'reimportação preserva publicação');
checkPublication((int) $pdo->query('SELECT COUNT(*) FROM produtos')->fetchColumn() === 1, 'publicação única');
$pdo->exec('DELETE FROM affiliate_product_staging');
$pdo->exec('DELETE FROM produtos');
$pdo->exec('DELETE FROM lojas WHERE id = 77');
echo "Publicação: validações, transação MySQL, idempotência e isolamento aprovados.\n";
