<?php
// Arquivo nav.php - Navegação superior da área administrativa

// Verificar se o usuário está logado
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Buscar notificações não lidas
$notificacoes_nao_lidas = 0;
$ultimas_notificacoes = [];

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM notificacoes 
        WHERE lida = 0 AND usuario_id = ?
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $notificacoes_nao_lidas = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("
        SELECT id, titulo, mensagem, tipo, created_at 
        FROM notificacoes 
        WHERE usuario_id = ? 
        ORDER BY created_at DESC 
        LIMIT 5
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $ultimas_notificacoes = $stmt->fetchAll();
} catch (Exception $e) {
    // Silenciar erro se a tabela não existir
    $notificacoes_nao_lidas = 0;
    $ultimas_notificacoes = [];
}

// Buscar estatísticas rápidas para o badge
$cupons_expirando = 0;
$sincronizacoes_pendentes = 0;

try {
    // Cupons que expiram em até 3 dias
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM cupons 
        WHERE ativo = 1 
        AND data_fim BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
    ");
    $stmt->execute();
    $cupons_expirando = $stmt->fetchColumn();
    
    // Sincronizações com erro nas últimas 24h
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM logs_sincronizacao 
        WHERE sucesso = 0 
        AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute();
    $sincronizacoes_pendentes = $stmt->fetchColumn();
} catch (Exception $e) {
    // Silenciar erro se as tabelas não existirem
    $cupons_expirando = 0;
    $sincronizacoes_pendentes = 0;
}
?>

<!-- Navigation Bar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top">
    <div class="container-fluid">
        <!-- Logo e Brand -->
        <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
            <div class="bg-primary rounded p-2 me-2">
                <i class="bi bi-ticket-perforated text-white"></i>
            </div>
            <span class="fw-bold">TTaTim</span>
            <small class="text-muted ms-1 d-none d-sm-inline">Admin</small>
        </a>

        <!-- Mobile Toggle -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarAdmin">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- Navbar Content -->
        <div class="collapse navbar-collapse" id="navbarAdmin">
            <!-- Search Form -->
            <form class="d-flex me-auto ms-2 my-2 my-lg-0" method="GET" action="busca.php" style="max-width: 300px;">
                <div class="input-group">
                    <input type="search" class="form-control form-control-sm" placeholder="Buscar cupons, produtos..." 
                           name="q" aria-label="Search">
                    <button class="btn btn-outline-light btn-sm" type="submit">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
            </form>

            <!-- Navbar Items -->
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                
                <!-- Dashboard Link -->
                <li class="nav-item">
                    <a class="nav-link" href="dashboard.php" data-bs-toggle="tooltip" title="Dashboard">
                        <i class="bi bi-speedometer2 me-1"></i>
                        <span class="d-none d-lg-inline">Dashboard</span>
                    </a>
                </li>

                <!-- Notifications Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link position-relative" href="#" id="notificationsDropdown" 
                       role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-bell me-1"></i>
                        <span class="d-none d-lg-inline">Notificações</span>
                        <?php if ($notificacoes_nao_lidas > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                <?= $notificacoes_nao_lidas ?>
                                <span class="visually-hidden">notificações não lidas</span>
                            </span>
                        <?php endif; ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-lg" aria-labelledby="notificationsDropdown">
                        <li>
                            <h6 class="dropdown-header">
                                Notificações
                                <?php if ($notificacoes_nao_lidas > 0): ?>
                                    <span class="badge bg-primary ms-2"><?= $notificacoes_nao_lidas ?> novas</span>
                                <?php endif; ?>
                            </h6>
                        </li>
                        
                        <?php if (empty($ultimas_notificacoes)): ?>
                            <li>
                                <div class="dropdown-item-text text-center text-muted py-3">
                                    <i class="bi bi-bell-slash display-6"></i>
                                    <p class="mb-0 mt-2">Nenhuma notificação</p>
                                </div>
                            </li>
                        <?php else: ?>
                            <?php foreach ($ultimas_notificacoes as $notificacao): 
                                $bg_class = '';
                                $icon_class = 'bi-bell';
                                
                                switch ($notificacao['tipo']) {
                                    case 'success':
                                        $bg_class = 'bg-success bg-opacity-10';
                                        $icon_class = 'bi-check-circle text-success';
                                        break;
                                    case 'warning':
                                        $bg_class = 'bg-warning bg-opacity-10';
                                        $icon_class = 'bi-exclamation-triangle text-warning';
                                        break;
                                    case 'error':
                                        $bg_class = 'bg-danger bg-opacity-10';
                                        $icon_class = 'bi-x-circle text-danger';
                                        break;
                                    case 'info':
                                    default:
                                        $bg_class = 'bg-info bg-opacity-10';
                                        $icon_class = 'bi-info-circle text-info';
                                }
                            ?>
                            <li>
                                <a class="dropdown-item d-flex align-items-start py-2 <?= $bg_class ?>" href="#">
                                    <i class="<?= $icon_class ?> me-2 mt-1"></i>
                                    <div class="flex-grow-1">
                                        <div class="fw-bold small"><?= htmlspecialchars($notificacao['titulo']) ?></div>
                                        <div class="small text-muted"><?= htmlspecialchars($notificacao['mensagem']) ?></div>
                                        <div class="small text-muted"><?= formatarTempo($notificacao['created_at']) ?></div>
                                    </div>
                                </a>
                            </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-center text-primary" href="notificacoes.php">
                                <i class="bi bi-list-ul me-1"></i>Ver todas as notificações
                            </a>
                        </li>
                    </ul>
                </li>

                <!-- Alerts Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link position-relative" href="#" id="alertsDropdown" 
                       role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        <span class="d-none d-lg-inline">Alertas</span>
                        <?php if ($cupons_expirando > 0 || $sincronizacoes_pendentes > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-warning">
                                <?= $cupons_expirando + $sincronizacoes_pendentes ?>
                                <span class="visually-hidden">alertas ativos</span>
                            </span>
                        <?php endif; ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="alertsDropdown">
                        <li><h6 class="dropdown-header">Alertas do Sistema</h6></li>
                        
                        <?php if ($cupons_expirando > 0): ?>
                        <li>
                            <a class="dropdown-item d-flex align-items-center text-warning" href="cupons.php?filtro=expirando">
                                <i class="bi bi-clock me-2"></i>
                                <div>
                                    <div class="fw-bold"><?= $cupons_expirando ?> cupons expiram em breve</div>
                                    <small class="text-muted">Verifique e renove os cupons</small>
                                </div>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if ($sincronizacoes_pendentes > 0): ?>
                        <li>
                            <a class="dropdown-item d-flex align-items-center text-danger" href="api-sync.php">
                                <i class="bi bi-cloud-slash me-2"></i>
                                <div>
                                    <div class="fw-bold"><?= $sincronizacoes_pendentes ?> sincronizações falharam</div>
                                    <small class="text-muted">Verificar conexão com APIs</small>
                                </div>
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php if ($cupons_expirando == 0 && $sincronizacoes_pendentes == 0): ?>
                        <li>
                            <div class="dropdown-item-text text-center text-muted py-2">
                                <i class="bi bi-check-circle display-6 text-success"></i>
                                <p class="mb-0 mt-2">Nenhum alerta ativo</p>
                            </div>
                        </li>
                        <?php endif; ?>
                        
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-center" href="configuracoes.php">
                                <i class="bi bi-gear me-1"></i>Configurações do Sistema
                            </a>
                        </li>
                    </ul>
                </li>

                <!-- Quick Actions Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link" href="#" id="quickActionsDropdown" 
                       role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-lightning me-1"></i>
                        <span class="d-none d-lg-inline">Ações Rápidas</span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="quickActionsDropdown">
                        <li><h6 class="dropdown-header">Criar Novo</h6></li>
                        <li>
                            <a class="dropdown-item" href="cupons.php?acao=novo">
                                <i class="bi bi-ticket-perforated text-primary me-2"></i>
                                Novo Cupom
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="produtos.php?acao=novo">
                                <i class="bi bi-box text-success me-2"></i>
                                Novo Produto
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="lojas.php?acao=nova">
                                <i class="bi bi-shop text-warning me-2"></i>
                                Nova Loja
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header">Ferramentas</h6></li>
                        <li>
                            <a class="dropdown-item" href="api-sync.php">
                                <i class="bi bi-cloud-arrow-down text-info me-2"></i>
                                Sincronizar APIs
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="estatisticas.php">
                                <i class="bi bi-graph-up text-purple me-2"></i>
                                Ver Estatísticas
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="exportar.php">
                                <i class="bi bi-download text-secondary me-2"></i>
                                Exportar Dados
                            </a>
                        </li>
                    </ul>
                </li>

                <!-- User Menu Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="userDropdown" 
                       role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <div class="bg-primary rounded-circle me-2 d-flex align-items-center justify-content-center" 
                             style="width: 32px; height: 32px;">
                            <i class="bi bi-person-fill text-white"></i>
                        </div>
                        <div class="d-none d-lg-block">
                            <div class="small fw-bold"><?= htmlspecialchars($_SESSION['username']) ?></div>
                            <div class="small text-muted">Administrador</div>
                        </div>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                        <li>
                            <div class="dropdown-header">
                                <div class="fw-bold"><?= htmlspecialchars($_SESSION['username']) ?></div>
                                <small class="text-muted">Administrador</small>
                            </div>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="perfil.php">
                                <i class="bi bi-person me-2"></i>Meu Perfil
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="configuracoes.php">
                                <i class="bi bi-gear me-2"></i>Configurações
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="../index.php" target="_blank">
                                <i class="bi bi-eye me-2"></i>Ver Site
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item" href="logs.php">
                                <i class="bi bi-clock-history me-2"></i>Logs do Sistema
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="backup.php">
                                <i class="bi bi-database me-2"></i>Backup
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-danger" href="logout.php">
                                <i class="bi bi-box-arrow-right me-2"></i>Sair
                            </a>
                        </li>
                    </ul>
                </li>

                <!-- Mobile Menu Toggle (Hidden on desktop) -->
                <li class="nav-item d-lg-none">
                    <button class="btn btn-link nav-link text-light" type="button" data-bs-toggle="collapse" data-bs-target="#sidebarMenu">
                        <i class="bi bi-list"></i>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- Breadcrumb Navigation -->
<nav aria-label="breadcrumb" class="bg-light border-bottom">
    <div class="container-fluid">
        <ol class="breadcrumb py-2 mb-0">
            <?= gerarBreadcrumb() ?>
        </ol>
    </div>
</nav>

<!-- Quick Stats Bar -->
<div class="bg-primary text-white py-2 small d-none d-md-block">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-3">
                <i class="bi bi-ticket-perforated me-1"></i>
                <strong><?= number_format($estatisticas_gerais['cupons_ativos'] ?? 0) ?></strong> cupons ativos
            </div>
            <div class="col-md-3">
                <i class="bi bi-box me-1"></i>
                <strong><?= number_format($estatisticas_gerais['produtos_ativos'] ?? 0) ?></strong> produtos ativos
            </div>
            <div class="col-md-3">
                <i class="bi bi-shop me-1"></i>
                <strong><?= number_format($estatisticas_gerais['lojas_ativas'] ?? 0) ?></strong> lojas parceiras
            </div>
            <div class="col-md-3 text-end">
                <i class="bi bi-graph-up me-1"></i>
                <strong><?= number_format($cliques_comparacao['cliques_hoje'] ?? 0) ?></strong> cliques hoje
            </div>
        </div>
    </div>
</div>

<script>
// Função para marcar notificações como lidas
function marcarNotificacaoComoLida(notificacaoId) {
    fetch('ajax/marcar-notificacao-lida.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            notificacao_id: notificacaoId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Atualizar contador de notificações
            atualizarContadorNotificacoes();
        }
    })
    .catch(error => console.error('Erro:', error));
}

// Atualizar contador de notificações
function atualizarContadorNotificacoes() {
    fetch('ajax/contador-notificacoes.php')
        .then(response => response.json())
        .then(data => {
            const badge = document.querySelector('#notificationsDropdown .badge');
            if (data.total_nao_lidas > 0) {
                if (badge) {
                    badge.textContent = data.total_nao_lidas;
                } else {
                    // Criar badge se não existir
                    const newBadge = document.createElement('span');
                    newBadge.className = 'position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger';
                    newBadge.textContent = data.total_nao_lidas;
                    document.querySelector('#notificationsDropdown').appendChild(newBadge);
                }
            } else if (badge) {
                badge.remove();
            }
        })
        .catch(error => console.error('Erro:', error));
}

// Auto-atualizar notificações a cada 30 segundos
setInterval(atualizarContadorNotificacoes, 30000);

// Marcar todas como lidas quando o dropdown é fechado
document.getElementById('notificationsDropdown').addEventListener('hidden.bs.dropdown', function() {
    // Implementar lógica para marcar todas como lidas se desejado
});

// Search auto-complete
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.querySelector('input[name="q"]');
    if (searchInput) {
        // Implementar auto-complete aqui
        searchInput.addEventListener('input', debounce(function(e) {
            const termo = e.target.value;
            if (termo.length > 2) {
                // Buscar sugestões
                buscarSugestoes(termo);
            }
        }, 300));
    }
});

function buscarSugestoes(termo) {
    // Implementar busca de sugestões
    console.log('Buscando sugestões para:', termo);
}

function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Ctrl+K para focar na busca
    if (e.ctrlKey && e.key === 'k') {
        e.preventDefault();
        const searchInput = document.querySelector('input[name="q"]');
        if (searchInput) {
            searchInput.focus();
        }
    }
    
    // Alt+N para notificações
    if (e.altKey && e.key === 'n') {
        e.preventDefault();
        const dropdown = new bootstrap.Dropdown(document.getElementById('notificationsDropdown'));
        dropdown.show();
    }
    
    // Alt+A para alertas
    if (e.altKey && e.key === 'a') {
        e.preventDefault();
        const dropdown = new bootstrap.Dropdown(document.getElementById('alertsDropdown'));
        dropdown.show();
    }
});

// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>

<?php
// Função para gerar breadcrumb dinâmico
function gerarBreadcrumb() {
    $current_page = basename($_SERVER['PHP_SELF']);
    $breadcrumb = '<li class="breadcrumb-item"><a href="dashboard.php"><i class="bi bi-house"></i></a></li>';
    
    $pages = [
        'dashboard.php' => 'Dashboard',
        'cupons.php' => 'Cupons',
        'produtos.php' => 'Produtos',
        'lojas.php' => 'Lojas',
        'estatisticas.php' => 'Estatísticas',
        'configuracoes.php' => 'Configurações',
        'api-sync.php' => 'Sincronização API',
        'perfil.php' => 'Meu Perfil',
        'logs.php' => 'Logs do Sistema',
        'backup.php' => 'Backup'
    ];
    
    if (isset($pages[$current_page])) {
        $breadcrumb .= '<li class="breadcrumb-item active">' . $pages[$current_page] . '</li>';
    } else {
        $breadcrumb .= '<li class="breadcrumb-item active">Página Atual</li>';
    }
    
    return $breadcrumb;
}

// Função para formatar tempo relativo
function formatarTempo($data) {
    $agora = new DateTime();
    $data_notificacao = new DateTime($data);
    $diferenca = $agora->diff($data_notificacao);
    
    if ($diferenca->y > 0) return $diferenca->y . ' ano' . ($diferenca->y > 1 ? 's' : '') . ' atrás';
    if ($diferenca->m > 0) return $diferenca->m . ' mês' . ($diferenca->m > 1 ? 'es' : '') . ' atrás';
    if ($diferenca->d > 0) return $diferenca->d . ' dia' . ($diferenca->d > 1 ? 's' : '') . ' atrás';
    if ($diferenca->h > 0) return $diferenca->h . ' hora' . ($diferenca->h > 1 ? 's' : '') . ' atrás';
    if ($diferenca->i > 0) return $diferenca->i . ' minuto' . ($diferenca->i > 1 ? 's' : '') . ' atrás';
    
    return 'Agora mesmo';
}
?>