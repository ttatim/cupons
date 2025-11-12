/**
 * main.js - Scripts principais do TTaTim Cupons
 * @version 1.0.0
 */

// ===== CONSTANTES E CONFIGURAÇÕES =====
const CONFIG = {
    API_BASE_URL: window.location.origin + '/ajax/',
    DEBOUNCE_DELAY: 300,
    LAZY_LOAD_THRESHOLD: 100,
    MODAL_BACKDROP: true,
    NOTIFICATION_TIMEOUT: 5000
};

// ===== CLASSE PRINCIPAL =====
class TTaTimCupons {
    constructor() {
        this.currentCupomId = null;
        this.initialized = false;
        this.observers = [];
    }

    // ===== INICIALIZAÇÃO =====
    init() {
        if (this.initialized) return;
        
        try {
            this.bindEvents();
            this.initComponents();
            this.setupObservers();
            this.initialized = true;
            
            console.log('TTaTim Cupons inicializado com sucesso');
        } catch (error) {
            console.error('Erro na inicialização:', error);
        }
    }

    // ===== EVENT BINDING =====
    bindEvents() {
        // Eventos de cupons
        document.addEventListener('click', this.handleCupomClick.bind(this));
        
        // Eventos de busca
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('input', 
                this.debounce(this.handleSearch.bind(this), CONFIG.DEBOUNCE_DELAY)
            );
        }

        // Eventos de formulário
        document.addEventListener('submit', this.handleFormSubmit.bind(this));
        
        // Eventos de teclado
        document.addEventListener('keydown', this.handleKeyboard.bind(this));
        
        // Eventos de scroll
        window.addEventListener('scroll', 
            this.throttle(this.handleScroll.bind(this), 200)
        );

        // Eventos de visibilidade
        document.addEventListener('visibilitychange', 
            this.handleVisibilityChange.bind(this)
        );
    }

    // ===== COMPONENTES =====
    initComponents() {
        this.initTooltips();
        this.initModals();
        this.initNotifications();
        this.initLazyLoading();
        this.initCopyToClipboard();
    }

    // ===== CUPONS =====
    handleCupomClick(e) {
        const target = e.target;
        
        // Botão "Me leve para a loja"
        if (target.closest('.btn-red')) {
            const card = target.closest('.offer-card, .product-card');
            if (card) {
                const cupomId = card.dataset.cupomId || 
                               card.querySelector('[data-cupom-id]')?.dataset.cupomId;
                if (cupomId) {
                    this.abrirCupom(parseInt(cupomId));
                }
            }
            e.preventDefault();
        }

        // Botão copiar código
        if (target.closest('.btn-copy') || target.classList.contains('btn-copy')) {
            const cupomCode = target.dataset.code || 
                             target.closest('[data-code]')?.dataset.code ||
                             target.previousElementSibling?.value;
            if (cupomCode) {
                this.copiarCodigo(cupomCode);
            }
            e.preventDefault();
        }
    }

    async abrirCupom(cupomId) {
        try {
            this.showLoading('modalCupomContent');
            
            const response = await fetch(`${CONFIG.API_BASE_URL}cupom.php?id=${cupomId}`);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const data = await response.json();
            
            if (data.error) {
                this.showError(data.error);
                return;
            }

            this.currentCupomId = cupomId;
            this.exibirModalCupom(data);
            this.registrarClique('cupom', cupomId);
            
        } catch (error) {
            console.error('Erro ao carregar cupom:', error);
            this.showError('Erro ao carregar cupom. Tente novamente.');
        }
    }

    exibirModalCupom(data) {
        const modalContent = document.getElementById('modalCupomContent');
        if (!modalContent) return;

        const desconto = data.tipo === 'percentual' ? 
            `${data.valor}%` : 
            `R$ ${this.formatarMoeda(data.valor)}`;

        const template = `
            <div class="cupom-detalhes">
                <div class="row">
                    <div class="col-md-8">
                        <div class="d-flex align-items-center mb-3">
                            <span class="badge bg-success discount-badge me-3">${desconto} OFF</span>
                            <small class="text-muted">
                                <i class="bi bi-clock me-1"></i>
                                Válido até: ${this.formatarData(data.data_fim)}
                            </small>
                        </div>
                        
                        <h4 class="text-success mb-3">${this.escapeHtml(data.descricao)}</h4>
                        
                        ${data.detalhes ? `
                            <div class="mb-4">
                                <p class="text-muted">${this.escapeHtml(data.detalhes)}</p>
                            </div>
                        ` : ''}

                        <div class="cupom-code-section mb-4">
                            <label class="form-label fw-bold">Código do Cupom:</label>
                            <div class="input-group input-group-lg">
                                <input type="text" 
                                       class="form-control text-center font-monospace fw-bold" 
                                       value="${this.escapeHtml(data.codigo)}" 
                                       readonly 
                                       id="cupomCodeInput">
                                <button class="btn btn-primary" type="button" onclick="app.copiarCodigo('${this.escapeHtml(data.codigo)}')">
                                    <i class="bi bi-clipboard"></i> Copiar
                                </button>
                            </div>
                        </div>

                        <div class="instrucoes">
                            <h6 class="mb-2">Como usar:</h6>
                            <ol class="small text-muted">
                                <li>Clique em "Me leve para a loja"</li>
                                <li>Adicione produtos ao carrinho</li>
                                <li>Cole o código na etapa de pagamento</li>
                                <li>Aproveite o desconto!</li>
                            </ol>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="loja-info text-center p-3 bg-light rounded">
                            ${data.loja_logo ? `
                                <img src="${data.loja_logo}" 
                                     alt="${this.escapeHtml(data.loja_nome)}" 
                                     class="img-fluid rounded mb-3" 
                                     style="max-height: 80px;">
                            ` : `
                                <div class="bg-primary rounded-circle d-inline-flex align-items-center justify-content-center mb-3" 
                                     style="width: 80px; height: 80px;">
                                    <i class="bi bi-shop text-white" style="font-size: 2rem;"></i>
                                </div>
                            `}
                            <h5>${this.escapeHtml(data.loja_nome)}</h5>
                            <p class="text-muted small">Loja parceira</p>
                        </div>
                    </div>
                </div>
                
                <div class="row mt-4">
                    <div class="col-12">
                        <button class="btn btn-red btn-lg w-100 py-3" onclick="app.irParaLoja('${this.escapeHtml(data.link)}')">
                            <i class="bi bi-arrow-right-circle me-2"></i>
                            Me leve para a loja
                        </button>
                    </div>
                </div>
            </div>
        `;

        modalContent.innerHTML = template;
        
        // Mostrar modal
        const modal = new bootstrap.Modal(document.getElementById('cupomModal'));
        modal.show();
    }

    // ===== PRODUTOS =====
    async abrirProduto(produtoId) {
        try {
            const response = await fetch(`${CONFIG.API_BASE_URL}produto.php?id=${produtoId}`);
            const data = await response.json();
            
            if (data.link) {
                this.irParaLoja(data.link);
                this.registrarClique('produto', produtoId);
            }
        } catch (error) {
            console.error('Erro ao abrir produto:', error);
            this.showError('Erro ao carregar produto.');
        }
    }

    // ===== CLIPBOARD =====
    async copiarCodigo(codigo) {
        try {
            await navigator.clipboard.writeText(codigo);
            this.showNotification('Código copiado para a área de transferência!', 'success');
            
            // Feedback visual
            const buttons = document.querySelectorAll(`[data-code="${codigo}"]`);
            buttons.forEach(btn => {
                const originalHTML = btn.innerHTML;
                btn.innerHTML = '<i class="bi bi-check"></i> Copiado!';
                btn.classList.add('btn-success');
                btn.classList.remove('btn-outline-primary');
                
                setTimeout(() => {
                    btn.innerHTML = originalHTML;
                    btn.classList.remove('btn-success');
                    btn.classList.add('btn-outline-primary');
                }, 2000);
            });
            
        } catch (err) {
            // Fallback para navegadores antigos
            this.fallbackCopyToClipboard(codigo);
        }
    }

    fallbackCopyToClipboard(text) {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.left = '-999999px';
        textArea.style.top = '-999999px';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        
        try {
            document.execCommand('copy');
            this.showNotification('Código copiado!', 'success');
        } catch (err) {
            this.showNotification('Erro ao copiar código.', 'error');
        }
        
        document.body.removeChild(textArea);
    }

    // ===== NAVEGAÇÃO =====
    irParaLoja(url) {
        if (!url || url === '#') {
            this.showError('Link não disponível.');
            return;
        }

        // Registrar clique antes de redirecionar
        if (this.currentCupomId) {
            this.registrarClique('cupom', this.currentCupomId);
        }

        // Abrir em nova aba
        window.open(url, '_blank');
        
        // Fechar modal se estiver aberto
        const modal = bootstrap.Modal.getInstance(document.getElementById('cupomModal'));
        if (modal) {
            modal.hide();
        }
    }

    // ===== BUSCA =====
    handleSearch(e) {
        const termo = e.target.value.trim();
        
        if (termo.length < 2) {
            this.limparResultadosBusca();
            return;
        }

        this.buscarOfertas(termo);
    }

    async buscarOfertas(termo) {
        try {
            this.showLoading('searchResults');
            
            const response = await fetch(`${CONFIG.API_BASE_URL}busca.php?q=${encodeURIComponent(termo)}`);
            const data = await response.json();
            
            this.exibirResultadosBusca(data);
        } catch (error) {
            console.error('Erro na busca:', error);
            this.showError('Erro na busca. Tente novamente.');
        }
    }

    exibirResultadosBusca(resultados) {
        const container = document.getElementById('searchResults');
        if (!container) return;

        if (!resultados || resultados.length === 0) {
            container.innerHTML = `
                <div class="text-center py-4">
                    <i class="bi bi-search display-4 text-muted"></i>
                    <p class="mt-3 text-muted">Nenhum resultado encontrado</p>
                </div>
            `;
            return;
        }

        let html = '<div class="row">';
        
        resultados.forEach(item => {
            html += `
                <div class="col-md-6 col-lg-4 mb-3">
                    <div class="card h-100">
                        <div class="card-body">
                            <h6 class="card-title">${this.escapeHtml(item.titulo)}</h6>
                            <p class="card-text small text-muted">${this.escapeHtml(item.descricao)}</p>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="badge bg-primary">${item.tipo}</span>
                                <button class="btn btn-sm btn-outline-primary" onclick="app.abrir${item.tipo.charAt(0).toUpperCase() + item.tipo.slice(1)}(${item.id})">
                                    Ver Detalhes
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        });
        
        html += '</div>';
        container.innerHTML = html;
    }

    limparResultadosBusca() {
        const container = document.getElementById('searchResults');
        if (container) {
            container.innerHTML = '';
        }
    }

    // ===== ESTATÍSTICAS =====
    async registrarClique(tipo, itemId) {
        try {
            await fetch(`${CONFIG.API_BASE_URL}estatisticas.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    tipo: tipo,
                    item_id: itemId,
                    acao: 'clique'
                })
            });
        } catch (error) {
            console.error('Erro ao registrar clique:', error);
        }
    }

    // ===== MODAIS =====
    initModals() {
        const modals = document.querySelectorAll('.modal');
        modals.forEach(modal => {
            modal.addEventListener('show.bs.modal', () => {
                document.body.classList.add('modal-open');
            });
            
            modal.addEventListener('hidden.bs.modal', () => {
                document.body.classList.remove('modal-open');
                this.currentCupomId = null;
            });
        });
    }

    // ===== NOTIFICAÇÕES =====
    initNotifications() {
        // Criar container de notificações se não existir
        if (!document.getElementById('notifications')) {
            const container = document.createElement('div');
            container.id = 'notifications';
            container.className = 'position-fixed top-0 end-0 p-3';
            container.style.zIndex = '9999';
            document.body.appendChild(container);
        }
    }

    showNotification(mensagem, tipo = 'info') {
        const container = document.getElementById('notifications');
        if (!container) return;

        const tipos = {
            success: { class: 'alert-success', icon: 'bi-check-circle' },
            error: { class: 'alert-danger', icon: 'bi-exclamation-triangle' },
            warning: { class: 'alert-warning', icon: 'bi-exclamation-circle' },
            info: { class: 'alert-info', icon: 'bi-info-circle' }
        };

        const config = tipos[tipo] || tipos.info;
        const id = 'notification-' + Date.now();

        const notification = document.createElement('div');
        notification.id = id;
        notification.className = `alert ${config.class} alert-dismissible fade show`;
        notification.style.minWidth = '300px';
        notification.style.marginBottom = '10px';
        notification.innerHTML = `
            <i class="${config.icon} me-2"></i>
            ${this.escapeHtml(mensagem)}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;

        container.appendChild(notification);

        // Auto-remover
        setTimeout(() => {
            const element = document.getElementById(id);
            if (element) {
                element.remove();
            }
        }, CONFIG.NOTIFICATION_TIMEOUT);
    }

    // ===== TOOLTIPS =====
    initTooltips() {
        const tooltipTriggerList = [].slice.call(
            document.querySelectorAll('[data-bs-toggle="tooltip"]')
        );
        
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl, {
                trigger: 'hover focus'
            });
        });
    }

    // ===== LAZY LOADING =====
    initLazyLoading() {
        if ('IntersectionObserver' in window) {
            const lazyObserver = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        img.src = img.dataset.src;
                        img.classList.remove('lazy-load');
                        lazyObserver.unobserve(img);
                    }
                });
            });

            document.querySelectorAll('img[data-src]').forEach(img => {
                lazyObserver.observe(img);
            });
        }
    }

    // ===== FORMULÁRIOS =====
    handleFormSubmit(e) {
        const form = e.target;
        
        // Validação básica
        if (!this.validarFormulario(form)) {
            e.preventDefault();
            return;
        }

        // Adicionar loading state
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) {
            this.setLoadingState(submitBtn, true);
        }
    }

    validarFormulario(form) {
        const required = form.querySelectorAll('[required]');
        let isValid = true;

        required.forEach(field => {
            if (!field.value.trim()) {
                this.mostrarErroCampo(field, 'Este campo é obrigatório');
                isValid = false;
            } else {
                this.limparErroCampo(field);
            }
        });

        return isValid;
    }

    mostrarErroCampo(campo, mensagem) {
        campo.classList.add('is-invalid');
        
        let feedback = campo.nextElementSibling;
        if (!feedback || !feedback.classList.contains('invalid-feedback')) {
            feedback = document.createElement('div');
            feedback.className = 'invalid-feedback';
            campo.parentNode.appendChild(feedback);
        }
        
        feedback.textContent = mensagem;
    }

    limparErroCampo(campo) {
        campo.classList.remove('is-invalid');
        campo.classList.add('is-valid');
    }

    // ===== SCROLL =====
    handleScroll() {
        // Header sticky
        const header = document.querySelector('.header');
        if (header) {
            if (window.scrollY > 100) {
                header.classList.add('header-scrolled');
            } else {
                header.classList.remove('header-scrolled');
            }
        }

        // Back to top button
        const backToTop = document.getElementById('backToTop');
        if (backToTop) {
            if (window.scrollY > 300) {
                backToTop.classList.add('show');
            } else {
                backToTop.classList.remove('show');
            }
        }

        // Lazy loading de elementos
        this.lazyLoadElements();
    }

    lazyLoadElements() {
        const elements = document.querySelectorAll('.lazy-load');
        elements.forEach(element => {
            const rect = element.getBoundingClientRect();
            if (rect.top < window.innerHeight + CONFIG.LAZY_LOAD_THRESHOLD) {
                element.classList.add('lazy-loaded');
            }
        });
    }

    // ===== KEYBOARD SHORTCUTS =====
    handleKeyboard(e) {
        // Ctrl + K para focar na busca
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            const searchInput = document.getElementById('searchInput');
            if (searchInput) {
                searchInput.focus();
            }
        }

        // Escape para fechar modais
        if (e.key === 'Escape') {
            const modals = document.querySelectorAll('.modal.show');
            modals.forEach(modal => {
                const bsModal = bootstrap.Modal.getInstance(modal);
                if (bsModal) {
                    bsModal.hide();
                }
            });
        }
    }

    // ===== VISIBILIDADE =====
    handleVisibilityChange() {
        if (!document.hidden) {
            // Página voltou ao foco
            this.atualizarDados();
        }
    }

    // ===== UTILITÁRIOS =====
    debounce(func, wait) {
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

    throttle(func, limit) {
        let inThrottle;
        return function(...args) {
            if (!inThrottle) {
                func.apply(this, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    }

    escapeHtml(unsafe) {
        if (unsafe === null || unsafe === undefined) return '';
        return unsafe
            .toString()
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    formatarMoeda(valor) {
        return parseFloat(valor).toLocaleString('pt-BR', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    formatarData(data) {
        return new Date(data).toLocaleDateString('pt-BR');
    }

    showLoading(elementId) {
        const element = document.getElementById(elementId);
        if (element) {
            element.innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Carregando...</span>
                    </div>
                    <p class="mt-2 text-muted">Carregando...</p>
                </div>
            `;
        }
    }

    showError(mensagem) {
        this.showNotification(mensagem, 'error');
    }

    setLoadingState(button, loading) {
        if (loading) {
            button.disabled = true;
            button.innerHTML = `
                <span class="spinner-border spinner-border-sm" role="status"></span>
                Carregando...
            `;
        } else {
            button.disabled = false;
            button.innerHTML = button.dataset.originalText || button.textContent;
        }
    }

    // ===== OBSERVERS =====
    setupObservers() {
        // Observer para elementos dinâmicos
        if ('MutationObserver' in window) {
            const observer = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    if (mutation.type === 'childList') {
                        this.initTooltips();
                        this.initCopyToClipboard();
                    }
                });
            });

            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        }
    }

    initCopyToClipboard() {
        // Re-inicializar botões de copiar que possam ter sido adicionados dinamicamente
        document.querySelectorAll('[data-copy]').forEach(btn => {
            btn.addEventListener('click', () => {
                const text = btn.dataset.copy;
                this.copiarCodigo(text);
            });
        });
    }

    // ===== ATUALIZAÇÃO DE DADOS =====
    async atualizarDados() {
        // Atualizar contadores se necessário
        const contadores = document.querySelectorAll('[data-counter]');
        if (contadores.length > 0) {
            // Implementar atualização de contadores
        }
    }

    // ===== DESTRUCTOR =====
    destroy() {
        this.initialized = false;
        this.observers.forEach(observer => {
            if (observer.disconnect) observer.disconnect();
        });
        this.observers = [];
    }
}

// ===== INICIALIZAÇÃO GLOBAL =====
const app = new TTaTimCupons();

// Inicializar quando o DOM estiver pronto
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => app.init());
} else {
    app.init();
}

// Expor app globalmente para uso em onclick
window.app = app;

// ===== FUNÇÕES GLOBAIS PARA USO EM HTML =====
function abrirCupom(id) {
    app.abrirCupom(id);
}

function abrirProduto(id) {
    app.abrirProduto(id);
}

function copiarCodigo(codigo) {
    app.copiarCodigo(codigo);
}

function irParaLoja(url) {
    app.irParaLoja(url);
}

// ===== POLYFILLS =====
// Polyfill para String.includes (para navegadores muito antigos)
if (!String.prototype.includes) {
    String.prototype.includes = function(search, start) {
        if (typeof start !== 'number') {
            start = 0;
        }
        if (start + search.length > this.length) {
            return false;
        }
        return this.indexOf(search, start) !== -1;
    };
}

// ===== ERROR HANDLING GLOBAL =====
window.addEventListener('error', (e) => {
    console.error('Erro global capturado:', e.error);
});

window.addEventListener('unhandledrejection', (e) => {
    console.error('Promise rejeitada não tratada:', e.reason);
    e.preventDefault();
});

// ===== PERFORMANCE MONITORING =====
if ('performance' in window) {
    window.addEventListener('load', () => {
        const perfData = performance.timing;
        const loadTime = perfData.loadEventEnd - perfData.navigationStart;
        console.log(`Tempo de carregamento: ${loadTime}ms`);
    });
}