<?php
// Arquivo sidebar.php - Menu lateral da área administrativa

// Determinar página atual para highlight do menu
$current_page = basename($_SERVER['PHP_SELF']);
$current_section = '';

// Mapear páginas para seções
$page_sections = [
    'dashboard.php' => 'dashboard',
    
    'cupons.php' => 'conteudo',
    'cupons-novo.php' => 'conteudo',
    'cupons-editar.php' => 'conteudo',
    
    'produtos.php' => 'conteudo', 
    'produtos-novo.php' => 'conteudo',
    'produtos-editar.php' => 'conteudo',
    
    'lojas.php' => 'conteudo',
    'lojas-nova.php' => 'conteudo',
    'lojas-editar.php' => 'conteudo',
    
    'categorias.php' => 'conteudo',
    
    'estatisticas.php' => 'analytics',
    'relatorios.php' => 'analytics',
    
    'api-sync.php' => 'integracao',
    'api-config.php' => 'integracao',
    
    'configuracoes.php' => 'sistema',
    'perfil.php' => 'sistema',
    'usuarios.php' => 'sistema',
    'backup.php' => 'sistema',
    'logs.php' => 'sistema'
];

if (isset($page_sections[$current_page])) {
    $current_section = $page_sections[$current_page];
}

// Buscar contadores para badges
$contadores = [
    'cupons_ativos' => 0,
    'cupons_expirando' => 0,
    'produtos_ativos' => 0,
    'sincronizacoes_pendentes' => 0
];

try {
    // Cupons ativos
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM cupons WHERE ativo = 1 AND data_fim >= CURDATE()");
    $stmt->execute();
    $contadores['cupons_ativos'] = $stmt->fetchColumn();
    
    // Cupons expirando em 3 dias
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM cupons 
        WHERE ativo = 1 
        AND data_fim BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
    ");
    $stmt->execute();
    $contadores['cupons_expirando'] = $stmt->fetchColumn();
    
    // Produtos ativos
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM produtos WHERE ativo = 1");
    $stmt->execute();
    $contadores['produtos_ativos'] = $stmt->fetchColumn();
    
    // Sincronizações com erro nas últimas 24h
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM logs_sincronizacao 
        WHERE sucesso = 0 
        AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute();
    $contadores['sincronizacoes_pendentes'] = $stmt->fetchColumn();
    
} catch (Exception $e) {
    // Silenciar erros se as tabelas não existirem
}
?>

<!-- Sidebar -->
<div class="col-md-3 col-lg-2 sidebar bg-dark text-white p-0" id="sidebarMenu">
    <div class="sidebar-sticky pt-3">
        
        <!-- User Info -->
        <div class="px-3 mb-4">
            <div class="d-flex align-items-center">
                <div class="bg-primary rounded-circle d-flex align-items-center justify-content-center me-3" 
                     style="width: 40px; height: 40px;">
                    <i class="bi bi-person-fill text-white"></i>
                </div>
                <div>
                    <div class="fw-bold small"><?= htmlspecialchars($_SESSION['username']) ?></div>
                    <div class="small text-muted">Administrador</div>
                </div>
            </div>
        </div>

        <!-- Navigation Menu -->
        <ul class="nav flex-column">
            
            <!-- Dashboard -->
            <li class="nav-item">
                <a class="nav-link <?= $current_page === 'dashboard.php' ? 'active' : '' ?>" href="dashboard.php">
                    <i class="bi bi-speedometer2 me-2"></i>
                    Dashboard
                </a>
            </li>

            <!-- Conteúdo -->
            <li class="nav-item">
                <a class="nav-link <?= $current_section === 'conteudo' ? '' : 'collapsed' ?>" 
                   data-bs-toggle="collapse" href="#collapseConteudo" role="button" 
                   aria-expanded="<?= $current_section === 'conteudo' ? 'true' : 'false' ?>" 
                   aria-controls="collapseConteudo">
                    <i class="bi bi-collection me-2"></i>
                    Conteúdo
                    <i class="bi bi-chevron-down ms-auto toggle-icon"></i>
                </a>
                <div class="collapse <?= $current_section === 'conteudo' ? 'show' : '' ?>" id="collapseConteudo">
                    <ul class="nav flex-column ms-3">
                        <li class="nav-item">
                            <a class="nav-link <?= $current_page === 'cupons.php' ? 'active' : '' ?>" href="cupons.php">
                                <i class="bi bi-ticket-perforated me-2"></i>
                                Cupons
                                <?php if ($contadores['cupons_ativos'] > 0): ?>
                                    <span class="badge bg-primary float-end"><?= $contadores['cupons_ativos'] ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $current_page === 'produtos.php' ? 'active' : '' ?>" href="produtos.php">
                                <i class="bi bi-box me-2"></i>
                                Produtos
                                <?php if ($contadores['produtos_ativos'] > 0): ?>
                                    <span class="badge bg-success float-end"><?= $contadores['produtos_ativos'] ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $current_page === 'lojas.php' ? 'active' : '' ?>" href="lojas.php">
                                <i class="bi bi-shop me-2"></i>
                                Lojas
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $current_page === 'categorias.php' ? 'active' : '' ?>" href="categorias.php">
                                <i class="bi bi-tags me-2"></i>
                                Categorias
                            </a>
                        </li>
                    </ul>
                </div>
            </li>

            <!-- Analytics -->
            <li class="nav-item">
                <a class="nav-link <?= $current_section === 'analytics' ? '' : 'collapsed' ?>" 
                   data-bs-toggle="collapse" href="#collapseAnalytics" role="button" 
                   aria-expanded="<?= $current_section === 'analytics' ? 'true' : 'false' ?>" 
                   aria-controls="collapseAnalytics">
                    <i class="bi bi-graph-up me-2"></i>
                    Analytics
                    <i class="bi bi-chevron-down ms-auto toggle-icon"></i>
                </a>
                <div class="collapse <?= $current_section === 'analytics' ? 'show' : '' ?>" id="collapseAnalytics">
                    <ul class="nav flex-column ms-3">
                        <li class="nav-item">
                            <a class="nav-link <?= $current_page === 'estatisticas.php' ? 'active' : '' ?>" href="estatisticas.php">
                                <i class="bi bi-bar-chart me-2"></i>
                                Estatísticas
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $current_page === 'relatorios.php' ? 'active' : '' ?>" href="relatorios.php">
                                <i class="bi bi-file-earmark-text me-2"></i>
                                Relatórios
                            </a>
                        </li>
                    </ul>
                </div>
            </li>

            <!-- Integração -->
            <li class="nav-item">
                <a class="nav-link <?= $current_section === 'integracao' ? '' : 'collapsed' ?>" 
                   data-bs-toggle="collapse" href="#collapseIntegracao" role="button" 
                   aria-expanded="<?= $current_section === 'integracao' ? 'true' : 'false' ?>" 
                   aria-controls="collapseIntegracao">
                    <i class="bi bi-plug me-2"></i>
                    Integração
                    <?php if ($contadores['sincronizacoes_pendentes'] > 0): ?>
                        <span class="badge bg-danger float-end"><?= $contadores['sincronizacoes_pendentes'] ?></span>
                    <?php else: ?>
                        <i class="bi bi-chevron-down ms-auto toggle-icon"></i>
                    <?php endif; ?>
                </a>
                <div class="collapse <?= $current_section === 'integracao' ? 'show' : '' ?>" id="collapseIntegracao">
                    <ul class="nav flex-column ms-3">
                        <li class="nav-item">
                            <a class="nav-link <?= $current_page === 'api-sync.php' ? 'active' : '' ?>" href="api-sync.php">
                                <i class="bi bi-cloud-arrow-down me-2"></i>
                                Sincronização
                                <?php if ($contadores['sincronizacoes_pendentes'] > 0): ?>
                                    <span class="badge bg-danger float-end"><?= $contadores['sincronizacoes_pendentes'] ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $current_page === 'api-config.php' ? 'active' : '' ?>" href="api-config.php">
                                <i class="bi bi-gear me-2"></i>
                                Configurações API
                            </a>
                        </li>
                    </ul>
                </div>
            </li>

            <!-- Sistema -->
            <li class="nav-item">
                <a class="nav-link <?= $current_section === 'sistema' ? '' : 'collapsed' ?>" 
                   data-bs-toggle="collapse" href="#collapseSistema" role="button" 
                   aria-expanded="<?= $current_section === 'sistema' ? 'true' : 'false' ?>" 
                   aria-controls="collapseSistema">
                    <i class="bi bi-gear me-2"></i>
                    Sistema
                    <i class="bi bi-chevron-down ms-auto toggle-icon"></i>
                </a>
                <div class="collapse <?= $current_section === 'sistema' ? 'show' : '' ?>" id="collapseSistema">
                    <ul class="nav flex-column ms-3">
                        <li class="nav-item">
                            <a class="nav-link <?= $current_page === 'configuracoes.php' ? 'active' : '' ?>" href="configuracoes.php">
                                <i class="bi bi-sliders me-2"></i>
                                Configurações
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $current_page === 'usuarios.php' ? 'active' : '' ?>" href="usuarios.php">
                                <i class="bi bi-people me-2"></i>
                                Usuários
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $current_page === 'perfil.php' ? 'active' : '' ?>" href="perfil.php">
                                <i class="bi bi-person me-2"></i>
                                Meu Perfil
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $current_page === 'backup.php' ? 'active' : '' ?>" href="backup.php">
                                <i class="bi bi-database me-2"></i>
                                Backup
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $current_page === 'logs.php' ? 'active' : '' ?>" href="logs.php">
                                <i class="bi bi-clock-history me-2"></i>
                                Logs do Sistema
                            </a>
                        </li>
                    </ul>
                </div>
            </li>

            <!-- Divider -->
            <li><hr class="dropdown-divider my-3"></li>

            <!-- Quick Actions -->
            <li class="nav-item">
                <div class="px-3 mb-2">
                    <small class="text-muted">Ações Rápidas</small>
                </div>
            </li>
            
            <li class="nav-item">
                <a class="nav-link text-warning" href="cupons.php?acao=novo">
                    <i class="bi bi-plus-circle me-2"></i>
                    Novo Cupom
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link text-info" href="produtos.php?acao=novo">
                    <i class="bi bi-plus-circle me-2"></i>
                    Novo Produto
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link text-success" href="api-sync.php">
                    <i class="bi bi-cloud-arrow-down me-2"></i>
                    Sincronizar APIs
                </a>
            </li>

            <!-- Divider -->
            <li><hr class="dropdown-divider my-3"></li>

            <!-- Alerts -->
            <?php if ($contadores['cupons_expirando'] > 0): ?>
            <li class="nav-item">
                <a class="nav-link text-warning" href="cupons.php?filtro=expirando">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Cupons Expirando
                    <span class="badge bg-warning float-end"><?= $contadores['cupons_expirando'] ?></span>
                </a>
            </li>
            <?php endif; ?>

            <!-- Site Link -->
            <li class="nav-item">
                <a class="nav-link text-light bg-primary rounded mx-2 mt-2 text-center" href="../index.php" target="_blank">
                    <i class="bi bi-eye me-2"></i>
                    Ver Site Público
                </a>
            </li>

        </ul>

        <!-- Sidebar Footer -->
        <div class="sidebar-footer mt-auto p-3 border-top border-secondary">
            <div class="small text-muted">
                <!-- System Status -->
                <div class="d-flex align-items-center mb-2">
                    <i class="bi bi-circle-fill text-success me-2" id="sidebarStatus"></i>
                    <span>Sistema Online</span>
                </div>
                
                <!-- Memory Usage -->
                <div class="d-flex justify-content-between">
                    <span>Memória:</span>
                    <span id="sidebarMemory"><?= number_format(memory_get_usage() / 1024 / 1024, 1) ?>MB</span>
                </div>
                
                <!-- Version -->
                <div class="d-flex justify-content-between">
                    <span>Versão:</span>
                    <span>v1.0.0</span>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.sidebar {
    min-height: 100vh;
    background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
    box-shadow: 2px 0 10px rgba(0,0,0,0.1);
}

.sidebar .nav-link {
    color: #bdc3c7;
    padding: 12px 15px;
    border-radius: 8px;
    margin: 2px 8px;
    transition: all 0.3s ease;
    position: relative;
}

.sidebar .nav-link:hover {
    color: #ecf0f1;
    background: rgba(255,255,255,0.1);
    transform: translateX(5px);
}

.sidebar .nav-link.active {
    color: #fff;
    background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
    box-shadow: 0 4px 15px rgba(52, 152, 219, 0.3);
}

.sidebar .nav-link .badge {
    font-size: 0.7em;
    padding: 4px 6px;
}

.sidebar .collapse .nav-link {
    margin: 1px 0;
    padding: 8px 15px 8px 30px;
    font-size: 0.9em;
}

.sidebar .toggle-icon {
    transition: transform 0.3s ease;
    font-size: 0.8em;
}

.sidebar .nav-link[aria-expanded="true"] .toggle-icon {
    transform: rotate(180deg);
}

.sidebar-footer {
    background: rgba(0,0,0,0.2);
    border-radius: 8px;
    margin: 8px;
}

/* Mobile responsive */
@media (max-width: 768px) {
    .sidebar {
        position: fixed;
        top: 0;
        left: -100%;
        width: 280px;
        z-index: 1040;
        transition: left 0.3s ease;
    }
    
    .sidebar.show {
        left: 0;
    }
    
    .sidebar-backdrop {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: 1039;
    }
}

/* Scrollbar personalizado */
.sidebar-sticky {
    height: 100vh;
    overflow-y: auto;
}

.sidebar-sticky::-webkit-scrollbar {
    width: 4px;
}

.sidebar-sticky::-webkit-scrollbar-track {
    background: rgba(255,255,255,0.1);
}

.sidebar-sticky::-webkit-scrollbar-thumb {
    background: rgba(255,255,255,0.3);
    border-radius: 2px;
}

.sidebar-sticky::-webkit-scrollbar-thumb:hover {
    background: rgba(255,255,255,0.5);
}
</style>

<script>
// Sidebar functionality
document.addEventListener('DOMContentLoaded', function() {
    // Toggle sidebar on mobile
    const sidebar = document.getElementById('sidebarMenu');
    const sidebarToggle = document.querySelector('[data-bs-target="#sidebarMenu"]');
    
    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.toggle('show');
            
            // Add backdrop on mobile
            if (window.innerWidth < 768) {
                if (sidebar.classList.contains('show')) {
                    const backdrop = document.createElement('div');
                    backdrop.className = 'sidebar-backdrop';
                    backdrop.addEventListener('click', function() {
                        sidebar.classList.remove('show');
                        backdrop.remove();
                    });
                    document.body.appendChild(backdrop);
                } else {
                    const backdrop = document.querySelector('.sidebar-backdrop');
                    if (backdrop) backdrop.remove();
                }
            }
        });
    }
    
    // Auto-close sidebar on mobile when clicking a link
    const sidebarLinks = sidebar.querySelectorAll('.nav-link');
    sidebarLinks.forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth < 768) {
                sidebar.classList.remove('show');
                const backdrop = document.querySelector('.sidebar-backdrop');
                if (backdrop) backdrop.remove();
            }
        });
    });
    
    // Update sidebar status
    function updateSidebarStatus() {
        fetch('ajax/system-status.php')
            .then(response => response.json())
            .then(data => {
                const statusElement = document.getElementById('sidebarStatus');
                if (data.status === 'online') {
                    statusElement.className = 'bi bi-circle-fill text-success me-2';
                    statusElement.nextElementSibling.textContent = 'Sistema Online';
                } else if (data.status === 'maintenance') {
                    statusElement.className = 'bi bi-circle-fill text-warning me-2';
                    statusElement.nextElementSibling.textContent = 'Em Manutenção';
                } else {
                    statusElement.className = 'bi bi-circle-fill text-danger me-2';
                    statusElement.nextElementSibling.textContent = 'Sistema Offline';
                }
            })
            .catch(error => {
                console.error('Erro ao atualizar status:', error);
            });
    }
    
    // Update memory usage
    function updateSidebarMemory() {
        const memoryElement = document.getElementById('sidebarMemory');
        if (memoryElement) {
            fetch('ajax/system-info.php')
                .then(response => response.json())
                .then(data => {
                    memoryElement.textContent = data.memory_usage + 'MB';
                })
                .catch(error => {
                    console.error('Erro ao atualizar memória:', error);
                });
        }
    }
    
    // Initialize
    updateSidebarStatus();
    updateSidebarMemory();
    
    // Update periodically
    setInterval(updateSidebarStatus, 30000);
    setInterval(updateSidebarMemory, 10000);
    
    // Keyboard shortcuts for sidebar
    document.addEventListener('keydown', function(e) {
        // Ctrl+B to toggle sidebar
        if (e.ctrlKey && e.key === 'b') {
            e.preventDefault();
            sidebar.classList.toggle('show');
        }
        
        // Escape to close sidebar
        if (e.key === 'Escape' && window.innerWidth < 768) {
            sidebar.classList.remove('show');
            const backdrop = document.querySelector('.sidebar-backdrop');
            if (backdrop) backdrop.remove();
        }
    });
    
    // Save sidebar state to localStorage
    function saveSidebarState() {
        const expandedSections = [];
        document.querySelectorAll('.collapse.show').forEach(collapse => {
            expandedSections.push(collapse.id);
        });
        localStorage.setItem('sidebarExpanded', JSON.stringify(expandedSections));
    }
    
    // Load sidebar state from localStorage
    function loadSidebarState() {
        const savedState = localStorage.getItem('sidebarExpanded');
        if (savedState) {
            const expandedSections = JSON.parse(savedState);
            expandedSections.forEach(sectionId => {
                const section = document.getElementById(sectionId);
                if (section) {
                    const trigger = document.querySelector(`[href="#${sectionId}"]`);
                    if (trigger) {
                        trigger.classList.remove('collapsed');
                        trigger.setAttribute('aria-expanded', 'true');
                    }
                    section.classList.add('show');
                }
            });
        }
    }
    
    // Save state when sections are toggled
    document.querySelectorAll('[data-bs-toggle="collapse"]').forEach(trigger => {
        trigger.addEventListener('click', function() {
            setTimeout(saveSidebarState, 300);
        });
    });
    
    // Load saved state on page load
    loadSidebarState();
    
    // Highlight current page in sidebar
    function highlightCurrentPage() {
        const currentPage = '<?= $current_page ?>';
        const currentSection = '<?= $current_section ?>';
        
        // Remove all active classes first
        document.querySelectorAll('.sidebar .nav-link').forEach(link => {
            link.classList.remove('active');
        });
        
        // Add active class to current page
        const currentLink = document.querySelector(`.sidebar a[href="${currentPage}"]`);
        if (currentLink) {
            currentLink.classList.add('active');
            
            // Ensure parent section is expanded
            const parentSection = currentLink.closest('.collapse');
            if (parentSection) {
                const trigger = document.querySelector(`[href="#${parentSection.id}"]`);
                if (trigger) {
                    trigger.classList.remove('collapsed');
                    trigger.setAttribute('aria-expanded', 'true');
                    parentSection.classList.add('show');
                }
            }
        }
    }
    
    // Apply highlighting
    highlightCurrentPage();
});

// Smooth scrolling for sidebar
document.querySelectorAll('.sidebar a[href^="#"]').forEach(anchor => {
    anchor.addEventListener('click', function (e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            target.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    });
});
</script>