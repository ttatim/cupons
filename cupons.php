<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

$categoria = $_GET['categoria'] ?? '';
$loja = $_GET['loja'] ?? '';
$pagina = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$limite = 20;
$offset = ($pagina - 1) * $limite;

// Construir query base
$where_conditions = ["c.ativo = 1", "c.data_fim >= CURDATE()"];
$params = [];

if (!empty($categoria)) {
    $where_conditions[] = "c.categoria = ?";
    $params[] = $categoria;
}

if (!empty($loja)) {
    $where_conditions[] = "l.slug = ?";
    $params[] = $loja;
}

$where_sql = implode(' AND ', $where_conditions);

// Buscar cupons
$stmt = $pdo->prepare("
    SELECT c.*, l.nome as loja_nome, l.slug as loja_slug, l.logo as loja_logo
    FROM cupons c 
    JOIN lojas l ON c.loja_id = l.id 
    WHERE $where_sql
    ORDER BY c.destaque DESC, c.created_at DESC 
    LIMIT ? OFFSET ?
");
$params[] = $limite;
$params[] = $offset;
$stmt->execute($params);
$cupons = $stmt->fetchAll();

// Buscar categorias
$stmt = $pdo->query("
    SELECT DISTINCT categoria 
    FROM cupons 
    WHERE categoria IS NOT NULL AND categoria != '' 
    ORDER BY categoria
");
$categorias = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Buscar lojas com cupons ativos
$stmt = $pdo->query("
    SELECT l.*, COUNT(c.id) as total_cupons 
    FROM lojas l 
    JOIN cupons c ON l.id = c.loja_id 
    WHERE c.ativo = 1 AND c.data_fim >= CURDATE()
    GROUP BY l.id 
    ORDER BY total_cupons DESC
");
$lojas = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cupons de Desconto - <?= SITE_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <main class="container my-4">
        <!-- Page Header -->
        <div class="row mb-4">
            <div class="col-md-8">
                <h1 class="h2 mb-2">
                    <i class="bi bi-ticket-perforated me-2"></i>Cupons de Desconto
                </h1>
                <p class="text-muted">
                    Encontre os melhores cupons de desconto para economizar em suas compras
                </p>
            </div>
            <div class="col-md-4 text-end">
                <div class="btn-group">
                    <button type="button" class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="bi bi-funnel me-1"></i>Filtrar
                    </button>
                    <ul class="dropdown-menu">
                        <li><h6 class="dropdown-header">Categorias</h6></li>
                        <li><a class="dropdown-item" href="cupons.php">Todas as Categorias</a></li>
                        <?php foreach ($categorias as $cat): ?>
                            <li><a class="dropdown-item" href="cupons.php?categoria=<?= urlencode($cat) ?>"><?= htmlspecialchars($cat) ?></a></li>
                        <?php endforeach; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">Lojas</h6></li>
                        <?php foreach ($lojas as $loja_item): ?>
                            <li><a class="dropdown-item" href="cupons.php?loja=<?= $loja_item['slug'] ?>">
                                <?= htmlspecialchars($loja_item['nome']) ?> (<?= $loja_item['total_cupons'] ?>)
                            </a></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Filtros Ativos -->
        <?php if (!empty($categoria) || !empty($loja)): ?>
        <div class="alert alert-info d-flex justify-content-between align-items-center">
            <div>
                <strong>Filtros ativos:</strong>
                <?php if (!empty($categoria)): ?>
                    <span class="badge bg-primary me-2">Categoria: <?= htmlspecialchars($categoria) ?></span>
                <?php endif; ?>
                <?php if (!empty($loja)): ?>
                    <span class="badge bg-success">Loja: <?= htmlspecialchars($loja) ?></span>
                <?php endif; ?>
            </div>
            <a href="cupons.php" class="btn btn-sm btn-outline-info">Limpar Filtros</a>
        </div>
        <?php endif; ?>

        <!-- Lista de Cupons -->
        <div class="row">
            <?php if (empty($cupons)): ?>
                <div class="col-12">
                    <div class="text-center py-5">
                        <i class="bi bi-ticket-perforated display-1 text-muted"></i>
                        <h3 class="mt-3 text-muted">Nenhum cupom encontrado</h3>
                        <p class="text-muted">Tente ajustar os filtros ou verifique novamente mais tarde.</p>
                        <a href="cupons.php" class="btn btn-primary">Ver Todos os Cupons</a>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($cupons as $cupom): 
                    $desconto = $cupom['tipo'] === 'percentual' ? $cupom['valor'] . '%' : 'R$ ' . number_format($cupom['valor'], 2, ',', '.');
                    $dias_restantes = floor((strtotime($cupom['data_fim']) - time()) / (60 * 60 * 24));
                ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card offer-card h-100 <?= $cupom['destaque'] ? 'featured' : '' ?>">
                        <?php if ($cupom['destaque']): ?>
                            <div class="featured-badge">
                                <i class="bi bi-star-fill me-1"></i>Destaque
                            </div>
                        <?php endif; ?>
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="badge bg-success discount-badge"><?= $desconto ?> OFF</span>
                                <small class="text-muted">
                                    <i class="bi bi-clock me-1"></i>
                                    <?= $dias_restantes <= 0 ? 'Hoje' : ($dias_restantes . ' dia' . ($dias_restantes != 1 ? 's' : '')) ?>
                                </small>
                            </div>
                            <h6 class="card-title"><?= htmlspecialchars($cupom['descricao']) ?></h6>
                            <?php if ($cupom['detalhes']): ?>
                                <p class="card-text small text-muted"><?= htmlspecialchars($cupom['detalhes']) ?></p>
                            <?php endif; ?>
                            <div class="store-info mb-3">
                                <?php if ($cupom['loja_logo']): ?>
                                    <img src="assets/uploads/lojas/<?= $cupom['loja_logo'] ?>" alt="<?= htmlspecialchars($cupom['loja_nome']) ?>" class="store-logo-sm me-2">
                                <?php endif; ?>
                                <?= htmlspecialchars($cupom['loja_nome']) ?>
                            </div>
                            <div class="d-flex justify-content-between align-items-center">
                                <code class="cupom-code"><?= htmlspecialchars($cupom['codigo']) ?></code>
                                <button class="btn btn-sm btn-outline-primary" onclick="copiarCodigo('<?= $cupom['codigo'] ?>')">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                        </div>
                        <div class="card-footer">
                            <button class="btn btn-red w-100" onclick="abrirCupom(<?= $cupom['id'] ?>)">
                                <i class="bi bi-arrow-right-circle me-1"></i>Me leve para a loja
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Paginação -->
        <?php if (count($cupons) >= $limite): ?>
        <nav aria-label="Navegação de cupons" class="mt-4">
            <ul class="pagination justify-content-center">
                <?php if ($pagina > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina - 1])) ?>">
                            <i class="bi bi-chevron-left"></i> Anterior
                        </a>
                    </li>
                <?php endif; ?>
                
                <li class="page-item active">
                    <span class="page-link"><?= $pagina ?></span>
                </li>
                
                <li class="page-item">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina + 1])) ?>">
                        Próxima <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
            </ul>
        </nav>
        <?php endif; ?>
    </main>

    <?php include 'includes/footer.php'; ?>

    <!-- Modal Cupom -->
    <div class="modal fade" id="cupomModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Detalhes do Cupom</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalCupomContent">
                    <!-- Conteúdo carregado via AJAX -->
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/main.js"></script>
</body>
</html>