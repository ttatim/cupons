<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$mensagem = '';
$erro = '';

// Buscar configurações atuais
$stmt = $pdo->query("SELECT * FROM configuracoes");
$configuracoes_raw = $stmt->fetchAll();

$configuracoes = [];
foreach ($configuracoes_raw as $config) {
    switch ($config['tipo']) {
        case 'json':
            $configuracoes[$config['chave']] = json_decode($config['valor'], true);
            break;
        case 'boolean':
            $configuracoes[$config['chave']] = (bool)$config['valor'];
            break;
        case 'number':
            $configuracoes[$config['chave']] = (float)$config['valor'];
            break;
        default:
            $configuracoes[$config['chave']] = $config['valor'];
    }
}

// Processar salvamento das configurações
if ($_POST && isset($_POST['acao']) && $_POST['acao'] === 'salvar_configuracoes') {
    try {
        $dados = Security::sanitizeXSS($_POST);
        
        if (!Security::validateCSRFToken($dados['csrf_token'])) {
            throw new Exception('Token de segurança inválido.');
        }

        // Configurações Gerais
        $configs_gerais = [
            'site_nome' => $dados['site_nome'],
            'site_descricao' => $dados['site_descricao'],
            'site_url' => $dados['site_url'],
            'admin_email' => $dados['admin_email'],
            'itens_por_pagina' => (int)$dados['itens_por_pagina'],
            'manutencao' => isset($dados['manutencao']) ? '1' : '0'
        ];

        // Configurações de SEO
        $configs_seo = [
            'meta_title' => $dados['meta_title'],
            'meta_description' => $dados['meta_description'],
            'meta_keywords' => $dados['meta_keywords'],
            'google_analytics' => $dados['google_analytics']
        ];

        // Configurações de Afiliados
        $configs_afiliados = [
            'ml_app_id' => $dados['ml_app_id'],
            'ml_app_secret' => $dados['ml_app_secret'],
            'shopee_partner_id' => $dados['shopee_partner_id'],
            'shopee_partner_key' => $dados['shopee_partner_key'],
            'ali_app_key' => $dados['ali_app_key'],
            'ali_app_secret' => $dados['ali_app_secret']
        ];

        // Configurações de Email
        $configs_email = [
            'smtp_host' => $dados['smtp_host'],
            'smtp_port' => $dados['smtp_port'],
            'smtp_username' => $dados['smtp_username'],
            'smtp_password' => $dados['smtp_password'],
            'smtp_secure' => $dados['smtp_secure']
        ];

        // Configurações de Upload
        $configs_upload = [
            'upload_max_size' => (int)$dados['upload_max_size'],
            'allowed_extensions' => explode(',', $dados['allowed_extensions'])
        ];

        // Salvar todas as configurações
        $configs_to_save = [
            // Gerais
            ['site_nome', $configs_gerais['site_nome'], 'string'],
            ['site_descricao', $configs_gerais['site_descricao'], 'string'],
            ['site_url', $configs_gerais['site_url'], 'string'],
            ['admin_email', $configs_gerais['admin_email'], 'string'],
            ['itens_por_pagina', $configs_gerais['itens_por_pagina'], 'number'],
            ['manutencao', $configs_gerais['manutencao'], 'boolean'],
            
            // SEO
            ['seo_config', json_encode($configs_seo), 'json'],
            
            // Afiliados
            ['afiliados_config', json_encode($configs_afiliados), 'json'],
            
            // Email
            ['email_config', json_encode($configs_email), 'json'],
            
            // Upload
            ['upload_config', json_encode($configs_upload), 'json']
        ];

        $pdo->beginTransaction();

        foreach ($configs_to_save as $config) {
            $stmt = $pdo->prepare("
                INSERT INTO configuracoes (chave, valor, tipo, updated_at) 
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                valor = VALUES(valor), 
                tipo = VALUES(tipo),
                updated_at = NOW()
            ");
            $stmt->execute([$config[0], $config[1], $config[2]]);
        }

        $pdo->commit();
        $mensagem = 'Configurações salvas com sucesso!';

        // Atualizar configurações locais
        foreach ($configs_gerais as $chave => $valor) {
            $configuracoes[$chave] = $valor;
        }
        $configuracoes['seo_config'] = $configs_seo;
        $configuracoes['afiliados_config'] = $configs_afiliados;
        $configuracoes['email_config'] = $configs_email;
        $configuracoes['upload_config'] = $configs_upload;

    } catch (Exception $e) {
        $pdo->rollBack();
        $erro = 'Erro ao salvar configurações: ' . $e->getMessage();
    }
}

// Processar backup do banco de dados
if ($_POST && isset($_POST['acao']) && $_POST['acao'] === 'backup_database') {
    try {
        $backup_file = backupDatabase($pdo);
        $mensagem = 'Backup criado com sucesso: ' . $backup_file;
    } catch (Exception $e) {
        $erro = 'Erro ao criar backup: ' . $e->getMessage();
    }
}

// Processar limpeza de cache
if ($_POST && isset($_POST['acao']) && $_POST['acao'] === 'limpar_cache') {
    try {
        limparCache();
        $mensagem = 'Cache limpo com sucesso!';
    } catch (Exception $e) {
        $erro = 'Erro ao limpar cache: ' . $e->getMessage();
    }
}

// Processar teste de email
if ($_POST && isset($_POST['acao']) && $_POST['acao'] === 'testar_email') {
    try {
        $resultado = testarEmail($configuracoes['email_config']);
        if ($resultado) {
            $mensagem = 'Email de teste enviado com sucesso!';
        } else {
            $erro = 'Erro ao enviar email de teste.';
        }
    } catch (Exception $e) {
        $erro = 'Erro no teste de email: ' . $e->getMessage();
    }
}

// Função para backup do banco
function backupDatabase($pdo) {
    $backup_dir = '../backups/';
    if (!file_exists($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }

    $backup_file = $backup_dir . 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    
    // Obter todas as tabelas
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    $output = "-- Backup criado em: " . date('Y-m-d H:i:s') . "\n";
    $output .= "-- TTaTim Cupons Database Backup\n\n";
    
    foreach ($tables as $table) {
        // Estrutura da tabela
        $output .= "--\n-- Estrutura da tabela `$table`\n--\n";
        $create_table = $pdo->query("SHOW CREATE TABLE `$table`")->fetch();
        $output .= $create_table['Create Table'] . ";\n\n";
        
        // Dados da tabela
        $output .= "--\n-- Dump dos dados da tabela `$table`\n--\n";
        $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($rows)) {
            $columns = array_keys($rows[0]);
            $output .= "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES \n";
            
            $values = [];
            foreach ($rows as $row) {
                $row_values = [];
                foreach ($row as $value) {
                    $row_values[] = $pdo->quote($value);
                }
                $values[] = "(" . implode(', ', $row_values) . ")";
            }
            
            $output .= implode(",\n", $values) . ";\n\n";
        }
    }
    
    file_put_contents($backup_file, $output);
    return basename($backup_file);
}

// Função para limpar cache
function limparCache() {
    $cache_dirs = [
        '../cache/',
        '../assets/cache/',
        '../tmp/'
    ];
    
    foreach ($cache_dirs as $dir) {
        if (file_exists($dir)) {
            $files = glob($dir . '*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
    
    // Limpar cache do OPcache se estiver ativo
    if (function_exists('opcache_reset')) {
        opcache_reset();
    }
}

// Função para testar email
function testarEmail($email_config) {
    // Implementação básica - expandir conforme necessidade
    $to = $email_config['smtp_username'];
    $subject = 'Teste de Configuração de Email - TTaTim Cupons';
    $message = 'Este é um email de teste para verificar a configuração do servidor SMTP.';
    $headers = 'From: ' . $email_config['smtp_username'] . "\r\n" .
               'Reply-To: ' . $email_config['smtp_username'] . "\r\n" .
               'X-Mailer: PHP/' . phpversion();
    
    return mail($to, $subject, $message, $headers);
}

$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações - Administração</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .nav-config .nav-link {
            border-radius: 8px;
            margin: 2px;
            padding: 10px 15px;
            color: #495057;
        }
        .nav-config .nav-link.active {
            background: #4169E1;
            color: white;
        }
        .config-section {
            display: none;
        }
        .config-section.active {
            display: block;
        }
        .form-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .system-info-card {
            text-align: center;
            padding: 15px;
        }
        .info-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: #4169E1;
        }
        .info-label {
            color: #6c757d;
            font-size: 0.9rem;
        }
        .badge-status {
            font-size: 0.8rem;
        }
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 60px;
            height: 34px;
        }
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 34px;
        }
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 26px;
            width: 26px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .toggle-slider {
            background-color: #28a745;
        }
        input:checked + .toggle-slider:before {
            transform: translateX(26px);
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
                        <i class="bi bi-gear me-2"></i>Configurações do Sistema
                    </h1>
                </div>

                <?php if ($mensagem): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="bi bi-check-circle me-2"></i><?= $mensagem ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($erro): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="bi bi-exclamation-triangle me-2"></i><?= $erro ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row">
                    <!-- Menu de Navegação -->
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <ul class="nav nav-pills flex-column nav-config" id="configTabs">
                                    <li class="nav-item">
                                        <a class="nav-link active" href="#" data-bs-target="geral">
                                            <i class="bi bi-house-gear me-2"></i>Geral
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" href="#" data-bs-target="seo">
                                            <i class="bi bi-search me-2"></i>SEO
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" href="#" data-bs-target="afiliados">
                                            <i class="bi bi-shop me-2"></i>Afiliados
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" href="#" data-bs-target="email">
                                            <i class="bi bi-envelope me-2"></i>Email
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" href="#" data-bs-target="upload">
                                            <i class="bi bi-upload me-2"></i>Upload
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" href="#" data-bs-target="sistema">
                                            <i class="bi bi-terminal me-2"></i>Sistema
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" href="#" data-bs-target="backup">
                                            <i class="bi bi-database me-2"></i>Backup
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <!-- Informações do Sistema -->
                        <div class="card mt-4">
                            <div class="card-header">
                                <h6 class="card-title mb-0">Informações do Sistema</h6>
                            </div>
                            <div class="card-body">
                                <div class="system-info-card">
                                    <div class="info-value"><?= phpversion() ?></div>
                                    <div class="info-label">PHP Version</div>
                                </div>
                                <div class="system-info-card">
                                    <div class="info-value"><?= $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) ?></div>
                                    <div class="info-label">MySQL Version</div>
                                </div>
                                <div class="system-info-card">
                                    <div class="info-value"><?= number_format(memory_get_peak_usage() / 1024 / 1024, 2) ?> MB</div>
                                    <div class="info-label">Memory Usage</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Conteúdo das Configurações -->
                    <div class="col-md-9">
                        <form method="POST" id="configForm">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            
                            <!-- Configurações Gerais -->
                            <div class="config-section active" id="geral">
                                <div class="card">
                                    <div class="card-header">
                                        <h5 class="card-title mb-0">
                                            <i class="bi bi-house-gear me-2"></i>Configurações Gerais
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Nome do Site</label>
                                                    <input type="text" name="site_nome" class="form-control" 
                                                           value="<?= htmlspecialchars($configuracoes['site_nome'] ?? 'TTaTim Cupons') ?>" required>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">URL do Site</label>
                                                    <input type="url" name="site_url" class="form-control" 
                                                           value="<?= htmlspecialchars($configuracoes['site_url'] ?? 'http://localhost') ?>" required>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Descrição do Site</label>
                                            <textarea name="site_descricao" class="form-control" rows="3"><?= htmlspecialchars($configuracoes['site_descricao'] ?? 'Encontre os melhores cupons e ofertas') ?></textarea>
                                        </div>

                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Email do Administrador</label>
                                                    <input type="email" name="admin_email" class="form-control" 
                                                           value="<?= htmlspecialchars($configuracoes['admin_email'] ?? 'admin@ttatim.com') ?>" required>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Itens por Página</label>
                                                    <input type="number" name="itens_por_pagina" class="form-control" 
                                                           value="<?= $configuracoes['itens_por_pagina'] ?? 20 ?>" min="1" max="100">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <div class="form-check form-switch">
                                                <input class="form-check-input" type="checkbox" name="manutencao" 
                                                       id="manutencao" <?= ($configuracoes['manutencao'] ?? false) ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="manutencao">
                                                    Modo Manutenção
                                                </label>
                                                <small class="form-text text-muted d-block">
                                                    Quando ativado, o site ficará indisponível para visitantes.
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Configurações SEO -->
                            <div class="config-section" id="seo">
                                <div class="card">
                                    <div class="card-header">
                                        <h5 class="card-title mb-0">
                                            <i class="bi bi-search me-2"></i>Configurações SEO
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label class="form-label">Meta Title</label>
                                            <input type="text" name="meta_title" class="form-control" 
                                                   value="<?= htmlspecialchars($configuracoes['seo_config']['meta_title'] ?? 'TTaTim Cupons - Melhores Ofertas') ?>">
                                            <small class="form-text text-muted">
                                                Título que aparece nos resultados de busca (até 60 caracteres)
                                            </small>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Meta Description</label>
                                            <textarea name="meta_description" class="form-control" rows="3"><?= htmlspecialchars($configuracoes['seo_config']['meta_description'] ?? 'Encontre os melhores cupons de desconto e ofertas exclusivas. Economize em suas compras online!') ?></textarea>
                                            <small class="form-text text-muted">
                                                Descrição que aparece nos resultados de busca (até 160 caracteres)
                                            </small>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Meta Keywords</label>
                                            <input type="text" name="meta_keywords" class="form-control" 
                                                   value="<?= htmlspecialchars($configuracoes['seo_config']['meta_keywords'] ?? 'cupons, descontos, ofertas, economia, compras online') ?>">
                                            <small class="form-text text-muted">
                                                Palavras-chave separadas por vírgula
                                            </small>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Google Analytics</label>
                                            <textarea name="google_analytics" class="form-control" rows="4" 
                                                      placeholder="Cole aqui o código do Google Analytics"><?= htmlspecialchars($configuracoes['seo_config']['google_analytics'] ?? '') ?></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Configurações de Afiliados -->
                            <div class="config-section" id="afiliados">
                                <div class="card">
                                    <div class="card-header">
                                        <h5 class="card-title mb-0">
                                            <i class="bi bi-shop me-2"></i>Configurações de Afiliados
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="form-section">
                                            <h6>Mercado Livre</h6>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">App ID</label>
                                                        <input type="text" name="ml_app_id" class="form-control" 
                                                               value="<?= htmlspecialchars($configuracoes['afiliados_config']['ml_app_id'] ?? '') ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">App Secret</label>
                                                        <input type="password" name="ml_app_secret" class="form-control" 
                                                               value="<?= htmlspecialchars($configuracoes['afiliados_config']['ml_app_secret'] ?? '') ?>">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="form-section">
                                            <h6>Shopee</h6>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Partner ID</label>
                                                        <input type="text" name="shopee_partner_id" class="form-control" 
                                                               value="<?= htmlspecialchars($configuracoes['afiliados_config']['shopee_partner_id'] ?? '') ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Partner Key</label>
                                                        <input type="password" name="shopee_partner_key" class="form-control" 
                                                               value="<?= htmlspecialchars($configuracoes['afiliados_config']['shopee_partner_key'] ?? '') ?>">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="form-section">
                                            <h6>AliExpress</h6>
                                            <div class="row">
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">App Key</label>
                                                        <input type="text" name="ali_app_key" class="form-control" 
                                                               value="<?= htmlspecialchars($configuracoes['afiliados_config']['ali_app_key'] ?? '') ?>">
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">App Secret</label>
                                                        <input type="password" name="ali_app_secret" class="form-control" 
                                                               value="<?= htmlspecialchars($configuracoes['afiliados_config']['ali_app_secret'] ?? '') ?>">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Configurações de Email -->
                            <div class="config-section" id="email">
                                <div class="card">
                                    <div class="card-header">
                                        <h5 class="card-title mb-0">
                                            <i class="bi bi-envelope me-2"></i>Configurações de Email
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">SMTP Host</label>
                                                    <input type="text" name="smtp_host" class="form-control" 
                                                           value="<?= htmlspecialchars($configuracoes['email_config']['smtp_host'] ?? '') ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">SMTP Port</label>
                                                    <input type="number" name="smtp_port" class="form-control" 
                                                           value="<?= $configuracoes['email_config']['smtp_port'] ?? 587 ?>">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">SMTP Username</label>
                                                    <input type="text" name="smtp_username" class="form-control" 
                                                           value="<?= htmlspecialchars($configuracoes['email_config']['smtp_username'] ?? '') ?>">
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">SMTP Password</label>
                                                    <input type="password" name="smtp_password" class="form-control" 
                                                           value="<?= htmlspecialchars($configuracoes['email_config']['smtp_password'] ?? '') ?>">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">SMTP Security</label>
                                            <select name="smtp_secure" class="form-select">
                                                <option value="">None</option>
                                                <option value="tls" <?= ($configuracoes['email_config']['smtp_secure'] ?? '') === 'tls' ? 'selected' : '' ?>>TLS</option>
                                                <option value="ssl" <?= ($configuracoes['email_config']['smtp_secure'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <button type="submit" name="acao" value="testar_email" class="btn btn-outline-primary">
                                                <i class="bi bi-envelope-check me-1"></i>Testar Configuração de Email
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Configurações de Upload -->
                            <div class="config-section" id="upload">
                                <div class="card">
                                    <div class="card-header">
                                        <h5 class="card-title mb-0">
                                            <i class="bi bi-upload me-2"></i>Configurações de Upload
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3">
                                            <label class="form-label">Tamanho Máximo de Upload (MB)</label>
                                            <input type="number" name="upload_max_size" class="form-control" 
                                                   value="<?= $configuracoes['upload_config']['upload_max_size'] ?? 5 ?>" min="1" max="100">
                                            <small class="form-text text-muted">
                                                Tamanho máximo permitido para upload de arquivos (em MB)
                                            </small>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Extensões Permitidas</label>
                                            <input type="text" name="allowed_extensions" class="form-control" 
                                                   value="<?= htmlspecialchars(implode(',', $configuracoes['upload_config']['allowed_extensions'] ?? ['jpg', 'jpeg', 'png', 'gif'])) ?>">
                                            <small class="form-text text-muted">
                                                Separe as extensões por vírgula (ex: jpg,png,gif,webp)
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Informações do Sistema -->
                            <div class="config-section" id="sistema">
                                <div class="card">
                                    <div class="card-header">
                                        <h5 class="card-title mb-0">
                                            <i class="bi bi-terminal me-2"></i>Informações do Sistema
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Versão do PHP</label>
                                                    <input type="text" class="form-control" value="<?= phpversion() ?>" readonly>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Versão do MySQL</label>
                                                    <input type="text" class="form-control" value="<?= $pdo->getAttribute(PDO::ATTR_SERVER_VERSION) ?>" readonly>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Servidor Web</label>
                                                    <input type="text" class="form-control" value="<?= $_SERVER['SERVER_SOFTWARE'] ?? 'N/A' ?>" readonly>
                                                </div>
                                            </div>
                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Sistema Operacional</label>
                                                    <input type="text" class="form-control" value="<?= php_uname() ?>" readonly>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <button type="submit" name="acao" value="limpar_cache" class="btn btn-outline-warning">
                                                <i class="bi bi-trash me-1"></i>Limpar Cache do Sistema
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Backup e Restauração -->
                            <div class="config-section" id="backup">
                                <div class="card">
                                    <div class="card-header">
                                        <h5 class="card-title mb-0">
                                            <i class="bi bi-database me-2"></i>Backup e Restauração
                                        </h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="alert alert-info">
                                            <i class="bi bi-info-circle me-2"></i>
                                            <strong>Importante:</strong> Faça backup regularmente do seu banco de dados para prevenir perda de dados.
                                        </div>

                                        <div class="mb-3">
                                            <button type="submit" name="acao" value="backup_database" class="btn btn-success">
                                                <i class="bi bi-database-down me-1"></i>Criar Backup do Banco de Dados
                                            </button>
                                            <small class="form-text text-muted d-block mt-1">
                                                O backup será salvo na pasta /backups/ com data e hora atual.
                                            </small>
                                        </div>

                                        <?php
                                        // Listar backups existentes
                                        $backup_dir = '../backups/';
                                        if (file_exists($backup_dir)) {
                                            $backups = glob($backup_dir . 'backup_*.sql');
                                            if (!empty($backups)) {
                                                echo '<h6 class="mt-4">Backups Existentes</h6>';
                                                echo '<div class="table-responsive">';
                                                echo '<table class="table table-sm">';
                                                echo '<thead><tr><th>Arquivo</th><th>Tamanho</th><th>Data</th><th>Ações</th></tr></thead>';
                                                echo '<tbody>';
                                                
                                                rsort($backups); // Ordenar do mais recente para o mais antigo
                                                
                                                foreach (array_slice($backups, 0, 5) as $backup) {
                                                    $filename = basename($backup);
                                                    $filesize = filesize($backup);
                                                    $filedate = date('d/m/Y H:i:s', filemtime($backup));
                                                    
                                                    echo '<tr>';
                                                    echo '<td>' . htmlspecialchars($filename) . '</td>';
                                                    echo '<td>' . number_format($filesize / 1024, 2) . ' KB</td>';
                                                    echo '<td>' . $filedate . '</td>';
                                                    echo '<td>';
                                                    echo '<a href="' . $backup . '" class="btn btn-sm btn-outline-primary me-1" download>';
                                                    echo '<i class="bi bi-download"></i>';
                                                    echo '</a>';
                                                    echo '</td>';
                                                    echo '</tr>';
                                                }
                                                
                                                echo '</tbody></table></div>';
                                            }
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Botão de Salvar -->
                            <div class="card mt-4">
                                <div class="card-body text-center">
                                    <button type="submit" name="acao" value="salvar_configuracoes" class="btn btn-primary btn-lg">
                                        <i class="bi bi-check-circle me-2"></i>Salvar Todas as Configurações
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Navegação entre abas
        document.querySelectorAll('#configTabs .nav-link').forEach(tab => {
            tab.addEventListener('click', function(e) {
                e.preventDefault();
                
                // Remover active de todas as abas
                document.querySelectorAll('#configTabs .nav-link').forEach(t => {
                    t.classList.remove('active');
                });
                
                // Esconder todas as seções
                document.querySelectorAll('.config-section').forEach(s => {
                    s.classList.remove('active');
                });
                
                // Ativar aba e seção atual
                this.classList.add('active');
                const target = this.getAttribute('data-bs-target');
                document.getElementById(target).classList.add('active');
            });
        });

        // Validação de formulário
        document.getElementById('configForm').addEventListener('submit', function(e) {
            const urlInput = document.querySelector('input[name="site_url"]');
            if (urlInput && !urlInput.value.match(/^https?:\/\/.+/)) {
                e.preventDefault();
                alert('Por favor, insira uma URL válida (deve começar com http:// ou https://)');
                urlInput.focus();
                return false;
            }
        });

        // Confirmação para ações destrutivas
        document.querySelector('button[value="limpar_cache"]').addEventListener('click', function(e) {
            if (!confirm('Tem certeza que deseja limpar todo o cache do sistema?')) {
                e.preventDefault();
            }
        });

        document.querySelector('button[value="backup_database"]').addEventListener('click', function(e) {
            if (!confirm('Deseja criar um backup do banco de dados agora?')) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>