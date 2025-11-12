<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/apis.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$mensagem = '';
$erro = '';
$resultados_sincronizacao = [];
$logs_sincronizacao = [];

// Instanciar a classe de APIs
$apiAfiliados = new ApiAfiliados($pdo);

// Processar sincronização
if ($_POST && isset($_POST['acao'])) {
    try {
        $dados = Security::sanitizeXSS($_POST);
        
        if (!Security::validateCSRFToken($dados['csrf_token'])) {
            throw new Exception('Token de segurança inválido.');
        }

        $acao = $dados['acao'];
        $plataforma = $dados['plataforma'] ?? 'todas';
        $categoria = $dados['categoria'] ?? '';
        $limite = (int)($dados['limite'] ?? 50);

        switch ($acao) {
            case 'sincronizar_ofertas':
                $resultados_sincronizacao = sincronizarOfertas($apiAfiliados, $plataforma, $categoria, $limite);
                $mensagem = 'Sincronização de ofertas concluída!';
                break;

            case 'sincronizar_comissoes':
                $resultados_sincronizacao = $apiAfiliados->sincronizarComissoes();
                $mensagem = 'Sincronização de comissões concluída!';
                break;

            case 'sincronizar_categorias':
                $resultados_sincronizacao = sincronizarCategorias($apiAfiliados, $plataforma);
                $mensagem = 'Sincronização de categorias concluída!';
                break;

            case 'testar_conexao':
                $resultados_sincronizacao = testarConexoes($apiAfiliados, $plataforma);
                $mensagem = 'Teste de conexão realizado!';
                break;

            case 'limpar_cache_api':
                limparCacheAPI();
                $mensagem = 'Cache da API limpo com sucesso!';
                break;

            default:
                throw new Exception('Ação não reconhecida.');
        }

        // Registrar log da sincronização
        registrarLogSincronizacao($acao, $plataforma, $resultados_sincronizacao);

    } catch (Exception $e) {
        $erro = 'Erro na sincronização: ' . $e->getMessage();
        registrarLogSincronizacao($acao ?? 'erro', $plataforma ?? '', ['erro' => $e->getMessage()]);
    }
}

// Buscar logs recentes
$stmt = $pdo->prepare("
    SELECT * FROM logs_sincronizacao 
    ORDER BY created_at DESC 
    LIMIT 10
");
$stmt->execute();
$logs_recentes = $stmt->fetchAll();

// Buscar estatísticas de sincronização
$stmt = $pdo->query("
    SELECT 
        plataforma,
        COUNT(*) as total_sincronizacoes,
        SUM(produtos_adicionados) as total_produtos,
        SUM(produtos_atualizados) as total_atualizados,
        MAX(created_at) as ultima_sincronizacao
    FROM logs_sincronizacao 
    WHERE sucesso = 1
    GROUP BY plataforma
");
$estatisticas_sincronizacao = $stmt->fetchAll();

// Buscar configurações de APIs
$configuracoes = [];
$stmt = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave LIKE '%_config'");
$configs_raw = $stmt->fetchAll();

foreach ($configs_raw as $config) {
    $configuracoes[$config['chave']] = json_decode($config['valor'], true);
}

// Função para sincronizar ofertas
function sincronizarOfertas($apiAfiliados, $plataforma, $categoria, $limite) {
    $resultados = [];
    
    $plataformas = $plataforma === 'todas' ? ['mercadolivre', 'shopee', 'aliexpress'] : [$plataforma];
    
    foreach ($plataformas as $plat) {
        try {
            switch ($plat) {
                case 'mercadolivre':
                    $ofertas = $apiAfiliados->mercadolivre('search_products', [
                        'category' => $categoria,
                        'limit' => $limite,
                        'sort' => 'price_desc'
                    ]);
                    break;

                case 'shopee':
                    $ofertas = $apiAfiliados->shopee('product/get_item_list', [
                        'category_id' => $categoria,
                        'page_size' => $limite
                    ]);
                    break;

                case 'aliexpress':
                    $ofertas = $apiAfiliados->aliexpress('listpromotionproduct', [
                        'keywords' => $categoria,
                        'page_size' => $limite
                    ]);
                    break;

                default:
                    continue 2;
            }

            if ($ofertas) {
                $processados = processarOfertas($ofertas, $plat);
                $resultados[$plat] = [
                    'sucesso' => true,
                    'ofertas_recebidas' => count($ofertas),
                    'ofertas_processadas' => count($processados),
                    'detalhes' => $processados
                ];
            } else {
                $resultados[$plat] = [
                    'sucesso' => false,
                    'erro' => 'Nenhuma oferta retornada da API'
                ];
            }

        } catch (Exception $e) {
            $resultados[$plat] = [
                'sucesso' => false,
                'erro' => $e->getMessage()
            ];
        }
    }
    
    return $resultados;
}

// Função para processar ofertas
function processarOfertas($ofertas, $plataforma) {
    global $pdo;
    $processados = [];
    
    foreach ($ofertas as $oferta) {
        try {
            // Verificar se a loja existe
            $loja_id = obterOuCriarLoja($plataforma, $oferta);
            
            // Preparar dados do produto
            $dados_produto = prepararDadosProduto($oferta, $plataforma, $loja_id);
            
            // Verificar se o produto já existe
            $stmt = $pdo->prepare("SELECT id FROM produtos WHERE nome = ? AND loja_id = ?");
            $stmt->execute([$dados_produto['nome'], $loja_id]);
            $produto_existente = $stmt->fetch();
            
            if ($produto_existente) {
                // Atualizar produto existente
                $stmt = $pdo->prepare("
                    UPDATE produtos SET 
                        preco = ?, preco_original = ?, descricao = ?, 
                        link_afiliado = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([
                    $dados_produto['preco'],
                    $dados_produto['preco_original'],
                    $dados_produto['descricao'],
                    $dados_produto['link_afiliado'],
                    $produto_existente['id']
                ]);
                $acao = 'atualizado';
            } else {
                // Inserir novo produto
                $stmt = $pdo->prepare("
                    INSERT INTO produtos (loja_id, nome, slug, descricao, preco, preco_original, link_afiliado, imagens, categoria) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $loja_id,
                    $dados_produto['nome'],
                    $dados_produto['slug'],
                    $dados_produto['descricao'],
                    $dados_produto['preco'],
                    $dados_produto['preco_original'],
                    $dados_produto['link_afiliado'],
                    $dados_produto['imagens'],
                    $dados_produto['categoria']
                ]);
                $acao = 'adicionado';
            }
            
            $processados[] = [
                'produto' => $dados_produto['nome'],
                'preco' => $dados_produto['preco'],
                'acao' => $acao
            ];
            
        } catch (Exception $e) {
            $processados[] = [
                'produto' => $oferta['title'] ?? 'Desconhecido',
                'erro' => $e->getMessage()
            ];
        }
    }
    
    return $processados;
}

// Função auxiliar para obter ou criar loja
function obterOuCriarLoja($plataforma, $dados_oferta) {
    global $pdo;
    
    $nomes_lojas = [
        'mercadolivre' => 'Mercado Livre',
        'shopee' => 'Shopee',
        'aliexpress' => 'AliExpress'
    ];
    
    $nome_loja = $nomes_lojas[$plataforma] ?? ucfirst($plataforma);
    $slug = criarSlug($nome_loja);
    
    $stmt = $pdo->prepare("SELECT id FROM lojas WHERE slug = ?");
    $stmt->execute([$slug]);
    $loja = $stmt->fetch();
    
    if ($loja) {
        return $loja['id'];
    }
    
    // Criar nova loja
    $stmt = $pdo->prepare("INSERT INTO lojas (nome, slug, website) VALUES (?, ?, ?)");
    $stmt->execute([
        $nome_loja,
        $slug,
        $dados_oferta['store_url'] ?? '#'
    ]);
    
    return $pdo->lastInsertId();
}

// Função para preparar dados do produto
function prepararDadosProduto($oferta, $plataforma, $loja_id) {
    $slug = criarSlug($oferta['title'] ?? 'produto-' . uniqid());
    
    // Processar preços
    $preco_original = $oferta['original_price'] ?? $oferta['price'] ?? 0;
    $preco = $oferta['price'] ?? $preco_original;
    
    // Garantir que preço seja menor ou igual ao original
    if ($preco > $preco_original) {
        $preco_original = $preco;
    }
    
    // Processar imagens
    $imagens = [];
    if (isset($oferta['images'])) {
        $imagens = array_slice($oferta['images'], 0, 3);
    } elseif (isset($oferta['image'])) {
        $imagens = [$oferta['image']];
    }
    
    return [
        'nome' => substr($oferta['title'] ?? 'Produto sem nome', 0, 255),
        'slug' => $slug,
        'descricao' => $oferta['description'] ?? $oferta['title'] ?? 'Descrição não disponível',
        'preco' => $preco,
        'preco_original' => $preco_original,
        'link_afiliado' => $oferta['url'] ?? $oferta['affiliate_url'] ?? '#',
        'imagens' => json_encode($imagens),
        'categoria' => $oferta['category'] ?? $plataforma
    ];
}

// Função para sincronizar categorias
function sincronizarCategorias($apiAfiliados, $plataforma) {
    $resultados = [];
    
    try {
        switch ($plataforma) {
            case 'mercadolivre':
                $categorias = $apiAfiliados->mercadolivre('get_categories', []);
                break;
                
            case 'shopee':
                $categorias = $apiAfiliados->shopee('product/get_category', []);
                break;
                
            default:
                throw new Exception('Plataforma não suportada para sincronização de categorias');
        }
        
        if ($categorias) {
            $resultados = [
                'sucesso' => true,
                'categorias_recebidas' => count($categorias),
                'categorias' => array_slice($categorias, 0, 10) // Mostrar apenas as primeiras 10
            ];
        }
        
    } catch (Exception $e) {
        $resultados = [
            'sucesso' => false,
            'erro' => $e->getMessage()
        ];
    }
    
    return $resultados;
}

// Função para testar conexões
function testarConexoes($apiAfiliados, $plataforma) {
    $resultados = [];
    
    $plataformas = $plataforma === 'todas' ? ['mercadolivre', 'shopee', 'aliexpress'] : [$plataforma];
    
    foreach ($plataformas as $plat) {
        try {
            $teste = $apiAfiliados->{$plat}('test_connection', []);
            $resultados[$plat] = [
                'sucesso' => true,
                'resposta' => $teste ? 'Conexão estabelecida' : 'Sem resposta'
            ];
        } catch (Exception $e) {
            $resultados[$plat] = [
                'sucesso' => false,
                'erro' => $e->getMessage()
            ];
        }
    }
    
    return $resultados;
}

// Função para limpar cache da API
function limparCacheAPI() {
    $cache_dirs = [
        '../cache/api/',
        '../tmp/api/'
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
    
    return ['arquivos_limpos' => count($files ?? [])];
}

// Função para registrar logs de sincronização
function registrarLogSincronizacao($acao, $plataforma, $resultados) {
    global $pdo;
    
    $sucesso = true;
    $produtos_adicionados = 0;
    $produtos_atualizados = 0;
    $detalhes = '';
    
    // Analisar resultados para estatísticas
    foreach ($resultados as $plat => $resultado) {
        if (isset($resultado['sucesso']) && !$resultado['sucesso']) {
            $sucesso = false;
        }
        if (isset($resultado['ofertas_processadas'])) {
            $produtos_adicionados += $resultado['ofertas_processadas'];
        }
    }
    
    $detalhes = json_encode($resultados);
    
    $stmt = $pdo->prepare("
        INSERT INTO logs_sincronizacao (acao, plataforma, sucesso, produtos_adicionados, produtos_atualizados, detalhes) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$acao, $plataforma, $sucesso, $produtos_adicionados, $produtos_atualizados, $detalhes]);
}

// Criar tabela de logs se não existir
$pdo->exec("
    CREATE TABLE IF NOT EXISTS logs_sincronizacao (
        id INT PRIMARY KEY AUTO_INCREMENT,
        acao VARCHAR(50) NOT NULL,
        plataforma VARCHAR(50) NOT NULL,
        sucesso BOOLEAN DEFAULT TRUE,
        produtos_adicionados INT DEFAULT 0,
        produtos_atualizados INT DEFAULT 0,
        detalhes JSON,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_plataforma (plataforma),
        KEY idx_created_at (created_at)
    )
");

$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sincronização com APIs - Administração</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .sync-card {
            text-align: center;
            padding: 20px;
            transition: all 0.3s ease;
        }
        .sync-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        .sync-icon {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        .stats-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: #4169E1;
        }
        .log-item {
            border-left: 4px solid #4169E1;
            padding-left: 15px;
            margin-bottom: 10px;
        }
        .log-item.error {
            border-left-color: #dc3545;
        }
        .log-item.success {
            border-left-color: #28a745;
        }
        .progress-bar-api {
            transition: width 0.6s ease;
        }
        .api-status {
            display: inline-block;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            margin-right: 5px;
        }
        .api-status.online {
            background-color: #28a745;
        }
        .api-status.offline {
            background-color: #dc3545;
        }
        .api-status.unknown {
            background-color: #ffc107;
        }
        .result-item {
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 5px;
            background: #f8f9fa;
        }
        .result-item.success {
            background: #d4edda;
            border-left: 4px solid #28a745;
        }
        .result-item.error {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
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
                        <i class="bi bi-cloud-arrow-down me-2"></i>Sincronização com APIs
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button class="btn btn-outline-primary me-2" onclick="atualizarStatus()">
                            <i class="bi bi-arrow-clockwise me-1"></i>Atualizar Status
                        </button>
                    </div>
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

                <!-- Cards de Status das APIs -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card sync-card">
                            <div class="sync-icon text-primary">
                                <i class="bi bi-shop"></i>
                            </div>
                            <h5>Mercado Livre</h5>
                            <div class="api-status <?= !empty($configuracoes['afiliados_config']['ml_app_id']) ? 'online' : 'offline' ?>"></div>
                            <span class="text-muted">
                                <?= !empty($configuracoes['afiliados_config']['ml_app_id']) ? 'Conectado' : 'Não Configurado' ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card sync-card">
                            <div class="sync-icon text-success">
                                <i class="bi bi-bag"></i>
                            </div>
                            <h5>Shopee</h5>
                            <div class="api-status <?= !empty($configuracoes['afiliados_config']['shopee_partner_id']) ? 'online' : 'offline' ?>"></div>
                            <span class="text-muted">
                                <?= !empty($configuracoes['afiliados_config']['shopee_partner_id']) ? 'Conectado' : 'Não Configurado' ?>
                            </span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card sync-card">
                            <div class="sync-icon text-warning">
                                <i class="bi bi-globe"></i>
                            </div>
                            <h5>AliExpress</h5>
                            <div class="api-status <?= !empty($configuracoes['afiliados_config']['ali_app_key']) ? 'online' : 'offline' ?>"></div>
                            <span class="text-muted">
                                <?= !empty($configuracoes['afiliados_config']['ali_app_key']) ? 'Conectado' : 'Não Configurado' ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Estatísticas de Sincronização -->
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-graph-up me-2"></i>Estatísticas de Sincronização
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Plataforma</th>
                                                <th>Sincronizações</th>
                                                <th>Produtos Adicionados</th>
                                                <th>Última Sincronização</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($estatisticas_sincronizacao)): ?>
                                                <tr>
                                                    <td colspan="5" class="text-center text-muted py-3">
                                                        <i class="bi bi-inbox me-2"></i>Nenhuma sincronização realizada
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($estatisticas_sincronizacao as $estat): ?>
                                                <tr>
                                                    <td class="text-capitalize"><?= $estat['plataforma'] ?></td>
                                                    <td><?= $estat['total_sincronizacoes'] ?></td>
                                                    <td><?= $estat['total_produtos'] ?></td>
                                                    <td>
                                                        <?= $estat['ultima_sincronizacao'] ? 
                                                            date('d/m/Y H:i', strtotime($estat['ultima_sincronizacao'])) : 
                                                            'Nunca' ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-success">Ativa</span>
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

                <!-- Ações de Sincronização -->
                <div class="row">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-lightning me-2"></i>Ações de Sincronização
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="syncForm">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                    
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Plataforma</label>
                                            <select name="plataforma" class="form-select" required>
                                                <option value="todas">Todas as Plataformas</option>
                                                <option value="mercadolivre">Mercado Livre</option>
                                                <option value="shopee">Shopee</option>
                                                <option value="aliexpress">AliExpress</option>
                                            </select>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Limite de Produtos</label>
                                            <input type="number" name="limite" class="form-control" value="50" min="1" max="500">
                                        </div>
                                    </div>

                                    <div class="row mb-4">
                                        <div class="col-12">
                                            <label class="form-label">Categoria (Opcional)</label>
                                            <input type="text" name="categoria" class="form-control" placeholder="Ex: eletrônicos, moda, casa...">
                                        </div>
                                    </div>

                                    <div class="row g-2">
                                        <div class="col-md-3">
                                            <button type="submit" name="acao" value="sincronizar_ofertas" 
                                                    class="btn btn-primary w-100">
                                                <i class="bi bi-cloud-download me-1"></i>Sincronizar Ofertas
                                            </button>
                                        </div>
                                        <div class="col-md-3">
                                            <button type="submit" name="acao" value="sincronizar_comissoes" 
                                                    class="btn btn-success w-100">
                                                <i class="bi bi-currency-dollar me-1"></i>Sincronizar Comissões
                                            </button>
                                        </div>
                                        <div class="col-md-3">
                                            <button type="submit" name="acao" value="sincronizar_categorias" 
                                                    class="btn btn-info w-100">
                                                <i class="bi bi-tags me-1"></i>Sincronizar Categorias
                                            </button>
                                        </div>
                                        <div class="col-md-3">
                                            <button type="submit" name="acao" value="testar_conexao" 
                                                    class="btn btn-warning w-100">
                                                <i class="bi bi-wifi me-1"></i>Testar Conexão
                                            </button>
                                        </div>
                                    </div>

                                    <div class="row mt-3">
                                        <div class="col-12">
                                            <button type="submit" name="acao" value="limpar_cache_api" 
                                                    class="btn btn-outline-danger w-100">
                                                <i class="bi bi-trash me-1"></i>Limpar Cache da API
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Resultados da Sincronização -->
                        <?php if (!empty($resultados_sincronizacao)): ?>
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-list-check me-2"></i>Resultados da Sincronização
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php foreach ($resultados_sincronizacao as $plataforma => $resultado): ?>
                                    <div class="result-item <?= $resultado['sucesso'] ? 'success' : 'error' ?>">
                                        <h6 class="text-capitalize"><?= $plataforma ?></h6>
                                        <?php if ($resultado['sucesso']): ?>
                                            <p class="mb-1">
                                                <strong>Ofertas Recebidas:</strong> <?= $resultado['ofertas_recebidas'] ?? 'N/A' ?>
                                            </p>
                                            <p class="mb-1">
                                                <strong>Ofertas Processadas:</strong> <?= $resultado['ofertas_processadas'] ?? 'N/A' ?>
                                            </p>
                                            <?php if (isset($resultado['detalhes']) && is_array($resultado['detalhes'])): ?>
                                                <details class="mt-2">
                                                    <summary>Detalhes dos Produtos</summary>
                                                    <div class="mt-2">
                                                        <?php foreach (array_slice($resultado['detalhes'], 0, 5) as $detalhe): ?>
                                                            <div class="small">
                                                                <?php if (isset($detalhe['erro'])): ?>
                                                                    <span class="text-danger">❌ <?= $detalhe['produto'] ?> - Erro: <?= $detalhe['erro'] ?></span>
                                                                <?php else: ?>
                                                                    <span class="text-success">✅ <?= $detalhe['produto'] ?> - R$ <?= number_format($detalhe['preco'], 2, ',', '.') ?> (<?= $detalhe['acao'] ?>)</span>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endforeach; ?>
                                                        <?php if (count($resultado['detalhes']) > 5): ?>
                                                            <div class="text-muted small">... e mais <?= count($resultado['detalhes']) - 5 ?> produtos</div>
                                                        <?php endif; ?>
                                                    </div>
                                                </details>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <p class="text-danger mb-0">
                                                <strong>Erro:</strong> <?= $resultado['erro'] ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Logs Recentes -->
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-clock-history me-2"></i>Logs Recentes
                                </h5>
                            </div>
                            <div class="card-body">
                                <div style="max-height: 400px; overflow-y: auto;">
                                    <?php if (empty($logs_recentes)): ?>
                                        <div class="text-center text-muted py-3">
                                            <i class="bi bi-inbox me-2"></i>Nenhum log disponível
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($logs_recentes as $log): ?>
                                            <div class="log-item <?= $log['sucesso'] ? 'success' : 'error' ?>">
                                                <div class="small fw-bold text-capitalize">
                                                    <?= $log['acao'] ?> - <?= $log['plataforma'] ?>
                                                </div>
                                                <div class="small text-muted">
                                                    <?= date('d/m H:i', strtotime($log['created_at'])) ?>
                                                </div>
                                                <div class="small">
                                                    <?php if ($log['sucesso']): ?>
                                                        <span class="text-success">
                                                            ✅ <?= $log['produtos_adicionados'] ?> produtos adicionados
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-danger">❌ Falha na sincronização</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Configurações Rápidas -->
                        <div class="card mt-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-gear me-2"></i>Configurações Rápidas
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label small">Frequência de Sincronização Automática</label>
                                    <select class="form-select form-select-sm">
                                        <option>Desativada</option>
                                        <option>1 hora</option>
                                        <option selected>6 horas</option>
                                        <option>12 horas</option>
                                        <option>24 horas</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label small">Notificações por Email</label>
                                    <select class="form-select form-select-sm">
                                        <option selected>Ativadas</option>
                                        <option>Desativadas</option>
                                    </select>
                                </div>
                                
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="autoRetry" checked>
                                    <label class="form-check-label small" for="autoRetry">
                                        Tentativa Automática em Caso de Falha
                                    </label>
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
        // Atualizar status das APIs
        function atualizarStatus() {
            document.querySelectorAll('.api-status').forEach(status => {
                status.classList.add('unknown');
                status.classList.remove('online', 'offline');
            });
            
            setTimeout(() => {
                document.querySelectorAll('.api-status').forEach(status => {
                    status.classList.remove('unknown');
                    status.classList.add('online');
                });
            }, 1000);
        }

        // Confirmação para ações destrutivas
        document.querySelector('button[value="limpar_cache_api"]').addEventListener('click', function(e) {
            if (!confirm('Tem certeza que deseja limpar todo o cache da API? Isso pode afetar o desempenho temporariamente.')) {
                e.preventDefault();
            }
        });

        // Mostrar loading durante sincronização
        document.getElementById('syncForm').addEventListener('submit', function() {
            const buttons = this.querySelectorAll('button[type="submit"]');
            buttons.forEach(button => {
                const originalText = button.innerHTML;
                button.innerHTML = '<i class="bi bi-arrow-repeat spinner"></i> Processando...';
                button.disabled = true;
                
                // Restaurar texto após 30 segundos (caso de timeout)
                setTimeout(() => {
                    button.innerHTML = originalText;
                    button.disabled = false;
                }, 30000);
            });
        });

        // Auto-expandir resultados se houver erro
        <?php if (!empty($resultados_sincronizacao)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                const resultItems = document.querySelectorAll('.result-item.error');
                resultItems.forEach(item => {
                    const details = item.querySelector('details');
                    if (details) {
                        details.open = true;
                    }
                });
            });
        <?php endif; ?>
    </script>
</body>
</html>