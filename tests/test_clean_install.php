<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
function ensure(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
ensure((int)$pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() === 0, 'Instalação criou usuário padrão.');
ensure((int)$pdo->query('SELECT COUNT(*) FROM lojas')->fetchColumn() === 0, 'Instalação criou lojas de demonstração.');
$pdo->prepare('INSERT INTO lojas (nome,slug,ativo) VALUES (?,?,1)')->execute(['Loja Teste','loja-teste']);
$lojaId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO produtos (loja_id,nome,slug,descricao,preco,link_afiliado,imagens) VALUES (?,?,?,?,?,?,?)')
    ->execute([$lojaId,'Produto de teste','produto-teste','Descrição de teste',99.90,'https://example.com/checkout','[]']);
$pdo->prepare('INSERT INTO cupons (loja_id,tipo,valor,descricao,codigo,link_afiliado,data_inicio,data_fim) VALUES (?,?,?,?,?,?,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 10 DAY))')
    ->execute([$lojaId,'percentual',10,'Cupom de teste','TESTE10','https://example.com/cupom']);
ob_start();
require __DIR__ . '/../index.php';
$html = ob_get_clean();
ensure(str_contains($html, 'ir para pagamento'), 'Botão de pagamento não renderizado.');
ensure(str_contains($html, 'Pegue seu cupom'), 'Botão de cupom não renderizado.');
ensure(str_contains($html, 'Produto de teste'), 'Produto não renderizado.');
ensure(str_contains($html, 'Cupom de teste'), 'Cupom não renderizado.');
$produtoId = (int)$pdo->query('SELECT id FROM produtos ORDER BY id DESC LIMIT 1')->fetchColumn();
$_GET['id'] = (string)$produtoId;
ob_start();
require __DIR__ . '/../oferta.php';
$offerHtml = ob_get_clean();
unset($_GET['id']);
ensure(str_contains($offerHtml, 'Produto de teste'), 'Página de oferta não renderizou o produto.');
ensure(str_contains($offerHtml, 'go.php?tipo=produto&amp;id=' . $produtoId), 'Página de oferta não aponta ao redirecionamento rastreado.');
echo "Instalação limpa, esquema e vitrine validados.\n";
