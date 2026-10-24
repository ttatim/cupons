<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/affiliate_staging_publisher.php';
function checkPublish(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException('Falhou: ' . $label); }
}
foreach (['http://example.com/checkout', 'https://localhost/x', 'https://127.0.0.1/x',
    'https://user:pass@example.com/x', 'https://example.com:8080/x',
    'javascript:alert(1)', 'https://example.com/' . "\r\n" . 'header'] as $url) {
    try {
        AffiliateStagingPublisher::validateCheckoutUrl($url);
        throw new RuntimeException('URL insegura aceita: ' . $url);
    } catch (InvalidArgumentException $expected) {}
}
checkPublish(AffiliateStagingPublisher::validateCheckoutUrl('https://checkout.example.com/pay?ref=abc') === 'https://checkout.example.com/pay?ref=abc', 'checkout válido');
foreach (['0', '-1', '1,00', '1.234', '100000000', 'abc'] as $price) {
    try {
        AffiliateStagingPublisher::validatePrice($price);
        throw new RuntimeException('Preço inválido aceito: ' . $price);
    } catch (InvalidArgumentException $expected) {}
}
checkPublish(AffiliateStagingPublisher::validatePrice('19.9') === '19.90', 'preço normalizado');
$dsn = (string) getenv('TEST_DB_DSN');
if ($dsn === '') {
    echo "Publicação Kiwify: validação de preço/checkout aprovada; MySQL não executado.\n";
    exit(0);
}
if (!str_contains($dsn, 'dbname=cupons_test')) {
    throw new RuntimeException('Banco de teste obrigatório: cupons_test.');
}
$pdo = new PDO($dsn, (string) getenv('TEST_DB_USER'), (string) getenv('TEST_DB_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec('CREATE TABLE IF NOT EXISTS lojas (
    id INT PRIMARY KEY AUTO_INCREMENT, nome VARCHAR(100) NOT NULL, ativo BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE IF NOT EXISTS produtos (
    id INT PRIMARY KEY AUTO_INCREMENT, loja_id INT NOT NULL, nome VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL, descricao TEXT NOT NULL, preco DECIMAL(10,2) NOT NULL,
    link_afiliado TEXT NOT NULL, imagens JSON, ativo BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB');
$pdo->exec((string) file_get_contents(__DIR__ . '/../sql/updates/20261024_affiliate_product_staging.sql'));
$pdo->exec((string) file_get_contents(__DIR__ . '/../sql/updates/20261024_affiliate_staging_publication.sql'));
$pdo->exec('DELETE FROM affiliate_product_staging');
$pdo->exec('DELETE FROM produtos');
$pdo->exec('DELETE FROM lojas');
$pdo->exec("INSERT INTO lojas (nome, ativo) VALUES ('Loja teste', 1), ('Inativa', 0)");
$storeId = (int) $pdo->query('SELECT MIN(id) FROM lojas')->fetchColumn();
$inactiveId = (int) $pdo->query('SELECT MAX(id) FROM lojas')->fetchColumn();
$product = ['id' => 'ext-101', 'name' => 'Produto para revisão', 'status' => 'active',
    'currency' => 'BRL', 'buyer' => ['email' => 'never-store@example.invalid']];
AffiliateProductStaging::stageKiwify($pdo, 'account-kiwify-a', [$product]);
$desc = 'Descrição editorial conferida para publicação manual.';
$checkout = 'https://checkout.example.com/pagar?ref=afiliado';
try {
    AffiliateStagingPublisher::publishKiwify($pdo, 'account-kiwify-b', 'ext-101', $storeId, $desc, '49.90', $checkout);
    throw new RuntimeException('Aceitou produto de outra conta.');
} catch (DomainException $expected) {}
try {
    AffiliateStagingPublisher::publishKiwify($pdo, 'account-kiwify-a', 'ext-101', $inactiveId, $desc, '49.90', $checkout);
    throw new RuntimeException('Aceitou loja inativa.');
} catch (DomainException $expected) {}
checkPublish((int) $pdo->query('SELECT COUNT(*) FROM produtos')->fetchColumn() === 0, 'falhas não criam produto');
$id = AffiliateStagingPublisher::publishKiwify($pdo, 'account-kiwify-a', 'ext-101', $storeId, $desc, '49.90', $checkout);
checkPublish($id > 0, 'publicação gerou id');
$row = $pdo->query('SELECT nome, descricao, preco, link_afiliado, imagens, ativo FROM produtos WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
checkPublish($row['nome'] === 'Produto para revisão' && $row['descricao'] === $desc
    && $row['preco'] === '49.90' && $row['link_afiliado'] === $checkout
    && $row['imagens'] === '[]' && (int) $row['ativo'] === 1, 'dados revisados persistidos');
$rows = AffiliateProductStaging::listKiwify($pdo, 'account-kiwify-a');
checkPublish((int) $rows[0]['published_product_id'] === $id, 'staging marcado publicado');
try {
    AffiliateStagingPublisher::publishKiwify($pdo, 'account-kiwify-a', 'ext-101', $storeId, $desc, '49.90', $checkout);
    throw new RuntimeException('Permitiu duplicação.');
} catch (DomainException $expected) {}
AffiliateProductStaging::stageKiwify($pdo, 'account-kiwify-a', [$product]);
checkPublish((int) AffiliateProductStaging::listKiwify($pdo, 'account-kiwify-a')[0]['published_product_id'] === $id, 'reimportação preserva publicação');
$product['id'] = 'ext-usd';
$product['currency'] = 'USD';
AffiliateProductStaging::stageKiwify($pdo, 'account-kiwify-a', [$product]);
try {
    AffiliateStagingPublisher::publishKiwify($pdo, 'account-kiwify-a', 'ext-usd', $storeId, $desc, '49.90', $checkout);
    throw new RuntimeException('Aceitou moeda diferente de BRL.');
} catch (DomainException $expected) {}
checkPublish((int) $pdo->query('SELECT COUNT(*) FROM produtos')->fetchColumn() === 1, 'nenhuma duplicação');
echo "Publicação Kiwify: validações, MySQL, isolamento, transação e antiduplificação aprovados.\n";
