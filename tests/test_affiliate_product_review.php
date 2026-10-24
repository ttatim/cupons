<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/affiliate_product_review.php';
require_once __DIR__ . '/../includes/affiliate_product_staging.php';

function assertReview(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('Falhou: ' . $message);
    }
}

$input = [
    'description' => 'Produto em revisão',
    'price' => '29.9',
    'original_price' => '39.90',
    'checkout_url' => 'https://checkout.example.com/pay?id=123',
    'image_url' => 'https://cdn.example.com/image.jpg',
    'store_id' => '12',
];
$normalized = AffiliateProductReview::validate($input);
assertReview($normalized['price'] === '29.90', 'preço normalizado');
assertReview($normalized['original_price'] === '39.90', 'preço original');
assertReview($normalized['store_id'] === 12, 'loja numérica');
assertReview($normalized['checkout_url'] === $input['checkout_url'], 'checkout HTTPS');
foreach ([
    ['price' => '-1'],
    ['price' => '100000000.00'],
    ['price' => '12,50'],
    ['price' => '0'],
    ['price' => '30.00', 'original_price' => '29.99'],
    ['description' => str_repeat('x', 1501)],
    ['checkout_url' => 'http://example.com/'],
    ['checkout_url' => 'javascript:alert(1)'],
    ['checkout_url' => 'https://127.0.0.1/private'],
    ['checkout_url' => 'https://localhost/admin'],
    ['checkout_url' => 'https://user:password@example.com/'],
    ['checkout_url' => "https://example.com/\r\nHeader: bad"],
    ['image_url' => 'https://192.168.1.2/file.jpg'],
    ['store_id' => '1 OR 1=1'],
    ['store_id' => ['12']],
] as $invalid) {
    try {
        AffiliateProductReview::validate($invalid);
        throw new RuntimeException('Aceitou entrada inválida: ' . json_encode($invalid));
    } catch (InvalidArgumentException $expected) {
        // Entrada rejeitada.
    }
}
assertReview(AffiliateProductReview::validate([])['checkout_url'] === null, 'campos opcionais');

$dsn = (string) getenv('TEST_DB_DSN');
if ($dsn === '') {
    echo "Review: validação aprovada; MySQL não executado (TEST_DB_DSN ausente).\n";
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
$pdo->exec((string) file_get_contents(__DIR__ . '/../sql/updates/20261024_affiliate_product_staging.sql'));
$column = $pdo->query("SHOW COLUMNS FROM affiliate_product_staging LIKE 'review_description'")->fetch(PDO::FETCH_ASSOC);
if ($column === false) {
    $pdo->exec((string) file_get_contents(__DIR__ . '/../sql/updates/20261024_affiliate_product_review.sql'));
}
$pdo->exec('DELETE FROM affiliate_product_staging');
$pdo->exec("INSERT INTO lojas (id, nome) VALUES (12, 'Loja de teste') ON DUPLICATE KEY UPDATE nome = VALUES(nome)");
AffiliateProductStaging::stageKiwify($pdo, 'test-account-a', [['id' => 'review-1', 'name' => 'Produto original']]);
AffiliateProductReview::saveKiwify($pdo, 'test-account-a', 'review-1', $input);
$rows = AffiliateProductStaging::listKiwify($pdo, 'test-account-a');
assertReview(count($rows) === 1, 'um registro revisado');
assertReview($rows[0]['review_description'] === 'Produto em revisão', 'descrição persistida');
assertReview($rows[0]['review_price'] === '29.90', 'preço persistido');
assertReview((int) $rows[0]['review_store_id'] === 12, 'loja persistida');
assertReview($rows[0]['review_checkout_url'] === $input['checkout_url'], 'checkout persistido');
try {
    AffiliateProductReview::saveKiwify($pdo, 'test-account-b', 'review-1', $input);
    throw new RuntimeException('Permitiu editar registro de outra conta.');
} catch (DomainException $expected) {
    // Isolamento confirmado.
}
try {
    AffiliateProductReview::saveKiwify($pdo, 'test-account-a', 'review-1', array_merge($input, ['store_id' => '999999']));
    throw new RuntimeException('Aceitou loja inexistente.');
} catch (InvalidArgumentException $expected) {
    // Rollback confirmado.
}
AffiliateProductStaging::stageKiwify($pdo, 'test-account-a', [['id' => 'review-1', 'name' => 'Nome atualizado']]);
$rows = AffiliateProductStaging::listKiwify($pdo, 'test-account-a');
assertReview($rows[0]['name'] === 'Nome atualizado', 'nome da API atualizado');
assertReview($rows[0]['review_price'] === '29.90', 'revisão preservada no upsert');
$pdo->exec('DELETE FROM affiliate_product_staging');
$pdo->exec('DELETE FROM lojas WHERE id = 12');
echo "Review: validação, MySQL, revisão, rollback e isolamento aprovados.\n";
