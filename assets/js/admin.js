/**
 * admin.js - Scripts da área administrativa do TTaTim Cupons
 * @version 1.0.0
 */

// ===== CONSTANTES E CONFIGURAÇÕES =====
const ADMIN_CONFIG = {
    API_BASE_URL: '../ajax/',
    DEBOUNCE_DELAY: 300,
    AUTO_SAVE_DELAY: 2000,
    SESSION_TIMEOUT: 30 * 60 * 1000, // 30 minutos
    UPLOAD_MAX_SIZE: 5 * 1024 * 1024, // 5MB
    REFRESH_INTERVAL: 30000 // 30 segundos
};

// ===== CLASSE PRINCIPAL DO ADMIN =====
class TTaTimAdmin {
    constructor() {
        this.currentPage = this.getCurrentPage();
        this.unsavedChanges = false;
        this.autoSaveTimer = null;
        this.sessionTimer = null;
        this.initialized = false;
        this.charts = new Map();
    }

    // ===== INICIALIZAÇÃO =====
    init() {
        if (this.initialized) return;
        
        try {
            this.bindEvents();
            this.initComponents();
            this.startSessionTimer();
            this.setupAutoRefresh();
            this.initialized = true;
            
            console.log('TTaTim Admin inicializado');
        } catch (error) {
            console.error('Erro na inicialização do admin:', error);
        }
    }

    // ===== EVENT BINDING =====
    bindEvents() {
        // Eventos de formulário
        document.addEventListener('input', this.handleFormInput.bind(this));
        document.addEventListener('submit', this.handleFormSubmit.bind(this));
        
        // Eventos de navegação
        document.addEventListener('click', this.handleGlobalClick.bind(this));
        
        // Eventos de teclado
        document.addEventListener('keydown', this.handleKeyboard.bind(this));
        
        // Eventos de janela
        window.addEventListener('beforeunload', this.handleBeforeUnload.bind(this));
        window.addEventListener('resize', 
            this.throttle(this.handleResize.bind(this), 250)
        );

        // Eventos de upload
        document.addEventListener('change', this.handleFileUpload.bind(this));
    }

    // ===== COMPONENTES =====
    initComponents() {
        this.initSidebar();
        this.initTooltips();
        this.initModals();
        this.initNotifications();
        this.initDataTables();
        this.initCharts();
        this.initRichTextEditors();
        this.initDatePickers();
        this.initSelect2();
        this.initClipboard();
    }

    // ===== SIDEBAR =====
    initSidebar() {
        const sidebar = document.getElementById('sidebarMenu');
        const toggleBtn = document.querySelector('[data-bs-toggle="sidebar"]');
        const backdrop = document.querySelector('.sidebar-backdrop');

        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => {
                sidebar.classList.toggle('collapsed');
                this.saveSidebarState();
            });
        }

        // Carregar estado salvo
        this.loadSidebarState();

        // Mobile sidebar
        if (window.innerWidth < 768) {
            this.initMobileSidebar();
        }
    }

    initMobileSidebar() {
        const sidebar = document.getElementById('sidebarMenu');
        const toggleBtn = document.querySelector('.navbar-toggler');

        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => {
                sidebar.classList.toggle('mobile-open');
                
                if (sidebar.classList.contains('mobile-open')) {
                    this.createBackdrop();
                } else {
                    this.removeBackdrop();
                }
            });
        }
    }

    createBackdrop() {
        const backdrop = document.createElement('div');
        backdrop.className = 'sidebar-backdrop';
        backdrop.addEventListener('click', () => {
            document.getElementById('sidebarMenu').classList.remove('mobile-open');
            backdrop.remove();
        });
        document.body.appendChild(backdrop);
    }

    removeBackdrop() {
        const backdrop = document.querySelector('.sidebar-backdrop');
        if (backdrop) backdrop.remove();
    }

    saveSidebarState() {
        const sidebar = document.getElementById('sidebarMenu');
        const isCollapsed = sidebar.classList.contains('collapsed');
        localStorage.setItem('adminSidebarCollapsed', isCollapsed);
    }

    loadSidebarState() {
        const isCollapsed = localStorage.getItem('adminSidebarCollapsed') === 'true';
        const sidebar = document.getElementById('sidebarMenu');
        
        if (isCollapsed && sidebar) {
            sidebar.classList.add('collapsed');
        }
    }

    // ===== DASHBOARD =====
    initCharts() {
        if (this.currentPage !== 'dashboard') return;

        // Gráfico de Estatísticas
        this.initStatsChart();
        
        // Gráfico de Vendas
        this.initSalesChart();
        
        // Gráfico de Tráfego
        this.initTrafficChart();
    }

    initStatsChart() {
        const ctx = document.getElementById('statsChart');
        if (!ctx) return;

        const chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun'],
                datasets: [{
                    label: 'Cupons Ativos',
                    data: [65, 59, 80, 81, 56, 55],
                    borderColor: '#3498db',
                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'Produtos Ativos',
                    data: [28, 48, 40, 19, 86, 27],
                    borderColor: '#27ae60',
                    backgroundColor: 'rgba(39, 174, 96, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        this.charts.set('stats', chart);
    }

    initSalesChart() {
        const ctx = document.getElementById('salesChart');
        if (!ctx) return;

        const chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'],
                datasets: [{
                    label: 'Vendas (R$)',
                    data: [1200, 1900, 1500, 2200, 1800, 2500, 2000],
                    backgroundColor: 'rgba(52, 152, 219, 0.8)',
                    borderColor: '#3498db',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        this.charts.set('sales', chart);
    }

    initTrafficChart() {
        const ctx = document.getElementById('trafficChart');
        if (!ctx) return;

        const chart = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Direto', 'Redes Sociais', 'Busca', 'Email'],
                datasets: [{
                    data: [40, 25, 20, 15],
                    backgroundColor: [
                        '#3498db',
                        '#27ae60',
                        '#e74c3c',
                        '#f39c12'
                    ],
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        this.charts.set('traffic', chart);
    }

    // ===== DATA TABLES =====
    initDataTables() {
        const tables = document.querySelectorAll('.admin-table[data-datatable]');
        
        tables.forEach(table => {
            this.enhanceTable(table);
        });
    }

    enhanceTable(table) {
        // Adicionar funcionalidades à tabela
        const headers = table.querySelectorAll('th[data-sortable]');
        
        headers.forEach(header => {
            header.style.cursor = 'pointer';
            header.addEventListener('click', () => {
                this.sortTable(table, header.cellIndex);
            });
        });

        // Adicionar busca se necessário
        if (table.dataset.searchable) {
            this.addTableSearch(table);
        }

        // Adicionar paginação se necessário
        if (table.dataset.paginate) {
            this.addTablePagination(table);
        }
    }

    sortTable(table, columnIndex) {
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        const isNumeric = table.querySelector(`td:nth-child(${columnIndex + 1})`).textContent.trim().match(/^\d+$/);
        
        rows.sort((a, b) => {
            const aVal = a.cells[columnIndex].textContent.trim();
            const bVal = b.cells[columnIndex].textContent.trim();
            
            if (isNumeric) {
                return parseInt(aVal) - parseInt(bVal);
            }
            
            return aVal.localeCompare(bVal);
        });

        // Limpar e re-adicionar linhas ordenadas
        tbody.innerHTML = '';
        rows.forEach(row => tbody.appendChild(row));
    }

    addTableSearch(table) {
        const container = table.closest('.card');
        const header = container.querySelector('.card-header');
        
        const searchHtml = `
            <div class="table-search mb-3">
                <div class="input-group input-group-sm" style="max-width: 300px;">
                    <input type="text" class="form-control" placeholder="Buscar na tabela...">
                    <button class="btn btn-outline-secondary" type="button">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
            </div>
        `;
        
        header.insertAdjacentHTML('afterend', searchHtml);
        
        const searchInput = container.querySelector('.table-search input');
        searchInput.addEventListener('input', this.debounce((e) => {
            this.filterTable(table, e.target.value);
        }, 300));
    }

    filterTable(table, query) {
        const rows = table.querySelectorAll('tbody tr');
        const lowerQuery = query.toLowerCase();
        
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(lowerQuery) ? '' : 'none';
        });
    }

    addTablePagination(table) {
        const rows = table.querySelectorAll('tbody tr');
        const itemsPerPage = parseInt(table.dataset.itemsPerPage) || 10;
        const pageCount = Math.ceil(rows.length / itemsPerPage);
        
        if (pageCount <= 1) return;
        
        this.createPagination(table, pageCount, itemsPerPage);
    }

    createPagination(table, pageCount, itemsPerPage) {
        const container = table.parentNode;
        const paginationHtml = `
            <div class="table-pagination mt-3">
                <nav>
                    <ul class="pagination justify-content-center">
                        <li class="page-item disabled">
                            <a class="page-link" href="#" data-page="prev">Anterior</a>
                        </li>
                        ${Array.from({length: pageCount}, (_, i) => `
                            <li class="page-item ${i === 0 ? 'active' : ''}">
                                <a class="page-link" href="#" data-page="${i + 1}">${i + 1}</a>
                            </li>
                        `).join('')}
                        <li class="page-item">
                            <a class="page-link" href="#" data-page="next">Próxima</a>
                        </li>
                    </ul>
                </nav>
            </div>
        `;
        
        container.insertAdjacentHTML('beforeend', paginationHtml);
        
        // Event listeners para paginação
        container.querySelector('.table-pagination').addEventListener('click', (e) => {
            e.preventDefault();
            if (e.target.classList.contains('page-link')) {
                this.handlePagination(table, e.target.dataset.page, itemsPerPage);
            }
        });
    }

    handlePagination(table, action, itemsPerPage) {
        const rows = table.querySelectorAll('tbody tr');
        const currentPage = parseInt(table.dataset.currentPage) || 1;
        let newPage = currentPage;
        
        if (action === 'prev') {
            newPage = Math.max(1, currentPage - 1);
        } else if (action === 'next') {
            newPage = Math.min(Math.ceil(rows.length / itemsPerPage), currentPage + 1);
        } else {
            newPage = parseInt(action);
        }
        
        // Mostrar/ocultar linhas
        const start = (newPage - 1) * itemsPerPage;
        const end = start + itemsPerPage;
        
        rows.forEach((row, index) => {
            row.style.display = (index >= start && index < end) ? '' : 'none';
        });
        
        // Atualizar estado da paginação
        table.dataset.currentPage = newPage;
        this.updatePaginationUI(table, newPage);
    }

    updatePaginationUI(table, currentPage) {
        const pagination = table.parentNode.querySelector('.table-pagination');
        const pageItems = pagination.querySelectorAll('.page-item');
        const totalPages = Math.ceil(table.querySelectorAll('tbody tr').length / (parseInt(table.dataset.itemsPerPage) || 10));
        
        pageItems.forEach(item => {
            item.classList.remove('active', 'disabled');
            
            const pageLink = item.querySelector('.page-link');
            if (pageLink.dataset.page === currentPage.toString()) {
                item.classList.add('active');
            }
            
            if ((currentPage === 1 && pageLink.dataset.page === 'prev') ||
                (currentPage === totalPages && pageLink.dataset.page === 'next')) {
                item.classList.add('disabled');
            }
        });
    }

    // ===== FORMULÁRIOS =====
    handleFormInput(e) {
        if (e.target.matches('input, select, textarea')) {
            this.unsavedChanges = true;
            this.scheduleAutoSave();
        }
    }

    handleFormSubmit(e) {
        const form = e.target;
        
        if (!this.validateForm(form)) {
            e.preventDefault();
            return;
        }
        
        this.setFormLoading(form, true);
        this.unsavedChanges = false;
    }

    validateForm(form) {
        let isValid = true;
        const required = form.querySelectorAll('[required]');
        
        required.forEach(field => {
            if (!field.value.trim()) {
                this.showFieldError(field, 'Este campo é obrigatório');
                isValid = false;
            } else {
                this.clearFieldError(field);
            }
            
            // Validações específicas
            if (field.type === 'email' && field.value) {
                if (!this.isValidEmail(field.value)) {
                    this.showFieldError(field, 'Email inválido');
                    isValid = false;
                }
            }
            
            if (field.type === 'number' && field.value) {
                if (field.min && parseFloat(field.value) < parseFloat(field.min)) {
                    this.showFieldError(field, `Valor mínimo: ${field.min}`);
                    isValid = false;
                }
                
                if (field.max && parseFloat(field.value) > parseFloat(field.max)) {
                    this.showFieldError(field, `Valor máximo: ${field.max}`);
                    isValid = false;
                }
            }
        });
        
        return isValid;
    }

    showFieldError(field, message) {
        field.classList.add('is-invalid');
        
        let feedback = field.nextElementSibling;
        if (!feedback || !feedback.classList.contains('invalid-feedback')) {
            feedback = document.createElement('div');
            feedback.className = 'invalid-feedback';
            field.parentNode.appendChild(feedback);
        }
        
        feedback.textContent = message;
    }

    clearFieldError(field) {
        field.classList.remove('is-invalid');
        field.classList.add('is-valid');
        
        const feedback = field.nextElementSibling;
        if (feedback && feedback.classList.contains('invalid-feedback')) {
            feedback.remove();
        }
    }

    isValidEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }

    setFormLoading(form, loading) {
        const buttons = form.querySelectorAll('button[type="submit"]');
        
        buttons.forEach(button => {
            if (loading) {
                button.disabled = true;
                button.dataset.originalText = button.innerHTML;
                button.innerHTML = `
                    <span class="spinner-border spinner-border-sm" role="status"></span>
                    Salvando...
                `;
            } else {
                button.disabled = false;
                button.innerHTML = button.dataset.originalText || button.textContent;
            }
        });
    }

    // ===== AUTO SAVE =====
    scheduleAutoSave() {
        if (this.autoSaveTimer) {
            clearTimeout(this.autoSaveTimer);
        }
        
        this.autoSaveTimer = setTimeout(() => {
            this.performAutoSave();
        }, ADMIN_CONFIG.AUTO_SAVE_DELAY);
    }

    async performAutoSave() {
        const form = document.querySelector('form[data-autosave]');
        if (!form) return;
        
        try {
            const formData = new FormData(form);
            const response = await fetch(form.action, {
                method: 'POST',
                body: formData
            });
            
            if (response.ok) {
                this.showNotification('Alterações salvas automaticamente', 'success');
                this.unsavedChanges = false;
            }
        } catch (error) {
            console.error('Erro no auto-save:', error);
        }
    }

    // ===== UPLOAD DE ARQUIVOS =====
    handleFileUpload(e) {
        if (e.target.type === 'file') {
            const file = e.target.files[0];
            if (file) {
                this.validateFile(file, e.target);
            }
        }
    }

    validateFile(file, input) {
        // Tamanho
        if (file.size > ADMIN_CONFIG.UPLOAD_MAX_SIZE) {
            this.showNotification('Arquivo muito grande. Máximo: 5MB', 'error');
            input.value = '';
            return;
        }
        
        // Tipo
        const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!allowedTypes.includes(file.type)) {
            this.showNotification('Tipo de arquivo não permitido', 'error');
            input.value = '';
            return;
        }
        
        // Preview se for imagem
        if (file.type.startsWith('image/')) {
            this.showImagePreview(file, input);
        }
    }

    showImagePreview(file, input) {
        const reader = new FileReader();
        const previewId = input.dataset.preview;
        
        reader.onload = (e) => {
            if (previewId) {
                const preview = document.getElementById(previewId);
                if (preview) {
                    preview.innerHTML = `<img src="${e.target.result}" class="img-thumbnail" style="max-height: 200px;">`;
                }
            }
        };
        
        reader.readAsDataURL(file);
    }

    // ===== NOTIFICAÇÕES =====
    initNotifications() {
        // Container já deve existir no layout do admin
    }

    showNotification(message, type = 'info') {
        const types = {
            success: { class: 'alert-success', icon: 'bi-check-circle' },
            error: { class: 'alert-danger', icon: 'bi-exclamation-triangle' },
            warning: { class: 'alert-warning', icon: 'bi-exclamation-circle' },
            info: { class: 'alert-info', icon: 'bi-info-circle' }
        };

        const config = types[type] || types.info;
        const id = 'notification-' + Date.now();

        const notification = document.createElement('div');
        notification.id = id;
        notification.className = `alert ${config.class} alert-dismissible fade show`;
        notification.innerHTML = `
            <i class="${config.icon} me-2"></i>
            ${this.escapeHtml(message)}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;

        // Adicionar ao container de notificações ou criar um
        let container = document.getElementById('notifications');
        if (!container) {
            container = document.createElement('div');
            container.id = 'notifications';
            container.className = 'position-fixed top-0 end-0 p-3';
            container.style.zIndex = '9999';
            document.body.appendChild(container);
        }

        container.appendChild(notification);

        // Auto-remover
        setTimeout(() => {
            const element = document.getElementById(id);
            if (element) {
                element.remove();
            }
        }, 5000);
    }

    // ===== RICH TEXT EDITORS =====
    initRichTextEditors() {
        const editors = document.querySelectorAll('[data-rich-text]');
        
        editors.forEach(textarea => {
            this.initSimpleEditor(textarea);
        });
    }

    initSimpleEditor(textarea) {
        // Editor simples - pode ser expandido com uma biblioteca como TinyMCE
        textarea.style.minHeight = '200px';
        
        // Adicionar toolbar básica
        const toolbar = document.createElement('div');
        toolbar.className = 'editor-toolbar btn-toolbar mb-2';
        toolbar.innerHTML = `
            <div class="btn-group btn-group-sm me-2">
                <button type="button" class="btn btn-outline-secondary" data-command="bold"><i class="bi bi-type-bold"></i></button>
                <button type="button" class="btn btn-outline-secondary" data-command="italic"><i class="bi bi-type-italic"></i></button>
                <button type="button" class="btn btn-outline-secondary" data-command="underline"><i class="bi bi-type-underline"></i></button>
            </div>
            <div class="btn-group btn-group-sm">
                <button type="button" class="btn btn-outline-secondary" data-command="insertUnorderedList"><i class="bi bi-list-ul"></i></button>
                <button type="button" class="btn btn-outline-secondary" data-command="insertOrderedList"><i class="bi bi-list-ol"></i></button>
            </div>
        `;
        
        textarea.parentNode.insertBefore(toolbar, textarea);
        
        // Event listeners para a toolbar
        toolbar.addEventListener('click', (e) => {
            if (e.target.tagName === 'BUTTON') {
                e.preventDefault();
                const command = e.target.dataset.command;
                document.execCommand(command, false, null);
                textarea.focus();
            }
        });
    }

    // ===== DATE PICKERS =====
    initDatePickers() {
        const dateInputs = document.querySelectorAll('input[type="date"]');
        
        dateInputs.forEach(input => {
            // Adicionar validação de data
            input.addEventListener('change', () => {
                this.validateDateInput(input);
            });
        });
    }

    validateDateInput(input) {
        const value = input.value;
        if (!value) return;
        
        const date = new Date(value);
        const today = new Date();
        
        if (input.dataset.minToday && date < today) {
            this.showFieldError(input, 'A data não pode ser anterior a hoje');
            input.value = '';
        }
        
        if (input.dataset.futureOnly && date <= today) {
            this.showFieldError(input, 'A data deve ser futura');
            input.value = '';
        }
    }

    // ===== SELECT2 =====
    initSelect2() {
        // Simulação básica do Select2 - pode ser integrado com a biblioteca real
        const selects = document.querySelectorAll('select[data-select2]');
        
        selects.forEach(select => {
            select.classList.add('form-select');
            
            // Adicionar busca se necessário
            if (select.dataset.searchable) {
                this.addSelectSearch(select);
            }
        });
    }

    addSelectSearch(select) {
        const wrapper = document.createElement('div');
        wrapper.className = 'select-search-wrapper';
        wrapper.innerHTML = `
            <input type="text" class="form-control mb-2" placeholder="Buscar...">
        `;
        
        select.parentNode.insertBefore(wrapper, select);
        
        const searchInput = wrapper.querySelector('input');
        searchInput.addEventListener('input', this.debounce((e) => {
            this.filterSelectOptions(select, e.target.value);
        }, 300));
    }

    filterSelectOptions(select, query) {
        const options = select.querySelectorAll('option');
        const lowerQuery = query.toLowerCase();
        
        options.forEach(option => {
            if (option.textContent.toLowerCase().includes(lowerQuery)) {
                option.style.display = '';
            } else {
                option.style.display = 'none';
            }
        });
    }

    // ===== CLIPBOARD =====
    initClipboard() {
        document.querySelectorAll('[data-copy]').forEach(button => {
            button.addEventListener('click', () => {
                const text = button.dataset.copy;
                this.copyToClipboard(text);
            });
        });
    }

    async copyToClipboard(text) {
        try {
            await navigator.clipboard.writeText(text);
            this.showNotification('Copiado para a área de transferência', 'success');
        } catch (err) {
            this.fallbackCopyToClipboard(text);
        }
    }

    fallbackCopyToClipboard(text) {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        document.body.appendChild(textArea);
        textArea.select();
        document.execCommand('copy');
        document.body.removeChild(textArea);
        this.showNotification('Copiado!', 'success');
    }

    // ===== SESSÃO =====
    startSessionTimer() {
        this.sessionTimer = setTimeout(() => {
            this.showSessionWarning();
        }, ADMIN_CONFIG.SESSION_TIMEOUT - 60000); // Aviso 1 minuto antes
    }

    showSessionWarning() {
        const warning = document.createElement('div');
        warning.className = 'alert alert-warning alert-dismissible fade show';
        warning.innerHTML = `
            <i class="bi bi-clock me-2"></i>
            Sua sessão expirará em 1 minuto. 
            <a href="#" onclick="admin.extendSession()">Estender sessão</a>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        document.querySelector('.admin-content').prepend(warning);
    }

    extendSession() {
        // Fazer uma requisição para estender a sessão
        fetch('../ajax/session.php?action=extend')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.showNotification('Sessão estendida', 'success');
                    this.startSessionTimer();
                }
            });
    }

    // ===== AUTO REFRESH =====
    setupAutoRefresh() {
        if (this.currentPage === 'dashboard' || this.currentPage === 'estatisticas') {
            setInterval(() => {
                this.refreshData();
            }, ADMIN_CONFIG.REFRESH_INTERVAL);
        }
    }

    async refreshData() {
        try {
            const response = await fetch('../ajax/refresh-data.php');
            const data = await response.json();
            
            // Atualizar stats cards
            this.updateStatsCards(data.stats);
            
            // Atualizar gráficos se necessário
            if (data.charts) {
                this.updateCharts(data.charts);
            }
        } catch (error) {
            console.error('Erro ao atualizar dados:', error);
        }
    }

    updateStatsCards(stats) {
        if (!stats) return;
        
        Object.keys(stats).forEach(key => {
            const element = document.querySelector(`[data-stat="${key}"]`);
            if (element) {
                element.textContent = stats[key];
            }
        });
    }

    updateCharts(chartData) {
        this.charts.forEach((chart, key) => {
            if (chartData[key]) {
                chart.data.datasets.forEach((dataset, index) => {
                    if (chartData[key][index]) {
                        dataset.data = chartData[key][index];
                    }
                });
                chart.update();
            }
        });
    }

    // ===== EVENT HANDLERS GLOBAIS =====
    handleGlobalClick(e) {
        // Toggle dropdowns
        if (e.target.matches('.dropdown-toggle')) {
            e.preventDefault();
            const dropdown = e.target.closest('.dropdown');
            dropdown.classList.toggle('show');
        }
        
        // Fechar dropdowns ao clicar fora
        if (!e.target.matches('.dropdown, .dropdown *')) {
            document.querySelectorAll('.dropdown.show').forEach(dropdown => {
                dropdown.classList.remove('show');
            });
        }
    }

    handleKeyboard(e) {
        // Ctrl + S para salvar
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            this.saveCurrentForm();
        }
        
        // Escape para fechar modais
        if (e.key === 'Escape') {
            const modals = document.querySelectorAll('.modal.show');
            modals.forEach(modal => {
                bootstrap.Modal.getInstance(modal)?.hide();
            });
        }
    }

    handleBeforeUnload(e) {
        if (this.unsavedChanges) {
            e.preventDefault();
            e.returnValue = 'Você tem alterações não salvas. Deseja realmente sair?';
            return e.returnValue;
        }
    }

    handleResize() {
        // Ajustar charts no resize
        this.charts.forEach(chart => {
            chart.resize();
        });
        
        // Mobile sidebar
        if (window.innerWidth < 768) {
            this.initMobileSidebar();
        }
    }

    // ===== UTILITÁRIOS =====
    getCurrentPage() {
        const path = window.location.pathname;
        return path.split('/').pop().replace('.php', '');
    }

    saveCurrentForm() {
        const form = document.querySelector('form');
        if (form) {
            form.dispatchEvent(new Event('submit', { cancelable: true }));
        }
    }

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

    // ===== MODAIS =====
    initModals() {
        const modals = document.querySelectorAll('.modal');
        modals.forEach(modal => {
            modal.addEventListener('show.bs.modal', () => {
                document.body.classList.add('modal-open');
            });
            
            modal.addEventListener('hidden.bs.modal', () => {
                document.body.classList.remove('modal-open');
            });
        });
    }

    // ===== TOOLTIPS =====
    initTooltips() {
        const tooltipTriggerList = [].slice.call(
            document.querySelectorAll('[data-bs-toggle="tooltip"]')
        );
        
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }

    // ===== DESTRUIDOR =====
    destroy() {
        this.initialized = false;
        
        if (this.autoSaveTimer) {
            clearTimeout(this.autoSaveTimer);
        }
        
        if (this.sessionTimer) {
            clearTimeout(this.sessionTimer);
        }
        
        this.charts.forEach(chart => {
            chart.destroy();
        });
        
        this.charts.clear();
    }
}

// ===== INICIALIZAÇÃO GLOBAL =====
const admin = new TTaTimAdmin();

// Inicializar quando o DOM estiver pronto
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => admin.init());
} else {
    admin.init();
}

// Expor globalmente
window.admin = admin;

// ===== FUNÇÕES GLOBAIS =====
function confirmAction(message, callback) {
    if (confirm(message)) {
        callback();
    }
}

function toggleElement(id) {
    const element = document.getElementById(id);
    if (element) {
        element.style.display = element.style.display === 'none' ? '' : 'none';
    }
}

function formatCurrency(value) {
    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL'
    }).format(value);
}

// ===== ERROR HANDLING =====
window.addEventListener('error', (e) => {
    console.error('Erro no admin:', e.error);
});

window.addEventListener('unhandledrejection', (e) => {
    console.error('Promise rejeitada no admin:', e.reason);
});

// ===== PERFORMANCE =====
if ('performance' in window) {
    window.addEventListener('load', () => {
        const perfData = performance.timing;
        const loadTime = perfData.loadEventEnd - perfData.navigationStart;
        console.log(`Admin carregado em: ${loadTime}ms`);
    });
}