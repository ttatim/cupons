<?php
/**
 * Database.php - Classe de gerenciamento do banco de dados
 * Sistema TTaTim Cupons
 */

class Database {
    private $pdo;
    private $error;
    private $stmt;
    private $connected = false;
    
    public function __construct() {
        // Configurações do banco de dados
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $options = [
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];
        
        try {
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            $this->connected = true;
            
            // Criar tabelas se não existirem
            $this->createTablesIfNotExists();
            
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            $this->logError('Connection Error: ' . $this->error);
            
            if (ENVIRONMENT === 'development') {
                die('Database Connection Error: ' . $this->error);
            } else {
                die('System temporarily unavailable. Please try again later.');
            }
        }
    }
    
    /**
     * Criar tabelas se não existirem
     */
    private function createTablesIfNotExists() {
        try {
            // Tabela de usuários
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS usuarios (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    username VARCHAR(50) UNIQUE NOT NULL,
                    password VARCHAR(255) NOT NULL,
                    email VARCHAR(100) NOT NULL,
                    nome VARCHAR(100),
                    nivel ENUM('admin', 'moderador', 'editor') DEFAULT 'editor',
                    ativo BOOLEAN DEFAULT TRUE,
                    ultimo_login DATETIME,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_username (username),
                    INDEX idx_email (email),
                    INDEX idx_nivel (nivel),
                    INDEX idx_ativo (ativo)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Tabela de lojas
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS lojas (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    nome VARCHAR(100) NOT NULL,
                    slug VARCHAR(100) UNIQUE NOT NULL,
                    logo VARCHAR(255),
                    descricao TEXT,
                    website VARCHAR(255),
                    cor_primaria VARCHAR(7) DEFAULT '#4169E1',
                    ativo BOOLEAN DEFAULT TRUE,
                    ordem INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_slug (slug),
                    INDEX idx_ativo (ativo),
                    INDEX idx_ordem (ordem),
                    FULLTEXT idx_busca (nome, descricao)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Tabela de cupons
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS cupons (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    loja_id INT NOT NULL,
                    tipo ENUM('percentual', 'valor') NOT NULL,
                    valor DECIMAL(10,2) NOT NULL,
                    descricao VARCHAR(255) NOT NULL,
                    detalhes TEXT,
                    codigo VARCHAR(100) NOT NULL,
                    link_afiliado TEXT NOT NULL,
                    data_inicio DATE NOT NULL,
                    data_fim DATE NOT NULL,
                    limite_uso INT,
                    usos INT DEFAULT 0,
                    ativo BOOLEAN DEFAULT TRUE,
                    destaque BOOLEAN DEFAULT FALSE,
                    cliques INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (loja_id) REFERENCES lojas(id) ON DELETE CASCADE,
                    INDEX idx_loja_id (loja_id),
                    INDEX idx_ativo_data (ativo, data_fim),
                    INDEX idx_destaque (destaque),
                    INDEX idx_codigo (codigo),
                    INDEX idx_data_inicio (data_inicio),
                    INDEX idx_data_fim (data_fim),
                    FULLTEXT idx_busca (descricao, detalhes, codigo)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Tabela de produtos
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS produtos (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    loja_id INT NOT NULL,
                    nome VARCHAR(255) NOT NULL,
                    slug VARCHAR(255) NOT NULL,
                    descricao TEXT NOT NULL,
                    preco DECIMAL(10,2) NOT NULL,
                    preco_original DECIMAL(10,2),
                    cupom_desconto VARCHAR(100),
                    link_afiliado TEXT NOT NULL,
                    imagens JSON,
                    categoria VARCHAR(100),
                    tags JSON,
                    ativo BOOLEAN DEFAULT TRUE,
                    destaque BOOLEAN DEFAULT FALSE,
                    cliques INT DEFAULT 0,
                    visualizacoes INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (loja_id) REFERENCES lojas(id) ON DELETE CASCADE,
                    INDEX idx_loja_id (loja_id),
                    INDEX idx_ativo (ativo),
                    INDEX idx_destaque (destaque),
                    INDEX idx_categoria (categoria),
                    INDEX idx_preco (preco),
                    INDEX idx_slug (slug),
                    FULLTEXT idx_busca (nome, descricao, tags)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Tabela de estatísticas
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS estatisticas (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    tipo ENUM('cupom', 'produto') NOT NULL,
                    item_id INT NOT NULL,
                    data_acesso DATE NOT NULL,
                    cliques INT DEFAULT 1,
                    conversoes INT DEFAULT 0,
                    valor_conversoes DECIMAL(10,2) DEFAULT 0,
                    ip_address VARCHAR(45),
                    user_agent TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_tipo_item_data (tipo, item_id, data_acesso),
                    INDEX idx_data_acesso (data_acesso),
                    INDEX idx_tipo_item (tipo, item_id),
                    INDEX idx_ip_address (ip_address)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Tabela de comissões
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS comissoes (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    plataforma VARCHAR(50) NOT NULL,
                    total DECIMAL(10,2) DEFAULT 0,
                    pendente DECIMAL(10,2) DEFAULT 0,
                    disponivel DECIMAL(10,2) DEFAULT 0,
                    data_sincronizacao DATETIME,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_plataforma (plataforma),
                    INDEX idx_data_sincronizacao (data_sincronizacao)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Tabela de configurações
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS configuracoes (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    chave VARCHAR(100) UNIQUE NOT NULL,
                    valor TEXT NOT NULL,
                    tipo ENUM('string', 'json', 'boolean', 'number') DEFAULT 'string',
                    descricao TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_chave (chave)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Tabela de logs de sincronização
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS logs_sincronizacao (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    acao VARCHAR(50) NOT NULL,
                    plataforma VARCHAR(50) NOT NULL,
                    sucesso BOOLEAN DEFAULT TRUE,
                    produtos_adicionados INT DEFAULT 0,
                    produtos_atualizados INT DEFAULT 0,
                    detalhes JSON,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_plataforma (plataforma),
                    INDEX idx_created_at (created_at),
                    INDEX idx_sucesso (sucesso)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Tabela de notificações
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS notificacoes (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    usuario_id INT NOT NULL,
                    titulo VARCHAR(255) NOT NULL,
                    mensagem TEXT NOT NULL,
                    tipo ENUM('info', 'success', 'warning', 'error') DEFAULT 'info',
                    lida BOOLEAN DEFAULT FALSE,
                    link VARCHAR(255),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_usuario_id (usuario_id),
                    INDEX idx_lida (lida),
                    INDEX idx_tipo (tipo),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Tabela de logs do sistema
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS logs_sistema (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    nivel ENUM('info', 'warning', 'error', 'debug') DEFAULT 'info',
                    mensagem TEXT NOT NULL,
                    contexto JSON,
                    ip_address VARCHAR(45),
                    user_agent TEXT,
                    usuario_id INT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_nivel (nivel),
                    INDEX idx_created_at (created_at),
                    INDEX idx_usuario_id (usuario_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Inserir dados iniciais
            $this->insertInitialData();
            
        } catch (PDOException $e) {
            $this->logError('Table Creation Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Inserir dados iniciais
     */
    private function insertInitialData() {
        try {
            // Verificar se já existem configurações
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM configuracoes");
            if ($stmt->fetchColumn() == 0) {
                // Configurações padrão
                $configuracoes = [
                    ['site_nome', 'TTaTim Cupons', 'string', 'Nome do site'],
                    ['site_descricao', 'Encontre os melhores cupons e ofertas', 'string', 'Descrição do site'],
                    ['site_url', 'http://localhost', 'string', 'URL do site'],
                    ['admin_email', 'admin@ttatim.com', 'string', 'Email do administrador'],
                    ['itens_por_pagina', '20', 'number', 'Número de itens por página'],
                    ['manutencao', '0', 'boolean', 'Modo manutenção'],
                    ['seo_config', '{"meta_title":"TTaTim Cupons - Melhores Ofertas","meta_description":"Encontre os melhores cupons de desconto e ofertas exclusivas","meta_keywords":"cupons, descontos, ofertas, economia"}', 'json', 'Configurações SEO'],
                    ['afiliados_config', '{"ml_app_id":"","ml_app_secret":"","shopee_partner_id":"","shopee_partner_key":"","ali_app_key":"","ali_app_secret":""}', 'json', 'Configurações de Afiliados'],
                    ['email_config', '{"smtp_host":"","smtp_port":"587","smtp_username":"","smtp_password":"","smtp_secure":"tls"}', 'json', 'Configurações de Email'],
                    ['upload_config', '{"upload_max_size":"5","allowed_extensions":["jpg","jpeg","png","gif","webp"]}', 'json', 'Configurações de Upload']
                ];
                
                $stmt = $this->pdo->prepare("
                    INSERT INTO configuracoes (chave, valor, tipo, descricao) 
                    VALUES (?, ?, ?, ?)
                ");
                
                foreach ($configuracoes as $config) {
                    $stmt->execute($config);
                }
            }
            
            // Verificar se já existe usuário admin
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM usuarios WHERE username = 'ttatim'");
            if ($stmt->fetchColumn() == 0) {
                $password_hash = password_hash('senha123', PASSWORD_DEFAULT);
                $stmt = $this->pdo->prepare("
                    INSERT INTO usuarios (username, password, email, nome, nivel) 
                    VALUES (?, ?, ?, ?, 'admin')
                ");
                $stmt->execute(['ttatim', $password_hash, 'admin@ttatim.com', 'Administrador TTaTim']);
            }
            
            // Inserir lojas de exemplo se não existirem
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM lojas");
            if ($stmt->fetchColumn() == 0) {
                $lojas = [
                    ['Magazine Luiza', 'magalu', 'Magazine Luiza - Tudo para sua casa', 'https://www.magazineluiza.com.br'],
                    ['Americanas', 'americanas', 'Americanas - Tudo que você precisa', 'https://www.americanas.com.br'],
                    ['Mercado Livre', 'mercado-livre', 'Mercado Livre - Maior plataforma', 'https://www.mercadolivre.com.br'],
                    ['Amazon', 'amazon', 'Amazon - Do A ao Z', 'https://www.amazon.com.br'],
                    ['Shopee', 'shopee', 'Shopee - Compre e venda', 'https://www.shopee.com.br']
                ];
                
                $stmt = $this->pdo->prepare("
                    INSERT INTO lojas (nome, slug, descricao, website) 
                    VALUES (?, ?, ?, ?)
                ");
                
                foreach ($lojas as $loja) {
                    $stmt->execute($loja);
                }
            }
            
        } catch (PDOException $e) {
            $this->logError('Initial Data Error: ' . $e->getMessage());
        }
    }
    
    /**
     * Preparar statement
     */
    public function query($sql) {
        $this->stmt = $this->pdo->prepare($sql);
    }
    
    /**
     * Bind values
     */
    public function bind($param, $value, $type = null) {
        if (is_null($type)) {
            switch (true) {
                case is_int($value):
                    $type = PDO::PARAM_INT;
                    break;
                case is_bool($value):
                    $type = PDO::PARAM_BOOL;
                    break;
                case is_null($value):
                    $type = PDO::PARAM_NULL;
                    break;
                default:
                    $type = PDO::PARAM_STR;
            }
        }
        $this->stmt->bindValue($param, $value, $type);
    }
    
    /**
     * Executar query
     */
    public function execute() {
        try {
            return $this->stmt->execute();
        } catch (PDOException $e) {
            $this->error = $e->getMessage();
            $this->logError('Execute Error: ' . $this->error);
            return false;
        }
    }
    
    /**
     * Obter resultados como array
     */
    public function resultSet() {
        $this->execute();
        return $this->stmt->fetchAll();
    }
    
    /**
     * Obter único resultado
     */
    public function single() {
        $this->execute();
        return $this->stmt->fetch();
    }
    
    /**
     * Obter contagem de linhas
     */
    public function rowCount() {
        return $this->stmt->rowCount();
    }
    
    /**
     * Obter último ID inserido
     */
    public function lastInsertId() {
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Iniciar transação
     */
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }
    
    /**
     * Finalizar transação
     */
    public function endTransaction() {
        return $this->pdo->commit();
    }
    
    /**
     * Cancelar transação
     */
    public function cancelTransaction() {
        return $this->pdo->rollBack();
    }
    
    /**
     * Debug do SQL
     */
    public function debugDumpParams() {
        return $this->stmt->debugDumpParams();
    }
    
    /**
     * Obter erro
     */
    public function getError() {
        return $this->error;
    }
    
    /**
     * Verificar se está conectado
     */
    public function isConnected() {
        return $this->connected;
    }
    
    /**
     * Obter informações do banco
     */
    public function getDatabaseInfo() {
        try {
            $info = [
                'version' => $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
                'driver' => $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
                'connection' => $this->pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS),
                'client_version' => $this->pdo->getAttribute(PDO::ATTR_CLIENT_VERSION),
                'server_info' => $this->pdo->getAttribute(PDO::ATTR_SERVER_INFO)
            ];
            
            // Tamanho do banco
            $stmt = $this->pdo->query("
                SELECT 
                    table_schema as database_name,
                    ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb
                FROM information_schema.tables 
                WHERE table_schema = ?
                GROUP BY table_schema
            ");
            $stmt->execute([DB_NAME]);
            $size = $stmt->fetch();
            
            if ($size) {
                $info['database_size'] = $size['size_mb'] . ' MB';
            }
            
            // Contagem de tabelas
            $stmt = $this->pdo->query("
                SELECT COUNT(*) as table_count 
                FROM information_schema.tables 
                WHERE table_schema = ?
            ");
            $stmt->execute([DB_NAME]);
            $table_count = $stmt->fetch();
            
            $info['table_count'] = $table_count['table_count'];
            
            return $info;
            
        } catch (PDOException $e) {
            $this->logError('Database Info Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Backup do banco de dados
     */
    public function backup($backup_path = '../backups/') {
        try {
            if (!is_dir($backup_path)) {
                mkdir($backup_path, 0755, true);
            }
            
            $backup_file = $backup_path . 'backup_' . date('Y-m-d_H-i-s') . '.sql';
            $output = "";
            
            // Obter todas as tabelas
            $tables = $this->pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($tables as $table) {
                // Estrutura da tabela
                $output .= "--\n-- Estrutura da tabela `$table`\n--\n";
                $create_table = $this->pdo->query("SHOW CREATE TABLE `$table`")->fetch();
                $output .= $create_table['Create Table'] . ";\n\n";
                
                // Dados da tabela
                $output .= "--\n-- Dump dos dados da tabela `$table`\n--\n";
                $rows = $this->pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($rows)) {
                    $columns = array_keys($rows[0]);
                    $output .= "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES \n";
                    
                    $values = [];
                    foreach ($rows as $row) {
                        $row_values = [];
                        foreach ($row as $value) {
                            $row_values[] = is_null($value) ? 'NULL' : $this->pdo->quote($value);
                        }
                        $values[] = "(" . implode(', ', $row_values) . ")";
                    }
                    
                    $output .= implode(",\n", $values) . ";\n\n";
                }
            }
            
            file_put_contents($backup_file, $output);
            $this->logSystem('Backup criado: ' . $backup_file, 'info');
            
            return $backup_file;
            
        } catch (PDOException $e) {
            $this->logError('Backup Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Otimizar tabelas
     */
    public function optimizeTables() {
        try {
            $tables = $this->pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            $optimized = [];
            
            foreach ($tables as $table) {
                $this->pdo->exec("OPTIMIZE TABLE `$table`");
                $optimized[] = $table;
            }
            
            $this->logSystem('Tabelas otimizadas: ' . implode(', ', $optimized), 'info');
            return $optimized;
            
        } catch (PDOException $e) {
            $this->logError('Optimize Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log de erro
     */
    private function logError($message) {
        $log_message = '[' . date('Y-m-d H:i:s') . '] DATABASE ERROR: ' . $message . PHP_EOL;
        error_log($log_message, 3, '../logs/database_errors.log');
        
        // Também logar no banco se possível
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO logs_sistema (nivel, mensagem, ip_address, user_agent) 
                VALUES ('error', ?, ?, ?)
            ");
            $stmt->execute([
                $message,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
        } catch (Exception $e) {
            // Silenciar se não puder logar no banco
        }
    }
    
    /**
     * Log do sistema
     */
    private function logSystem($message, $level = 'info') {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO logs_sistema (nivel, mensagem, ip_address, user_agent, usuario_id) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $level,
                $message,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                $_SESSION['user_id'] ?? null
            ]);
        } catch (Exception $e) {
            // Silenciar se não puder logar
        }
    }
    
    /**
     * Destructor
     */
    public function __destruct() {
        $this->pdo = null;
        $this->stmt = null;
    }
}

// Criar instância global do banco de dados
try {
    $db = new Database();
} catch (Exception $e) {
    die('Database initialization failed: ' . $e->getMessage());
}
?>