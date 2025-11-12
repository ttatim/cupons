<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// Configurações de período
$periodo = isset($_GET['periodo']) ? $_GET['periodo'] : '7d';
$data_inicio = isset($_GET['data_inicio']) ? $_GET['data_inicio'] : '';
$data_fim = isset($_GET['data_fim']) ? $_GET['data_fim'] : '';

// Definir período padrão
switch ($periodo) {
    case 'hoje':
        $data_inicio = date('Y-m-d');
        $data_fim = date('Y-m-d');
        break;
    case '7d':
        $data_inicio = date('Y-m-d', strtotime('-7 days'));
        $data_fim = date('Y-m-d');
        break;
    case '30d':
        $data_inicio = date('Y-m-d', strtotime('-30 days'));
        $data_fim = date('Y-m-d');
        break;
    case '90d':
        $data_inicio = date('Y-m-d', strtotime('-90 days'));
        $data_fim = date('Y-m-d');
        break;
    case 'personalizado':
        if (empty($data_inicio) || empty($data_fim)) {
            $data_inicio = date('Y-m-d', strtotime('-7 days'));
            $data_fim = date('Y-m-d');
        }
        break;
    default:
        $data_inicio = date('Y-m-d', strtotime('-7 days'));
        $data_fim = date('Y-m-d');
        break;
}

// Buscar estatísticas gerais
$stmt = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT CASE WHEN tipo = 'cupom' THEN item_id END) as total_cupons,
        COUNT(DISTINCT CASE WHEN tipo = 'produto' THEN item_id END) as total_produtos,
        SUM(cliques) as total_cliques,
        SUM(conversoes) as total_conversoes,
        AVG(conversoes) as taxa_conversao
    FROM estatisticas 
    WHERE data_acesso BETWEEN ? AND ?
");
$stmt->execute([$data_inicio, $data_fim]);
$estatisticas_gerais = $stmt->fetch();

// Buscar evolução diária
$stmt = $pdo->prepare("
    SELECT 
        data_acesso as data,
        SUM(cliques) as cliques,
        SUM(conversoes) as conversoes,
        COUNT(DISTINCT item_id) as itens_unicos
    FROM estatisticas 
    WHERE data_acesso BETWEEN ? AND ?
    GROUP BY data_acesso 
    ORDER BY data_acesso
");
$stmt->execute([$data_inicio, $data_fim]);
$evolucao_diaria = $stmt->fetchAll();

// Top cupons
$stmt = $pdo->prepare("
    SELECT 
        c.id,
        c.descricao,
        l.nome as loja_nome,
        SUM(e.cliques) as total_cliques,
        SUM(e.conversoes) as total_conversoes,
        CASE 
            WHEN SUM(e.cliques) > 0 THEN ROUND((SUM(e.conversoes) / SUM(e.cliques)) * 100, 2)
            ELSE 0 
        END as taxa_conversao
    FROM estatisticas e
    JOIN cupons c ON e.item_id = c.id AND e.tipo = 'cupom'
    JOIN lojas l ON c.loja_id = l.id
    WHERE e.data_acesso BETWEEN ? AND ?
    GROUP BY c.id
    ORDER BY total_cliques DESC
    LIMIT 10
");
$stmt->execute([$data_inicio, $data_fim]);
$top_cupons = $stmt->fetchAll();

// Top produtos
$stmt = $pdo->prepare("
    SELECT 
        p.id,
        p.nome,
        l.nome as loja_nome,
        SUM(e.cliques) as total_cliques,
        SUM(e.conversoes) as total_conversoes,
        CASE 
            WHEN SUM(e.cliques) > 0 THEN ROUND((SUM(e.conversoes) / SUM(e.cliques)) * 100, 2)
            ELSE 0 
        END as taxa_conversao
    FROM estatisticas e
    JOIN produtos p ON e.item_id = p.id AND e.tipo = 'produto'
    JOIN lojas l ON p.loja_id = l.id
    WHERE e.data_acesso BETWEEN ? AND ?
    GROUP BY p.id
    ORDER BY total_cliques DESC
    LIMIT 10
");
$stmt->execute([$data_inicio, $data_fim]);
$top_produtos = $stmt->fetchAll();

// Top lojas
$stmt = $pdo->prepare("
    SELECT 
        l.id,
        l.nome,
        l.slug,
        SUM(e.cliques) as total_cliques,
        SUM(e.conversoes) as total_conversoes,
        COUNT(DISTINCT e.item_id) as itens_unicos,
        CASE 
            WHEN SUM(e.cliques) > 0 THEN ROUND((SUM(e.conversoes) / SUM(e.cliques)) * 100, 2)
            ELSE 0 
        END as taxa_conversao
    FROM estatisticas e
    LEFT JOIN cupons c ON e.item_id = c.id AND e.tipo = 'cupom'
    LEFT JOIN produtos p ON e.item_id = p.id AND e.tipo = 'produto'
    JOIN lojas l ON (c.loja_id = l.id OR p.loja_id = l.id)
    WHERE e.data_acesso BETWEEN ? AND ?
    GROUP BY l.id
    ORDER BY total_cliques DESC
    LIMIT 10
");
$stmt->execute([$data_inicio, $data_fim]);
$top_lojas = $stmt->fetchAll();

// Estatísticas por hora do dia
$stmt = $pdo->prepare("
    SELECT 
        HOUR(created_at) as hora,
        COUNT(*) as total_cliques
    FROM estatisticas 
    WHERE DATE(created_at) = CURDATE()
    GROUP BY HOUR(created_at)
    ORDER BY hora
");
$stmt->execute();
$cliques_por_hora = $stmt->fetchAll();

// Preparar dados para gráficos
$grafico_evolucao_labels = [];
$grafico_evolucao_cliques = [];
$grafico_evolucao_conversoes = [];

foreach ($evolucao_diaria as $dia) {
    $grafico_evolucao_labels[] = date('d/m', strtotime($dia['data']));
    $grafico_evolucao_cliques[] = $dia['cliques'];
    $grafico_evolucao_conversoes[] = $dia['conversoes'];
}

$grafico_horas_labels = [];
$grafico_horas_cliques = [];

for ($hora = 0; $hora < 24; $hora++) {
    $grafico_horas_labels[] = sprintf('%02d:00', $hora);
    $encontrado = false;
    foreach ($cliques_por_hora as $hora_data) {
        if ($hora_data['hora'] == $hora) {
            $grafico_horas_cliques[] = $hora_data['total_cliques'];
            $encontrado = true;
            break;
        }
    }
    if (!$encontrado) {
        $grafico_horas_cliques[] = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estatísticas - Administração</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .stats-card {
            text-align: center;
            padding: 20px;
        }
        .stats-number {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .stats-label {
            color: #6c757d;
            font-size: 0.9rem;
        }
        .stats-change {
            font-size: 0.8rem;
            font-weight: bold;
        }
        .stats-change.positive {
            color: #28a745;
        }
        .stats-change.negative {
            color: #dc3545;
        }
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        .table-responsive {
            border-radius: 10px;
        }
        .badge-cliques {
            background-color: #4169E1;
        }
        .badge-conversoes {
            background-color: #28a745;
        }
        .badge-taxa {
            background-color: #ffc107;
            color: #000;
        }
        .periodo-selector {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .nav-periodo .nav-link {
            border-radius: 20px;
            margin: 0 2px;
            padding: 5px 15px;
        }
        .nav-periodo .nav-link.active {
            background: #4169E1;
            color: white;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="bi bi-graph-up me-2"></i>Estatísticas e Relatórios
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button class="btn btn-outline-primary me-2" onclick="exportarRelatorio()">
                            <i class="bi bi-download me-1"></i>Exportar
                        </button>
                        <button class="btn btn-primary" onclick="atualizarEstatisticas()">
                            <i class="bi bi-arrow-clockwise me-1"></i>Atualizar
                        </button>
                    </div>
                </div>

                <!-- Seletor de Período -->
                <div class="card periodo-selector">
                    <div class="card-body">
                        <h6 class="card-title mb-3">Selecione o Período</h6>
                        <form method="GET" class="row g-3 align-items-end">
                            <div class="col-md-8">
                                <ul class="nav nav-pills nav-periodo">
                                    <li class="nav-item">
                                        <a class="nav-link <?= $periodo === 'hoje' ? 'active' : '' ?>" 
                                           href="?periodo=hoje">Hoje</a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link <?= $periodo === '7d' ? 'active' : '' ?>" 
                                           href="?periodo=7d">7 Dias</a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link <?= $periodo === '30d' ? 'active' : '' ?>" 
                                           href="?periodo=30d">30 Dias</a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link <?= $periodo === '90d' ? 'active' : '' ?>" 
                                           href="?periodo=90d">90 Dias</a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link <?= $periodo === 'personalizado' ? 'active' : '' ?>" 
                                           href="#" onclick="toggleCustomDate()">Personalizado</a>
                                    </li>
                                </ul>
                            </div>
                            <div class="col-md-4" id="customDateRange" style="display: <?= $periodo === 'personalizado' ? 'block' : 'none' ?>;">
                                <div class="row g-2">
                                    <div class="col-md-5">
                                        <input type="date" name="data_inicio" class="form-control form-control-sm" 
                                               value="<?= $data_inicio ?>" max="<?= date('Y-m-d') ?>">
                                    </div>
                                    <div class="col-md-5">
                                        <input type="date" name="data_fim" class="form-control form-control-sm" 
                                               value="<?= $data_fim ?>" max="<?= date('Y-m-d') ?>">
                                    </div>
                                    <div class="col-md-2">
                                        <button type="submit" class="btn btn-primary btn-sm w-100">
                                            <i class="bi bi-check"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <input type="hidden" name="periodo" value="personalizado" id="periodoHidden">
                        </form>
                    </div>
                </div>

                <!-- Cards de Estatísticas Gerais -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="stats-number text-primary">
                                    <?= number_format($estatisticas_gerais['total_cliques'] ?: 0) ?>
                                </div>
                                <div class="stats-label">Total de Cliques</div>
                                <div class="stats-change positive">
                                    <i class="bi bi-arrow-up"></i> 12%
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="stats-number text-success">
                                    <?= number_format($estatisticas_gerais['total_conversoes'] ?: 0) ?>
                                </div>
                                <div class="stats-label">Conversões</div>
                                <div class="stats-change positive">
                                    <i class="bi bi-arrow-up"></i> 8%
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="stats-number text-warning">
                                    <?= number_format($estatisticas_gerais['taxa_conversao'] ?: 0, 2) ?>%
                                </div>
                                <div class="stats-label">Taxa de Conversão</div>
                                <div class="stats-change positive">
                                    <i class="bi bi-arrow-up"></i> 2.5%
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="stats-number text-info">
                                    <?= number_format($estatisticas_gerais['total_cupons'] + $estatisticas_gerais['total_produtos']) ?>
                                </div>
                                <div class="stats-label">Itens Únicos</div>
                                <div class="stats-change positive">
                                    <i class="bi bi-arrow-up"></i> 15%
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Gráficos Principais -->
                <div class="row mb-4">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Evolução de Cliques e Conversões</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="graficoEvolucao"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Cliques por Hora (Hoje)</h5>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="graficoHoras"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tabelas de Top Performers -->
                <div class="row">
                    <!-- Top Cupons -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">Top 10 Cupons</h5>
                                <span class="badge bg-primary">Por Cliques</span>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead>
                                            <tr>
                                                <th>Cupom</th>
                                                <th>Loja</th>
                                                <th>Cliques</th>
                                                <th>Taxa</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($top_cupons)): ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted py-3">
                                                        <i class="bi bi-inbox me-2"></i>Nenhum dado disponível
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($top_cupons as $cupom): ?>
                                                <tr>
                                                    <td>
                                                        <div class="fw-bold small"><?= htmlspecialchars($cupom['descricao']) ?></div>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-light text-dark"><?= htmlspecialchars($cupom['loja_nome']) ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="badge badge-cliques"><?= $cupom['total_cliques'] ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="badge badge-taxa"><?= $cupom['taxa_conversao'] ?>%</span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Top Produtos -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">Top 10 Produtos</h5>
                                <span class="badge bg-primary">Por Cliques</span>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead>
                                            <tr>
                                                <th>Produto</th>
                                                <th>Loja</th>
                                                <th>Cliques</th>
                                                <th>Taxa</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($top_produtos)): ?>
                                                <tr>
                                                    <td colspan="4" class="text-center text-muted py-3">
                                                        <i class="bi bi-inbox me-2"></i>Nenhum dado disponível
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($top_produtos as $produto): ?>
                                                <tr>
                                                    <td>
                                                        <div class="fw-bold small"><?= htmlspecialchars($produto['nome']) ?></div>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-light text-dark"><?= htmlspecialchars($produto['loja_nome']) ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="badge badge-cliques"><?= $produto['total_cliques'] ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="badge badge-taxa"><?= $produto['taxa_conversao'] ?>%</span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top Lojas -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">Top Lojas por Performance</h5>
                                <span class="badge bg-primary">Desempenho Geral</span>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Loja</th>
                                                <th>Itens</th>
                                                <th>Cliques</th>
                                                <th>Conversões</th>
                                                <th>Taxa</th>
                                                <th>Performance</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($top_lojas)): ?>
                                                <tr>
                                                    <td colspan="6" class="text-center text-muted py-4">
                                                        <i class="bi bi-inbox me-2"></i>Nenhum dado disponível
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($top_lojas as $loja): 
                                                    $performance = $loja['taxa_conversao'] * $loja['total_cliques'] / 100;
                                                ?>
                                                <tr>
                                                    <td>
                                                        <div class="fw-bold"><?= htmlspecialchars($loja['nome']) ?></div>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-secondary"><?= $loja['itens_unicos'] ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="badge badge-cliques"><?= $loja['total_cliques'] ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="badge badge-conversoes"><?= $loja['total_conversoes'] ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="badge badge-taxa"><?= $loja['taxa_conversao'] ?>%</span>
                                                    </td>
                                                    <td>
                                                        <div class="progress" style="height: 8px;">
                                                            <div class="progress-bar bg-success" 
                                                                 style="width: <?= min($performance / 10, 100) ?>%"
                                                                 data-bs-toggle="tooltip" 
                                                                 title="Score: <?= number_format($performance, 1) ?>">
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Resumo do Período -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Resumo do Período</h5>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <div class="col-md-2">
                                        <div class="border rounded p-3">
                                            <div class="h4 text-primary mb-1"><?= count($evolucao_diaria) ?></div>
                                            <div class="text-muted small">Dias Analisados</div>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="border rounded p-3">
                                            <div class="h4 text-success mb-1"><?= number_format($estatisticas_gerais['total_cliques'] ?: 0) ?></div>
                                            <div class="text-muted small">Total de Cliques</div>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="border rounded p-3">
                                            <div class="h4 text-info mb-1"><?= number_format($estatisticas_gerais['total_conversoes'] ?: 0) ?></div>
                                            <div class="text-muted small">Conversões</div>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="border rounded p-3">
                                            <div class="h4 text-warning mb-1"><?= number_format($estatisticas_gerais['taxa_conversao'] ?: 0, 2) ?>%</div>
                                            <div class="text-muted small">Taxa Média</div>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="border rounded p-3">
                                            <div class="h4 text-secondary mb-1"><?= number_format($estatisticas_gerais['total_cupons'] ?: 0) ?></div>
                                            <div class="text-muted small">Cupons Ativos</div>
                                        </div>
                                    </div>
                                    <div class="col-md-2">
                                        <div class="border rounded p-3">
                                            <div class="h4 text-dark mb-1"><?= number_format($estatisticas_gerais['total_produtos'] ?: 0) ?></div>
                                            <div class="text-muted small">Produtos Ativos</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Gráfico de Evolução
        const ctxEvolucao = document.getElementById('graficoEvolucao').getContext('2d');
        const graficoEvolucao = new Chart(ctxEvolucao, {
            type: 'line',
            data: {
                labels: <?= json_encode($grafico_evolucao_labels) ?>,
                datasets: [
                    {
                        label: 'Cliques',
                        data: <?= json_encode($grafico_evolucao_cliques) ?>,
                        borderColor: '#4169E1',
                        backgroundColor: 'rgba(65, 105, 225, 0.1)',
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Conversões',
                        data: <?= json_encode($grafico_evolucao_conversoes) ?>,
                        borderColor: '#28a745',
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        tension: 0.4,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });

        // Gráfico de Horas
        const ctxHoras = document.getElementById('graficoHoras').getContext('2d');
        const graficoHoras = new Chart(ctxHoras, {
            type: 'bar',
            data: {
                labels: <?= json_encode($grafico_horas_labels) ?>,
                datasets: [{
                    label: 'Cliques por Hora',
                    data: <?= json_encode($grafico_horas_cliques) ?>,
                    backgroundColor: 'rgba(65, 105, 225, 0.7)',
                    borderColor: '#4169E1',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0
                        }
                    }
                }
            }
        });

        // Funções auxiliares
        function toggleCustomDate() {
            const customDateRange = document.getElementById('customDateRange');
            const periodoHidden = document.getElementById('periodoHidden');
            
            if (customDateRange.style.display === 'none') {
                customDateRange.style.display = 'block';
                periodoHidden.disabled = false;
            } else {
                customDateRange.style.display = 'none';
                periodoHidden.disabled = true;
            }
        }

        function atualizarEstatisticas() {
            window.location.reload();
        }

        function exportarRelatorio() {
            // Simular exportação - implementar conforme necessidade
            const link = document.createElement('a');
            link.href = `ajax/exportar-estatisticas.php?periodo=<?= $periodo ?>&data_inicio=<?= $data_inicio ?>&data_fim=<?= $data_fim ?>`;
            link.download = `relatorio-estatisticas-<?= $data_inicio ?>-a-<?= $data_fim ?>.csv`;
            link.click();
        }

        // Tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });

        // Auto-refresh a cada 5 minutos
        setInterval(atualizarEstatisticas, 300000);
    </script>
</body>
</html>