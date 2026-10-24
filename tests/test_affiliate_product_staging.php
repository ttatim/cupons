<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/affiliate_product_staging.php';

function assertStaging(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException('Falhou: ' . $label);
    }
}
$product = [
    'id' => 'external-123',
    'name' => '  Oferta de teste  ',
    'status' => 'active',
    'currency' => 'brl',
    'checkout_url' => 'https://example.invalid/?secret=never-save',
    'buyer' => ['email' => 'private@example.invalid'],
];
$normalized = AffiliateProductStaging::normalizeKiwify($product);
assertStaging($normalized === [
    'external_id' => 'external-123',
    'name' => 'Oferta de teste',
    'status' => 'active',
    'currency' => 'BRL',
], 'somente campos permitidos');
foreach ([
    ['name' => 'sem id'],
    ['id' => '1', 'name' => ''],
    ['id' => "bad\r\nheader", 'name' => 'Teste'],
] as $bad) {
    try {
        AffiliateProductStaging::normalizeKiwify($bad);
        throw new RuntimeException('Aceitou produto inválido');
    } catch (InvalidArgumentException $expected) {
        // OK.
    }
}
assertStaging(AffiliateProductStaging::normalizeKiwify(['id' => 7, 'name' => 'Produto', 'currency' => '???'])['currency'] === null, 'moeda inválida não persistida');

$dsn = (string) getenv('TEST_DB_DSN');
if ($dsn === '') {
    echo "Staging: normalização aprovada; integração MySQL não executada (TEST_DB_DSN ausente).\n";
    exit(0);
}
if (!str_contains($dsn, 'dbname=cupons_test')) {
    throw new RuntimeException('Banco de teste obrigatório: cupons_test.');
}
$pdo = new PDO($dsn, (string) getenv('TEST_DB_USER'), (string) getenv('TEST_DB_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$pdo->exec("CREATE TABLE IF NOT EXISTS lojas (id INT PRIMARY KEY, nome VARCHAR(100) NOT NULL) ENGINE=InnoDB");
$pdo->exec((string) file_get_contents(__DIR__ . '/../sql/updates/20261024_affiliate_product_staging.sql'));
$column = $pdo->query("SHOW COLUMNS FROM affiliate_product_staging LIKE 'review_description'")->fetch(PDO::FETCH_ASSOC);
if ($column === false) {
    $pdo->exec((string) file_get_contents(__DIR__ . '/../sql/updates/20261024_affiliate_product_review.sql'));
}
$pdo->exec('DELETE FROM affiliate_product_staging');
$result = AffiliateProductStaging::stageKiwify($pdo, 'test-account-a', [$product, ['id' => '', 'name' => 'Inválido']]);
assertStaging($result === ['processed' => 1, 'rejected' => 1], 'contagem de importação');
$rows = AffiliateProductStaging::listKiwify($pdo, 'test-account-a');
assertStaging(count($rows) === 1 && $rows[0]['name'] === 'Oferta de teste', 'importação para revisão');
assertStaging(AffiliateProductStaging::listKiwify($pdo, 'test-account-b') === [], 'isolamento entre contas');
$product['name'] = 'Nome atualizado';
AffiliateProductStaging::stageKiwify($pdo, 'test-account-a', [$product]);
$rows = AffiliateProductStaging::listKiwify($pdo, 'test-account-a');
assertStaging(count($rows) === 1 && $rows[0]['name'] === 'Nome atualizado', 'upsert idempotente');
try {
    AffiliateProductStaging::stageKiwify($pdo, 'test-account-a', array_fill(0, 51, $product));
    throw new RuntimeException('Aceitou lote com mais de 50 produtos');
} catch (InvalidArgumentException $expected) {
    // OK.
}
try {
    AffiliateProductStaging::listKiwify($pdo, 'bad account');
    throw new RuntimeException('Aceitou identificador de conta inválido');
} catch (InvalidArgumentException $expected) {
    // OK.
}
echo "Staging: normalização, MySQL, upsert e isolamento aprovados.\n";
