<?php
// Arquivo footer.php - Rodapé da área administrativa
?>

<!-- Footer -->
<footer class="footer mt-auto py-3 bg-dark text-light">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-6">
                <div class="d-flex align-items-center">
                    <span class="text-muted">
                        <i class="bi bi-cpu me-1"></i>
                        <strong>TTaTim Cupons</strong> - Sistema Administrativo
                    </span>
                    <span class="badge bg-primary ms-2">v1.0</span>
                </div>
            </div>
            <div class="col-md-6 text-end">
                <div class="d-flex justify-content-end align-items-center">
                    <!-- Status do Sistema -->
                    <div class="me-3">
                        <small class="text-muted">
                            <i class="bi bi-circle-fill text-success me-1" id="systemStatus"></i>
                            <span id="statusText">Sistema Online</span>
                        </small>
                    </div>
                    
                    <!-- Informações de Performance -->
                    <div class="me-3 d-none d-md-block">
                        <small class="text-muted">
                            <i class="bi bi-speedometer2 me-1"></i>
                            <span id="loadTime"><?= number_format(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 3) ?>s</span>
                        </small>
                    </div>
                    
                    <!-- Memória Usage -->
                    <div class="me-3 d-none d-lg-block">
                        <small class="text-muted">
                            <i class="bi bi-memory me-1"></i>
                            <span id="memoryUsage"><?= number_format(memory_get_usage() / 1024 / 1024, 2) ?>MB</span>
                        </small>
                    </div>
                    
                    <!-- Data/Hora -->
                    <div>
                        <small class="text-muted">
                            <i class="bi bi-clock me-1"></i>
                            <span id="currentTime"><?= date('H:i:s') ?></span>
                        </small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Links do Footer -->
        <div class="row mt-2 pt-2 border-top border-secondary">
            <div class="col-md-8">
                <div class="d-flex flex-wrap">
                    <a href="../index.php" class="text-muted text-decoration-none me-3 mb-1" target="_blank">
                        <i class="bi bi-eye me-1"></i>Ver Site
                    </a>
                    <a href="configuracoes.php" class="text-muted text-decoration-none me-3 mb-1">
                        <i class="bi bi-gear me-1"></i>Configurações
                    </a>
                    <a href="estatisticas.php" class="text-muted text-decoration-none me-3 mb-1">
                        <i class="bi bi-graph-up me-1"></i>Estatísticas
                    </a>
                    <a href="api-sync.php" class="text-muted text-decoration-none me-3 mb-1">
                        <i class="bi bi-cloud-arrow-down me-1"></i>Sincronização
                    </a>
                    <a href="#" class="text-muted text-decoration-none me-3 mb-1" data-bs-toggle="modal" data-bs-target="#modalHelp">
                        <i class="bi bi-question-circle me-1"></i>Ajuda
                    </a>
                </div>
            </div>
            <div class="col-md-4 text-end">
                <small class="text-muted">
                    &copy; <?= date('Y') ?> TTaTim Cupons. Todos os direitos reservados.
                </small>
            </div>
        </div>
    </div>
</footer>

<!-- Modal de Ajuda -->
<div class="modal fade" id="modalHelp" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-question-circle me-2"></i>Central de Ajuda
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6>Suporte Rápido</h6>
                        <ul class="list-unstyled">
                            <li class="mb-2">
                                <a href="cupons.php" class="text-decoration-none">
                                    <i class="bi bi-ticket-perforated me-2"></i>Como gerenciar cupons?
                                </a>
                            </li>
                            <li class="mb-2">
                                <a href="produtos.php" class="text-decoration-none">
                                    <i class="bi bi-box me-2"></i>Como adicionar produtos?
                                </a>
                            </li>
                            <li class="mb-2">
                                <a href="api-sync.php" class="text-decoration-none">
                                    <i class="bi bi-cloud-arrow-down me-2"></i>Como sincronizar com APIs?
                                </a>
                            </li>
                            <li class="mb-2">
                                <a href="estatisticas.php" class="text-decoration-none">
                                    <i class="bi bi-graph-up me-2"></i>Como visualizar estatísticas?
                                </a>
                            </li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6>Contato & Suporte</h6>
                        <div class="mb-3">
                            <strong>Email de Suporte:</strong><br>
                            <a href="mailto:support@ttatim.com" class="text-decoration-none">support@ttatim.com</a>
                        </div>
                        <div class="mb-3">
                            <strong>Documentação:</strong><br>
                            <a href="#" class="text-decoration-none">Guia do Usuário</a>
                        </div>
                        <div>
                            <strong>Status do Sistema:</strong><br>
                            <span class="badge bg-success">Operacional</span>
                        </div>
                    </div>
                </div>
                
                <div class="mt-4 p-3 bg-light rounded">
                    <h6>Dicas Rápidas</h6>
                    <ul class="small mb-0">
                        <li>Use <kbd>Ctrl + S</kbd> para salvar rapidamente nos formulários</li>
                        <li>Clique no logo para voltar ao Dashboard</li>
                        <li>Use os filtros para encontrar conteúdo rapidamente</li>
                    </ul>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                <a href="mailto:support@ttatim.com" class="btn btn-primary">
                    <i class="bi bi-envelope me-1"></i>Contatar Suporte
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Informações do Sistema -->
<div class="modal fade" id="modalSystemInfo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-info-circle me-2"></i>Informações do Sistema
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-6">
                        <strong>PHP Version:</strong>
                    </div>
                    <div class="col-6">
                        <?= phpversion() ?>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-6">
                        <strong>MySQL Version:</strong>
                    </div>
                    <div class="col-6">
                        <?= $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) ?>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-6">
                        <strong>Server Software:</strong>
                    </div>
                    <div class="col-6">
                        <?= $_SERVER['SERVER_SOFTWARE'] ?>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-6">
                        <strong>Memory Usage:</strong>
                    </div>
                    <div class="col-6">
                        <?= number_format(memory_get_usage() / 1024 / 1024, 2) ?> MB
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-6">
                        <strong>Peak Memory:</strong>
                    </div>
                    <div class="col-6">
                        <?= number_format(memory_get_peak_usage() / 1024 / 1024, 2) ?> MB
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-6">
                        <strong>Load Time:</strong>
                    </div>
                    <div class="col-6">
                        <?= number_format(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 3) ?>s
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
            </div>
        </div>
    </div>
</div>

<!-- Scripts do Footer -->
<script>
// Atualizar relógio em tempo real
function updateClock() {
    const now = new Date();
    const timeString = now.toLocaleTimeString('pt-BR', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit'
    });
    document.getElementById('currentTime').textContent = timeString;
}

// Atualizar uso de memória
function updateMemoryUsage() {
    fetch('ajax/system-info.php')
        .then(response => response.json())
        .then(data => {
            if (data.memory_usage) {
                document.getElementById('memoryUsage').textContent = data.memory_usage + 'MB';
            }
            if (data.load_time) {
                document.getElementById('loadTime').textContent = data.load_time + 's';
            }
            if (data.system_status) {
                const statusElement = document.getElementById('systemStatus');
                const statusText = document.getElementById('statusText');
                
                if (data.system_status === 'online') {
                    statusElement.className = 'bi bi-circle-fill text-success me-1';
                    statusText.textContent = 'Sistema Online';
                } else {
                    statusElement.className = 'bi bi-circle-fill text-warning me-1';
                    statusText.textContent = 'Sistema Instável';
                }
            }
        })
        .catch(error => {
            console.error('Erro ao atualizar informações do sistema:', error);
        });
}

// Verificar status do sistema
function checkSystemStatus() {
    fetch('ajax/system-status.php')
        .then(response => response.json())
        .then(data => {
            const statusElement = document.getElementById('systemStatus');
            const statusText = document.getElementById('statusText');
            
            if (data.status === 'online') {
                statusElement.className = 'bi bi-circle-fill text-success me-1';
                statusText.textContent = 'Sistema Online';
            } else if (data.status === 'maintenance') {
                statusElement.className = 'bi bi-circle-fill text-warning me-1';
                statusText.textContent = 'Em Manutenção';
            } else {
                statusElement.className = 'bi bi-circle-fill text-danger me-1';
                statusText.textContent = 'Sistema Offline';
            }
        })
        .catch(error => {
            console.error('Erro ao verificar status do sistema:', error);
            document.getElementById('systemStatus').className = 'bi bi-circle-fill text-danger me-1';
            document.getElementById('statusText').textContent = 'Erro de Conexão';
        });
}

// Atalhos de teclado
document.addEventListener('keydown', function(e) {
    // Ctrl + S para salvar
    if (e.ctrlKey && e.key === 's') {
        e.preventDefault();
        const saveButton = document.querySelector('button[type="submit"], .btn-primary');
        if (saveButton) {
            saveButton.click();
        }
    }
    
    // F1 para ajuda
    if (e.key === 'F1') {
        e.preventDefault();
        const helpModal = new bootstrap.Modal(document.getElementById('modalHelp'));
        helpModal.show();
    }
    
    // F2 para informações do sistema
    if (e.key === 'F2') {
        e.preventDefault();
        const systemModal = new bootstrap.Modal(document.getElementById('modalSystemInfo'));
        systemModal.show();
    }
});

// Inicializar quando o DOM estiver carregado
document.addEventListener('DOMContentLoaded', function() {
    // Iniciar relógio
    updateClock();
    setInterval(updateClock, 1000);
    
    // Verificar status do sistema
    checkSystemStatus();
    setInterval(checkSystemStatus, 30000); // A cada 30 segundos
    
    // Atualizar uso de memória
    updateMemoryUsage();
    setInterval(updateMemoryUsage, 10000); // A cada 10 segundos
    
    // Adicionar tooltips
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});

// Função para exibir notificações
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
    notification.style.cssText = `
        top: 20px;
        right: 20px;
        z-index: 9999;
        min-width: 300px;
    `;
    notification.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    document.body.appendChild(notification);
    
    // Auto-remover após 5 segundos
    setTimeout(() => {
        if (notification.parentNode) {
            notification.remove();
        }
    }, 5000);
}

// Verificar se há atualizações
function checkForUpdates() {
    fetch('ajax/check-updates.php')
        .then(response => response.json())
        .then(data => {
            if (data.update_available) {
                showNotification(
                    `Nova versão ${data.latest_version} disponível! <a href="configuracoes.php" class="alert-link">Atualizar agora</a>`,
                    'warning'
                );
            }
        })
        .catch(error => console.error('Erro ao verificar atualizações:', error));
}

// Verificar atualizações a cada hora
setInterval(checkForUpdates, 3600000);

// Verificar atualizações na inicialização
setTimeout(checkForUpdates, 5000);
</script>

<!-- Scripts de Analytics (Opcional) -->
<script>
// Google Analytics (se configurado)
<?php if (isset($configuracoes['seo_config']['google_analytics']) && !empty($configuracoes['seo_config']['google_analytics'])): ?>
    <?= $configuracoes['seo_config']['google_analytics'] ?>
<?php endif; ?>

// Hotjar (se configurado)
<?php if (isset($configuracoes['seo_config']['hotjar']) && !empty($configuracoes['seo_config']['hotjar'])): ?>
    (function(h,o,t,j,a,r){
        h.hj=h.hj||function(){(h.hj.q=h.hj.q||[]).push(arguments)};
        h._hjSettings={hjid:<?= $configuracoes['seo_config']['hotjar'] ?>,hjsv:6};
        a=o.getElementsByTagName('head')[0];
        r=o.createElement('script');r.async=1;
        r.src=t+h._hjSettings.hjid+j+h._hjSettings.hjsv;
        a.appendChild(r);
    })(window,document,'https://static.hotjar.com/c/hotjar-','.js?sv=');
<?php endif; ?>
</script>

</body>
</html>