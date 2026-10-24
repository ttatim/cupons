<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/offer_metadata_importer.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}
header('X-Content-Type-Options: nosniff');

$csrf = Security::generateCSRFToken();
$errors = [];
$success = '';
$preview = null;
$values = [
    'tipo' => 'produto', 'url_origem' => '', 'link_destino' => '',
    'nome' => '', 'descricao' => '', 'preco' => '', 'preco_original' => '',
    'imagem' => '', 'codigo' => '', 'tipo_desconto' => 'percentual',
    'valor_desconto' => '', 'data_fim' => date('Y-m-d', strtotime('+30 days')),
    'loja_id' => '',
];
$lojas = $pdo->query('SELECT id, nome FROM lojas WHERE ativo = 1 ORDER BY nome')->fetchAll(PDO::FETCH_ASSOC);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $key => $value) {
        $values[$key] = trim((string)($_POST[$key] ?? $value));
    }
    try {
        if (!Security::validateCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
        }
        if (!in_array($values['tipo'], ['produto', 'cupom'], true)) {
            throw new InvalidArgumentException('Tipo de anúncio inválido.');
        }
        $action = (string)($_POST['acao'] ?? '');
        if ($action === 'preview') {
            $values['url_origem'] = OfferMetadataImporter::validatePublicUrl($values['url_origem']);
            try {
                $preview = (new OfferMetadataImporter())->import($values['url_origem']);
                $values['nome'] = $preview['title'] ?: $values['nome'];
                $values['descricao'] = $preview['description'] ?: $values['descricao'];
                $values['imagem'] = $preview['image'] ?: $values['imagem'];
                if ($values['tipo'] === 'produto') {
                    $values['preco'] = $preview['price'] ?: $values['preco'];
                }
                if ($preview['manual_required'] || $values['tipo'] === 'cupom') {
                    $errors[] = 'Confira os campos e complete manualmente os dados não encontrados.';
                }
            } catch (Throwable $e) {
                $errors[] = 'Não foi possível obter os metadados. Preencha os campos manualmente.';
                error_log('[importador] ' . $e->getMessage());
            }
            $values['link_destino'] = $values['link_destino'] ?: $values['url_origem'];
        } elseif ($action === 'save') {
            $destination = OfferMetadataImporter::validatePublicUrl($values['link_destino']);
            $storeId = filter_var($values['loja_id'], FILTER_VALIDATE_INT);
            if (!$storeId || !in_array($storeId, array_map('intval', array_column($lojas, 'id')), true)) {
                throw new InvalidArgumentException('Selecione uma loja válida.');
            }
            $name = mb_substr(strip_tags($values['nome']), 0, 255);
            $description = mb_substr(strip_tags($values['descricao']), 0, 1500);
            if ($name === '' || $description === '') {
                throw new InvalidArgumentException('Informe o título e a descrição.');
            }
            if ($values['tipo'] === 'produto') {
                $price = filter_var($values['preco'], FILTER_VALIDATE_FLOAT);
                $original = $values['preco_original'] === '' ? null : filter_var($values['preco_original'], FILTER_VALIDATE_FLOAT);
                if ($price === false || $price < 0 || ($original !== null && ($original === false || $original < 0))) {
                    throw new InvalidArgumentException('Informe preços válidos.');
                }
                $image = $values['imagem'];
                if ($image !== '' && (strlen($image) > 2048 || !str_starts_with($image, 'https://'))) {
                    $image = '';
                }
                $slug = 'oferta-' . bin2hex(random_bytes(8));
                $stmt = $pdo->prepare('INSERT INTO produtos (loja_id, nome, slug, descricao, preco, preco_original, link_afiliado, imagens) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$storeId, $name, $slug, $description, $price, $original, $destination, json_encode($image !== '' ? [$image] : [], JSON_UNESCAPED_SLASHES)]);
                $success = 'Produto cadastrado. O botão público levará ao checkout informado.';
            } else {
                $discount = filter_var($values['valor_desconto'], FILTER_VALIDATE_FLOAT);
                if (!in_array($values['tipo_desconto'], ['percentual', 'valor'], true)
                    || $discount === false || $discount <= 0
                    || ($values['tipo_desconto'] === 'percentual' && $discount > 100)) {
                    throw new InvalidArgumentException('Informe um desconto válido.');
                }
                $expiry = DateTimeImmutable::createFromFormat('!Y-m-d', $values['data_fim']);
                if (!$expiry || $expiry->format('Y-m-d') !== $values['data_fim'] || $expiry < new DateTimeImmutable('today')) {
                    throw new InvalidArgumentException('Informe uma data de validade futura.');
                }
                $stmt = $pdo->prepare('INSERT INTO cupons (loja_id, tipo, valor, descricao, detalhes, codigo, link_afiliado, data_inicio, data_fim) VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), ?)');
                $stmt->execute([$storeId, $values['tipo_desconto'], $discount, $name, $description, mb_substr($values['codigo'], 0, 100), $destination, $values['data_fim']]);
                $success = 'Cupom cadastrado. O botão público levará ao link informado.';
            }
        } else {
            throw new InvalidArgumentException('Ação inválida.');
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}
function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Importar oferta | Cupons</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container py-4" style="max-width:960px">
<div class="d-flex justify-content-between align-items-center mb-4"><h1 class="h3">Importar produto ou cupom</h1><a href="dashboard.php" class="btn btn-outline-secondary">Voltar ao painel</a></div>
<p class="text-muted">Cole a URL de uma página pública HTTPS para buscar título, descrição, imagem e preço. Caso o site bloqueie a leitura, complete os dados manualmente. Confirme sempre o link final de pagamento ou resgate.</p>
<?php foreach ($errors as $error): ?><div class="alert alert-warning"><?= h($error) ?></div><?php endforeach; ?>
<?php if ($success !== ''): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>
<form method="post" class="card shadow-sm"><div class="card-body row g-3">
<input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
<div class="col-md-4"><label class="form-label">Tipo</label><select class="form-select" name="tipo" id="tipo"><option value="produto" <?= $values['tipo'] === 'produto' ? 'selected' : '' ?>>Produto</option><option value="cupom" <?= $values['tipo'] === 'cupom' ? 'selected' : '' ?>>Cupom</option></select></div>
<div class="col-md-8"><label class="form-label">Loja</label><select class="form-select" name="loja_id" required><option value="">Selecione</option><?php foreach ($lojas as $loja): ?><option value="<?= (int)$loja['id'] ?>" <?= (string)$loja['id'] === $values['loja_id'] ? 'selected' : '' ?>><?= h($loja['nome']) ?></option><?php endforeach; ?></select></div>
<div class="col-12"><label class="form-label">URL para importar metadados</label><input type="url" class="form-control" name="url_origem" value="<?= h($values['url_origem']) ?>" placeholder="https://loja.com/produto" maxlength="2048"></div>
<div class="col-12"><button type="submit" name="acao" value="preview" formnovalidate class="btn btn-outline-primary">Buscar informações da URL</button></div>
<hr>
<div class="col-12"><label class="form-label">Título</label><input type="text" class="form-control" name="nome" maxlength="255" required value="<?= h($values['nome']) ?>"></div>
<div class="col-12"><label class="form-label">Descrição (até 1500 caracteres)</label><textarea class="form-control" name="descricao" maxlength="1500" rows="4" required><?= h($values['descricao']) ?></textarea></div>
<div class="col-md-6"><label class="form-label">Preço do produto (R$)</label><input type="number" step="0.01" min="0" class="form-control" name="preco" value="<?= h($values['preco']) ?>"></div>
<div class="col-md-6"><label class="form-label">Preço original (R$)</label><input type="number" step="0.01" min="0" class="form-control" name="preco_original" value="<?= h($values['preco_original']) ?>"></div>
<div class="col-12"><label class="form-label">URL da imagem principal (HTTPS)</label><input type="url" class="form-control" name="imagem" value="<?= h($values['imagem']) ?>" maxlength="2048"><?php if ($values['imagem'] !== '' && str_starts_with($values['imagem'], 'https://')): ?><img class="img-thumbnail mt-2" style="max-height:150px" src="<?= h($values['imagem']) ?>" alt="Prévia da imagem"><?php endif; ?></div>
<div class="col-md-4"><label class="form-label">Tipo do desconto (cupom)</label><select class="form-select" name="tipo_desconto"><option value="percentual">Percentual (%)</option><option value="valor" <?= $values['tipo_desconto'] === 'valor' ? 'selected' : '' ?>>Valor (R$)</option></select></div>
<div class="col-md-4"><label class="form-label">Valor do desconto (cupom)</label><input type="number" min="0.01" step="0.01" class="form-control" name="valor_desconto" value="<?= h($values['valor_desconto']) ?>"></div>
<div class="col-md-4"><label class="form-label">Código (cupom, se houver)</label><input type="text" maxlength="100" class="form-control" name="codigo" value="<?= h($values['codigo']) ?>"></div>
<div class="col-md-6"><label class="form-label">Validade do cupom</label><input type="date" class="form-control" name="data_fim" value="<?= h($values['data_fim']) ?>"></div>
<div class="col-12"><label class="form-label fw-bold">URL final de pagamento ou resgate</label><input type="url" class="form-control" name="link_destino" maxlength="2048" required value="<?= h($values['link_destino']) ?>" placeholder="https://checkout.exemplo.com/..."><div class="form-text">Para produtos, será utilizada pelo botão "ir para pagamento". Para cupons, pelo botão "Pegue seu cupom".</div></div>
<div class="col-12"><button type="submit" name="acao" value="save" class="btn btn-primary">Salvar anúncio</button></div>
</div></form>
</main>
</body></html>
