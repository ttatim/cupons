<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// Buscar estatísticas para o dashboard
$hoje = date('Y-m-d');
$ontem = date('Y-m-d', strtotime('-1 day'));
$mes_atual = date('Y-m');
$mes_anterior = date('Y-m', strtotime('-1 month'));

// Estatísticas gerais
$stmt = $pdo->query("
    SELECT 
        (SELECT COUNT(*) FROM cupons WHERE ativo = 1 AND data_fim >= CURDATE()) as cupons_ativos,
        (SELECT COUNT(*) FROM produtos WHERE ativo = 1) as produtos_ativos,
        (SELECT COUNT(*) FROM lojas WHERE ativo = 1) as lojas_ativas,
        (SELECT COUNT(*) FROM usuarios WHERE ativo = 1) as usuarios_ativos
");
$estatisticas_gerais = $stmt->fetch();

// Cliques hoje vs ontem
$stmt = $pdo->prepare("
    SELECT 
        (SELECT SUM(cliques) FROM estatisticas WHERE data_acesso = ?) as cliques_hoje,
        (SELECT SUM(cliques) FROM estatisticas WHERE data_acesso = ?) as cliques_ontem
");
$stmt->execute([$hoje, $ontem]);
$cliques_comparacao = $stmt->fetch();

// Conversões hoje vs ontem
$stmt = $pdo->prepare("
    SELECT 
        (SELECT SUM(conversoes) FROM estatisticas WHERE data_acesso = ?) as conversoes_hoje,
        (SELECT SUM(conversoes) FROM estatisticas WHERE data_acesso = ?) as conversoes_ontem
");
$stmt->execute([$hoje, $ontem]);
$conversoes_comparacao = $stmt->fetch();

// Receita do mês
$stmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(disponivel), 0) as receita_mes_atual,
        (SELECT COALESCE(SUM(disponivel), 0) FROM comissoes WHERE DATE_FORMAT(updated_at, '%Y-%m') = ?) as receita_mes_anterior
    FROM comissoes 
    WHERE DATE_FORMAT(updated_at, '%Y-%m') = ?
");
$stmt->execute([$mes_anterior, $mes_atual]);
$receita_comparacao = $stmt->fetch();

// Top 5 cupons mais clicados (últimos 7 dias)
$stmt = $pdo->prepare("
    SELECT 
        c.descricao,
        l.nome as loja_nome,
        SUM(e.cliques) as total_cliques
    FROM estatisticas e
    JOIN cupons c ON e.item_id = c.id AND e.tipo = 'cupom'
    JOIN lojas l ON c.loja_id = l.id
    WHERE e.data_acesso >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY c.id
    ORDER BY total_cliques DESC
    LIMIT 5
");
$stmt->execute();
$top_cupons = $stmt->fetchAll();

// Top 5 produtos mais clicados (últimos 7 dias)
$stmt = $pdo->prepare("
    SELECT 
        p.nome,
        l.nome as loja_nome,
        SUM(e.cliques) as total_cliques
    FROM estatisticas e
    JOIN produtos p ON e.item_id = p.id AND e.tipo = 'produto'
    JOIN lojas l ON p.loja_id = l.id
    WHERE e.data_acesso >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY p.id
    ORDER BY total_cliques DESC
    LIMIT 5
");
$stmt->execute();
$top_produtos = $stmt->fetchAll();

// Evolução de cliques (últimos 7 dias)
$stmt = $pdo->prepare("
    SELECT 
        data_acesso as data,
        SUM(cliques) as cliques,
        SUM(conversoes) as conversoes
    FROM estatisticas 
    WHERE data_acesso >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY data_acesso 
    ORDER BY data_acesso
");
$stmt->execute();
$evolucao_cliques = $stmt->fetchAll();

// Cupons prestes a expirar (próximos 7 dias)
$stmt = $pdo->prepare("
    SELECT 
        c.descricao,
        l.nome as loja_nome,
        c.data_fim,
        DATEDIFF(c.data_fim, CURDATE()) as dias_restantes
    FROM cupons c
    JOIN lojas l ON c.loja_id = l.id
    WHERE c.ativo = 1 
    AND c.data_fim BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY c.data_fim ASC
    LIMIT 5
");
$stmt->execute();
$cupons_expirando = $stmt->fetchAll();

// Últimas sincronizações
$stmt = $pdo->query("
    SELECT 
        plataforma,
        acao,
        produtos_adicionados,
        created_at
    FROM logs_sincronizacao 
    ORDER BY created_at DESC 
    LIMIT 5
");
$ultimas_sincronizacoes = $stmt->fetchAll();

// Preparar dados para gráfico
$grafico_labels = [];
$grafico_cliques = [];
$grafico_conversoes = [];

foreach ($evolucao_cliques as $dia) {
    $grafico_labels[] = date('d/m', strtotime($dia['data']));
    $grafico_cliques[] = $dia['cliques'];
    $grafico_conversoes[] = $dia['conversoes'];
}

// Calcular variações percentuais
$variacao_cliques = calcularVariacaoPercentual($cliques_comparacao['cliques_hoje'], $cliques_comparacao['cliques_ontem']);
$variacao_conversoes = calcularVariacaoPercentual($conversoes_comparacao['conversoes_hoje'], $conversoes_comparacao['conversoes_ontem']);
$variacao_receita = calcularVariacaoPercentual($receita_comparacao['receita_mes_atual'], $receita_comparacao['receita_mes_anterior']);

function calcularVariacaoPercentual($atual, $anterior) {
    if ($anterior == 0) {
        return $atual > 0 ? 100 : 0;
    }
    return (($atual - $anterior) / $anterior) * 100;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Administração</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            transition: transform 0.2s ease;
        }
        .card:hover {
            transform: translateY(-2px);
        }
        .stats-card {
            text-align: center;
            padding: 20px;
        }
        .stats-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .stats-label {
            color: #6c757d;
            font-size: 0.9rem;
            margin-bottom: 5px;
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
            height: 250px;
            width: 100%;
        }
        .quick-action {
            text-align: center;
            padding: 15px;
            border-radius: 8px;
            background: #f8f9fa;
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .quick-action:hover {
            background: #4169E1;
            color: white;
            transform: translateY(-3px);
            text-decoration: none;
        }
        .quick-action-icon {
            font-size: 1.5rem;
            margin-bottom: 10px;
        }
        .list-item {
            border-left: 3px solid #4169E1;
            padding-left: 15px;
            margin-bottom: 10px;
        }
        .list-item.warning {
            border-left-color: #ffc107;
        }
        .list-item.danger {
            border-left-color: #dc3545;
        }
        .badge-days {
            font-size: 0.7rem;
        }
        .welcome-banner {
            background: linear-gradient(135deg, #4169E1, #3151C0);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <!-- Banner de Boas-Vindas -->
                <div class="welcome-banner">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h2 class="mb-2">Bem-vindo, <?= htmlspecialchars($_SESSION['username']) ?>! 👋</h2>
                            <p class="mb-0">Aqui está um resumo do desempenho da sua plataforma hoje.</p>
                        </div>
                        <div class="col-md-4 text-end">
                            <div class="h4 mb-0"><?= date('d/m/Y') ?></div>
                            <small><?= date('H:i') ?> • <?= getDiaSemana(date('w')) ?></small>
                        </div>
                    </div>
                </div>

                <!-- Cards de Estatísticas Principais -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="stats-number text-primary">
                                    <?= number_format($cliques_comparacao['cliques_hoje'] ?: 0) ?>
                                </div>
                                <div class="stats-label">Cliques Hoje</div>
                                <div class="stats-change <?= $variacao_cliques >= 0 ? 'positive' : 'negative' ?>">
                                    <i class="bi bi-arrow-<?= $variacao_cliques >= 0 ? 'up' : 'down' ?>"></i>
                                    <?= number_format(abs($variacao_cliques), 1) ?>%
                                    <?php if ($cliques_comparacao['cliques_ontem']): ?>
                                        <small class="text-muted">vs ontem (<?= number_format($cliques_comparacao['cliques_ontem']) ?>)</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="stats-number text-success">
                                    <?= number_format($conversoes_comparacao['conversoes_hoje'] ?: 0) ?>
                                </div>
                                <div class="stats-label">Conversões Hoje</div>
                                <div class="stats-change <?= $variacao_conversoes >= 0 ? 'positive' : 'negative' ?>">
                                    <i class="bi bi-arrow-<?= $variacao_conversoes >= 0 ? 'up' : 'down' ?>"></i>
                                    <?= number_format(abs($variacao_conversoes), 1) ?>%
                                    <?php if ($conversoes_comparacao['conversoes_ontem']): ?>
                                        <small class="text-muted">vs ontem (<?= number_format($conversoes_comparacao['conversoes_ontem']) ?>)</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="stats-number text-warning">
                                    R$ <?= number_format($receita_comparacao['receita_mes_atual'], 2, ',', '.') ?>
                                </div>
                                <div class="stats-label">Receita do Mês</div>
                                <div class="stats-change <?= $variacao_receita >= 0 ? 'positive' : 'negative' ?>">
                                    <i class="bi bi-arrow-<?= $variacao_receita >= 0 ? 'up' : 'down' ?>"></i>
                                    <?= number_format(abs($variacao_receita), 1) ?>%
                                    <?php if ($receita_comparacao['receita_mes_anterior']): ?>
                                        <small class="text-muted">vs mês anterior</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="stats-number text-info">
                                    <?= number_format($estatisticas_gerais['cupons_ativos'] + $estatisticas_gerais['produtos_ativos']) ?>
                                </div>
                                <div class="stats-label">Ofertas Ativas</div>
                                <div class="stats-change positive">
                                    <i class="bi bi-arrow-up"></i>
                                    <?= number_format($estatisticas_gerais['cupons_ativos']) ?> cupons
                                    <small class="text-muted d-block"><?= number_format($estatisticas_gerais['produtos_ativos']) ?> produtos</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Gráfico e Ações Rápidas -->
                    <div class="col-md-8">
                        <!-- Gráfico de Evolução -->
                        <div class="card mb-4">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">Evolução de Cliques e Conversões (7 dias)</h5>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-secondary active" data-period="7d">7D</button>
                                    <button class="btn btn-outline-secondary" data-period="30d">30D</button>
                                    <button class="btn btn-outline-secondary" data-period="90d">90D</button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="chart-container">
                                    <canvas id="graficoEvolucao"></canvas>
                                </div>
                            </div>
                        </div>

                        <!-- Ações Rápidas -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Ações Rápidas</h5>
                            </div>
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <a href="cupons.php" class="quick-action">
                                            <div class="quick-action-icon text-primary">
                                                <i class="bi bi-ticket-perforated"></i>
                                            </div>
                                            <div class="fw-bold">Gerenciar Cupons</div>
                                            <small class="text-muted"><?= number_format($estatisticas_gerais['cupons_ativos']) ?> ativos</small>
                                        </a>
                                    </div>
                                    <div class="col-md-3">
                                        <a href="produtos.php" class="quick-action">
                                            <div class="quick-action-icon text-success">
                                                <i class="bi bi-box"></i>
                                            </div>
                                            <div class="fw-bold">Gerenciar Produtos</div>
                                            <small class="text-muted"><?= number_format($estatisticas_gerais['produtos_ativos']) ?> ativos</small>
                                        </a>
                                    </div>
                                    <div class="col-md-3">
                                        <a href="lojas.php" class="quick-action">
                                            <div class="quick-action-icon text-warning">
                                                <i class="bi bi-shop"></i>
                                            </div>
                                            <div class="fw-bold">Gerenciar Lojas</div>
                                            <small class="text-muted"><?= number_format($estatisticas_gerais['lojas_ativas']) ?> ativas</small>
                                        </a>
                                    </div>
                                    <div class="col-md-3">
                                        <a href="api-sync.php" class="quick-action">
                                            <div class="quick-action-icon text-info">
                                                <i class="bi bi-cloud-arrow-down"></i>
                                            </div>
                                            <div class="fw-bold">Sincronizar APIs</div>
                                            <small class="text-muted">Atualizar ofertas</small>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar com Listas -->
                    <div class="col-md-4">
                        <!-- Top Cupons -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Top Cupons (7 dias)</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($top_cupons)): ?>
                                    <div class="text-center text-muted py-3">
                                        <i class="bi bi-inbox me-2"></i>Nenhum clique registrado
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($top_cupons as $cupom): ?>
                                    <div class="list-item">
                                        <div class="fw-bold small"><?= htmlspecialchars($cupom['descricao']) ?></div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-muted small"><?= htmlspecialchars($cupom['loja_nome']) ?></span>
                                            <span class="badge bg-primary"><?= $cupom['total_cliques'] ?> cliques</span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Top Produtos -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Top Produtos (7 dias)</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($top_produtos)): ?>
                                    <div class="text-center text-muted py-3">
                                        <i class="bi bi-inbox me-2"></i>Nenhum clique registrado
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($top_produtos as $produto): ?>
                                    <div class="list-item">
                                        <div class="fw-bold small"><?= htmlspecialchars($produto['nome']) ?></div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-muted small"><?= htmlspecialchars($produto['loja_nome']) ?></span>
                                            <span class="badge bg-success"><?= $produto['total_cliques'] ?> cliques</span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Cupons Expirando -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Cupons Prestes a Expirar</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($cupons_expirando)): ?>
                                    <div class="text-center text-muted py-3">
                                        <i class="bi bi-check-circle me-2"></i>Nenhum cupom expirando em breve
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($cupons_expirando as $cupom): 
                                        $classe = $cupom['dias_restantes'] <= 1 ? 'danger' : ($cupom['dias_restantes'] <= 3 ? 'warning' : '');
                                    ?>
                                    <div class="list-item <?= $classe ?>">
                                        <div class="fw-bold small"><?= htmlspecialchars($cupom['descricao']) ?></div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-muted small"><?= htmlspecialchars($cupom['loja_nome']) ?></span>
                                            <span class="badge badge-days bg-<?= $cupom['dias_restantes'] <= 1 ? 'danger' : ($cupom['dias_restantes'] <= 3 ? 'warning' : 'secondary') ?>">
                                                <?= $cupom['dias_restantes'] == 0 ? 'Hoje' : ($cupom['dias_restantes'] . ' dia' . ($cupom['dias_restantes'] != 1 ? 's' : '')) ?>
                                            </span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                    <div class="text-center mt-2">
                                        <a href="cupons.php?filtro_status=ativos" class="btn btn-sm btn-outline-primary">Ver Todos</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Últimas Sincronizações -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Últimas Sincronizações</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($ultimas_sincronizacoes)): ?>
                                    <div class="text-center text-muted py-3">
                                        <i class="bi bi-inbox me-2"></i>Nenhuma sincronização
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($ultimas_sincronizacoes as $sync): ?>
                                    <div class="list-item">
                                        <div class="fw-bold small text-capitalize"><?= $sync['acao'] ?> - <?= $sync['plataforma'] ?></div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-muted small"><?= date('H:i', strtotime($sync['created_at'])) ?></span>
                                            <?php if ($sync['produtos_adicionados'] > 0): ?>
                                                <span class="badge bg-success">+<?= $sync['produtos_adicionados'] ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">0</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                    <div class="text-center mt-2">
                                        <a href="api-sync.php" class="btn btn-sm btn-outline-primary">Sincronizar Agora</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cards de Estatísticas Secundárias -->
                <div class="row mt-4">
                    <div class="col-md-2">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="stats-number" style="color: #6c757d;">
                                    <?= number_format($estatisticas_gerais['cupons_ativos']) ?>
                                </div>
                                <div class="stats-label">Cupons Ativos</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="stats-number" style="color: #6c757d;">
                                    <?= number_format($estatisticas_gerais['produtos_ativos']) ?>
                                </div>
                                <div class="stats-label">Produtos Ativos</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="stats-number" style="color: #6c757d;">
                                    <?= number_format($estatisticas_gerais['lojas_ativas']) ?>
                                </div>
                                <div class="stats-label">Lojas Parceiras</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="stats-number" style="color: #6c757d;">
                                    <?= number_format($estatisticas_gerais['usuarios_ativos']) ?>
                                </div>
                                <div class="stats-label">Usuários</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="stats-number" style="color: #6c757d;">
                                    <?= number_format($receita_comparacao['receita_mes_atual'], 0) ?>
                                </div>
                                <div class="stats-label">Receita (R$)</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="stats-number" style="color: #6c757d;">
                                    <?= calcularTaxaConversao($conversoes_comparacao['conversoes_hoje'], $cliques_comparacao['cliques_hoje']) ?>%
                                </div>
                                <div class="stats-label">Taxa Conversão</div>
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
                labels: <?= json_encode($grafico_labels) ?>,
                datasets: [
                    {
                        label: 'Cliques',
                        data: <?= json_encode($grafico_cliques) ?>,
                        borderColor: '#4169E1',
                        backgroundColor: 'rgba(65, 105, 225, 0.1)',
                        tension: 0.4,
                        fill: true,
                        borderWidth: 2
                    },
                    {
                        label: 'Conversões',
                        data: <?= json_encode($grafico_conversoes) ?>,
                        borderColor: '#28a745',
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        tension: 0.4,
                        fill: true,
                        borderWidth: 2
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
                },
                interaction: {
                    intersect: false,
                    mode: 'index'
                }
            }
        });

        // Botões de período do gráfico
        document.querySelectorAll('[data-period]').forEach(btn => {
            btn.addEventListener('click', function() {
                // Ativar botão clicado
                document.querySelectorAll('[data-period]').forEach(b => {
                    b.classList.remove('active');
                });
                this.classList.add('active');
                
                // Aqui você pode implementar a atualização do gráfico
                // com dados do período selecionado
                console.log('Período selecionado:', this.getAttribute('data-period'));
            });
        });

        // Auto-refresh a cada 2 minutos
        setInterval(() => {
            // Atualizar apenas os cards de estatísticas
            location.reload();
        }, 120000);

        // Animações de entrada
        document.addEventListener('DOMContentLoaded', function() {
            const cards = document.querySelectorAll('.card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    card.style.transition = 'all 0.5s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });
        });
    </script>
</body>
</html>

<?php
// Funções auxiliares
function getDiaSemana($numero) {
    $dias = [
        'Domingo', 'Segunda-feira', 'Terça-feira', 
        'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'
    ];
    return $dias[$numero] ?? 'Dia desconhecido';
}

function calcularTaxaConversao($conversoes, $cliques) {
    if ($cliques == 0) return 0;
    return number_format(($conversoes / $cliques) * 100, 1);
}
?>