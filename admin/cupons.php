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

// Processar formulário de adicionar/editar cupom
if ($_POST && isset($_POST['acao'])) {
    try {
        $dados = Security::sanitizeXSS($_POST);
        
        if (!Security::validateCSRFToken($dados['csrf_token'])) {
            throw new Exception('Token de segurança inválido.');
        }
        
        if ($dados['acao'] === 'adicionar' || $dados['acao'] === 'editar') {
            $loja_id = (int)$dados['loja_id'];
            $tipo = $dados['tipo'];
            $valor = (float)$dados['valor'];
            $descricao = trim($dados['descricao']);
            $codigo = trim($dados['codigo']);
            $link_afiliado = trim($dados['link_afiliado']);
            $data_inicio = $dados['data_inicio'];
            $data_fim = $dados['data_fim'];
            $detalhes = trim($dados['detalhes'] ?? '');
            $limite_uso = !empty($dados['limite_uso']) ? (int)$dados['limite_uso'] : null;
            $destaque = isset($dados['destaque']) ? 1 : 0;
            
            // Validações
            if (empty($descricao) || empty($codigo) || empty($link_afiliado)) {
                throw new Exception('Preencha todos os campos obrigatórios.');
            }
            
            if ($data_inicio > $data_fim) {
                throw new Exception('Data de início não pode ser maior que data de fim.');
            }
            
            if ($dados['acao'] === 'adicionar') {
                $stmt = $pdo->prepare("
                    INSERT INTO cupons (loja_id, tipo, valor, descricao, detalhes, codigo, link_afiliado, data_inicio, data_fim, limite_uso, destaque) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$loja_id, $tipo, $valor, $descricao, $detalhes, $codigo, $link_afiliado, $data_inicio, $data_fim, $limite_uso, $destaque]);
                $mensagem = 'Cupom cadastrado com sucesso!';
            } else {
                $cupom_id = (int)$dados['cupom_id'];
                $stmt = $pdo->prepare("
                    UPDATE cupons 
                    SET loja_id = ?, tipo = ?, valor = ?, descricao = ?, detalhes = ?, codigo = ?, 
                        link_afiliado = ?, data_inicio = ?, data_fim = ?, limite_uso = ?, destaque = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$loja_id, $tipo, $valor, $descricao, $detalhes, $codigo, $link_afiliado, $data_inicio, $data_fim, $limite_uso, $destaque, $cupom_id]);
                $mensagem = 'Cupom atualizado com sucesso!';
            }
        }
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

// Processar exclusão
if (isset($_GET['excluir'])) {
    try {
        $cupom_id = (int)$_GET['excluir'];
        
        $stmt = $pdo->prepare("UPDATE cupons SET ativo = 0 WHERE id = ?");
        $stmt->execute([$cupom_id]);
        $mensagem = 'Cupom excluído com sucesso!';
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

// Processar ativação/desativação
if (isset($_GET['toggle'])) {
    try {
        $cupom_id = (int)$_GET['toggle'];
        
        $stmt = $pdo->prepare("SELECT ativo FROM cupons WHERE id = ?");
        $stmt->execute([$cupom_id]);
        $cupom = $stmt->fetch();
        
        if ($cupom) {
            $novo_status = $cupom['ativo'] ? 0 : 1;
            $stmt = $pdo->prepare("UPDATE cupons SET ativo = ? WHERE id = ?");
            $stmt->execute([$novo_status, $cupom_id]);
            $mensagem = 'Status do cupom alterado com sucesso!';
        }
    } catch (Exception $e) {
        $erro = $e->getMessage();
    }
}

// Buscar cupom para edição
$cupom_edicao = null;
if (isset($_GET['editar'])) {
    $cupom_id = (int)$_GET['editar'];
    $stmt = $pdo->prepare("
        SELECT c.*, l.nome as loja_nome 
        FROM cupons c 
        JOIN lojas l ON c.loja_id = l.id 
        WHERE c.id = ?
    ");
    $stmt->execute([$cupom_id]);
    $cupom_edicao = $stmt->fetch();
}

// Buscar todos os cupons
$filtro_loja = isset($_GET['filtro_loja']) ? (int)$_GET['filtro_loja'] : '';
$filtro_status = isset($_GET['filtro_status']) ? $_GET['filtro_status'] : 'ativos';

$where_conditions = ["c.ativo IN (1" . ($filtro_status === 'todos' ? ',0)' : ')')];
$params = [];

if ($filtro_loja) {
    $where_conditions[] = "c.loja_id = ?";
    $params[] = $filtro_loja;
}

if ($filtro_status === 'expirados') {
    $where_conditions[] = "c.data_fim < CURDATE()";
} elseif ($filtro_status === 'ativos') {
    $where_conditions[] = "c.data_fim >= CURDATE()";
}

$where_sql = implode(' AND ', $where_conditions);

$stmt = $pdo->prepare("
    SELECT c.*, l.nome as loja_nome, l.slug as loja_slug,
           (SELECT COUNT(*) FROM estatisticas e WHERE e.item_id = c.id AND e.tipo = 'cupom') as total_cliques
    FROM cupons c 
    JOIN lojas l ON c.loja_id = l.id 
    WHERE $where_sql
    ORDER BY c.created_at DESC
");
$stmt->execute($params);
$cupons = $stmt->fetchAll();

// Buscar lojas para o select
$stmt = $pdo->query("SELECT id, nome FROM lojas WHERE ativo = 1 ORDER BY nome");
$lojas = $stmt->fetchAll();

$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Cupons - Administração</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
    <style>
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .table-responsive {
            border-radius: 10px;
        }
        .badge-expired {
            background-color: #6c757d;
        }
        .badge-active {
            background-color: #28a745;
        }
        .badge-inactive {
            background-color: #dc3545;
        }
        .cupom-code {
            font-family: 'Courier New', monospace;
            background: #f8f9fa;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: bold;
        }
        .form-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .stats-card {
            text-align: center;
            padding: 15px;
        }
        .stats-number {
            font-size: 2rem;
            font-weight: bold;
            color: #4169E1;
        }
        .action-buttons .btn {
            margin-right: 5px;
            margin-bottom: 5px;
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
                        <i class="bi bi-ticket-perforated me-2"></i>Gerenciar Cupons
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCupom">
                            <i class="bi bi-plus-circle me-1"></i>Novo Cupom
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

                <!-- Cards de Estatísticas -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="stats-number">
                                    <?php
                                    $stmt = $pdo->query("SELECT COUNT(*) FROM cupons WHERE ativo = 1 AND data_fim >= CURDATE()");
                                    echo $stmt->fetchColumn();
                                    ?>
                                </div>
                                <div class="text-muted">Cupons Ativos</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="stats-number">
                                    <?php
                                    $stmt = $pdo->query("SELECT COUNT(*) FROM cupons WHERE ativo = 1 AND data_fim < CURDATE()");
                                    echo $stmt->fetchColumn();
                                    ?>
                                </div>
                                <div class="text-muted">Cupons Expirados</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="stats-number">
                                    <?php
                                    $stmt = $pdo->query("SELECT SUM(cliques) FROM cupons");
                                    echo number_format($stmt->fetchColumn() ?: 0);
                                    ?>
                                </div>
                                <div class="text-muted">Total de Cliques</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stats-card">
                            <div class="card-body">
                                <div class="stats-number">
                                    <?php
                                    $stmt = $pdo->query("SELECT COUNT(*) FROM cupons WHERE destaque = 1 AND ativo = 1");
                                    echo $stmt->fetchColumn();
                                    ?>
                                </div>
                                <div class="text-muted">Em Destaque</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filtros -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Filtrar por Loja</label>
                                <select name="filtro_loja" class="form-select">
                                    <option value="">Todas as Lojas</option>
                                    <?php foreach ($lojas as $loja): ?>
                                        <option value="<?= $loja['id'] ?>" <?= $filtro_loja == $loja['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($loja['nome']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Status</label>
                                <select name="filtro_status" class="form-select">
                                    <option value="ativos" <?= $filtro_status === 'ativos' ? 'selected' : '' ?>>Ativos</option>
                                    <option value="expirados" <?= $filtro_status === 'expirados' ? 'selected' : '' ?>>Expirados</option>
                                    <option value="inativos" <?= $filtro_status === 'inativos' ? 'selected' : '' ?>>Inativos</option>
                                    <option value="todos" <?= $filtro_status === 'todos' ? 'selected' : '' ?>>Todos</option>
                                </select>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary me-2">
                                    <i class="bi bi-funnel me-1"></i>Filtrar
                                </button>
                                <a href="cupons.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-clockwise me-1"></i>Limpar
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Tabela de Cupons -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Lista de Cupons</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Cupom</th>
                                        <th>Loja</th>
                                        <th>Desconto</th>
                                        <th>Código</th>
                                        <th>Validade</th>
                                        <th>Cliques</th>
                                        <th>Status</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($cupons)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted py-4">
                                                <i class="bi bi-inbox me-2"></i>Nenhum cupom encontrado
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($cupons as $cupom): 
                                            $desconto = $cupom['tipo'] === 'percentual' ? 
                                                $cupom['valor'] . '%' : 
                                                'R$ ' . number_format($cupom['valor'], 2, ',', '.');
                                            
                                            $hoje = date('Y-m-d');
                                            $status_class = '';
                                            $status_text = '';
                                            
                                            if (!$cupom['ativo']) {
                                                $status_class = 'badge-inactive';
                                                $status_text = 'Inativo';
                                            } elseif ($cupom['data_fim'] < $hoje) {
                                                $status_class = 'badge-expired';
                                                $status_text = 'Expirado';
                                            } else {
                                                $status_class = 'badge-active';
                                                $status_text = 'Ativo';
                                            }
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold"><?= htmlspecialchars($cupom['descricao']) ?></div>
                                                <?php if ($cupom['destaque']): ?>
                                                    <span class="badge bg-warning text-dark">Destaque</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($cupom['loja_nome']) ?></td>
                                            <td>
                                                <span class="badge bg-success"><?= $desconto ?></span>
                                            </td>
                                            <td>
                                                <code class="cupom-code"><?= htmlspecialchars($cupom['codigo']) ?></code>
                                            </td>
                                            <td>
                                                <small>
                                                    <?= date('d/m/Y', strtotime($cupom['data_fim'])) ?>
                                                    <?php if ($cupom['data_fim'] < $hoje): ?>
                                                        <br><span class="text-danger">Expirado</span>
                                                    <?php endif; ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span class="badge bg-info"><?= $cupom['total_cliques'] ?></span>
                                            </td>
                                            <td>
                                                <span class="badge <?= $status_class ?>"><?= $status_text ?></span>
                                            </td>
                                            <td class="action-buttons">
                                                <button class="btn btn-sm btn-outline-primary" 
                                                        onclick="editarCupom(<?= $cupom['id'] ?>)"
                                                        data-bs-toggle="tooltip" title="Editar">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                
                                                <a href="?toggle=<?= $cupom['id'] ?>" 
                                                   class="btn btn-sm <?= $cupom['ativo'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                                   data-bs-toggle="tooltip" title="<?= $cupom['ativo'] ? 'Desativar' : 'Ativar' ?>">
                                                    <i class="bi bi-power"></i>
                                                </a>
                                                
                                                <a href="?excluir=<?= $cupom['id'] ?>" 
                                                   class="btn btn-sm btn-outline-danger"
                                                   data-bs-toggle="tooltip" title="Excluir"
                                                   onclick="return confirm('Tem certeza que deseja excluir este cupom?')">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal para Adicionar/Editar Cupom -->
    <div class="modal fade" id="modalCupom" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitulo">
                        <i class="bi bi-ticket-perforated me-2"></i>
                        <span id="modalTituloTexto">Adicionar Novo Cupom</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="formCupom">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="acao" id="acao" value="adicionar">
                        <input type="hidden" name="cupom_id" id="cupom_id" value="">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Loja *</label>
                                    <select name="loja_id" class="form-select" required id="loja_id">
                                        <option value="">Selecione uma loja</option>
                                        <?php foreach ($lojas as $loja): ?>
                                            <option value="<?= $loja['id'] ?>"><?= htmlspecialchars($loja['nome']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Tipo de Desconto *</label>
                                    <select name="tipo" class="form-select" required id="tipo">
                                        <option value="percentual">Percentual (%)</option>
                                        <option value="valor">Valor Fixo (R$)</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label" id="labelValor">Valor do Desconto *</label>
                                    <div class="input-group">
                                        <input type="number" name="valor" class="form-control" step="0.01" min="0" required id="valor">
                                        <span class="input-group-text" id="simboloValor">%</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Código do Cupom *</label>
                                    <div class="input-group">
                                        <input type="text" name="codigo" class="form-control" required id="codigo">
                                        <button type="button" class="btn btn-outline-secondary" onclick="gerarCodigo()">
                                            <i class="bi bi-shuffle"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Descrição *</label>
                            <input type="text" name="descricao" class="form-control" required id="descricao" 
                                   placeholder="Ex: 50% OFF em Eletrônicos">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Detalhes (Opcional)</label>
                            <textarea name="detalhes" class="form-control" rows="3" id="detalhes" 
                                      placeholder="Descreva os detalhes do cupom, condições de uso, etc."></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Link Afiliado *</label>
                            <input type="url" name="link_afiliado" class="form-control" required id="link_afiliado" 
                                   placeholder="https://...">
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Data de Início *</label>
                                    <input type="date" name="data_inicio" class="form-control" required id="data_inicio" 
                                           value="<?= date('Y-m-d') ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Data de Fim *</label>
                                    <input type="date" name="data_fim" class="form-control" required id="data_fim" 
                                           min="<?= date('Y-m-d') ?>">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Limite de Usos (Opcional)</label>
                                    <input type="number" name="limite_uso" class="form-control" min="1" id="limite_uso" 
                                           placeholder="Deixe em branco para ilimitado">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3 form-check pt-4">
                                    <input type="checkbox" name="destaque" class="form-check-input" id="destaque" value="1">
                                    <label class="form-check-label" for="destaque">Destacar este cupom</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" id="btnSubmit">
                            <i class="bi bi-check-circle me-1"></i>
                            <span id="btnSubmitTexto">Cadastrar Cupom</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        // Inicializar Select2
        $(document).ready(function() {
            $('#loja_id').select2({
                placeholder: 'Selecione uma loja',
                dropdownParent: $('#modalCupom')
            });
        });

        // Alterar símbolo do valor baseado no tipo
        document.getElementById('tipo').addEventListener('change', function() {
            const simbolo = this.value === 'percentual' ? '%' : 'R$';
            document.getElementById('simboloValor').textContent = simbolo;
            document.getElementById('labelValor').textContent = 
                this.value === 'percentual' ? 'Valor do Desconto (%) *' : 'Valor do Desconto (R$) *';
        });

        // Gerar código aleatório
        function gerarCodigo() {
            const caracteres = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            let codigo = '';
            for (let i = 0; i < 8; i++) {
                codigo += caracteres.charAt(Math.floor(Math.random() * caracteres.length));
            }
            document.getElementById('codigo').value = codigo;
        }

        // Editar cupom
        function editarCupom(cupomId) {
            fetch(`../ajax/cupom-admin.php?action=get&id=${cupomId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const cupom = data.cupom;
                        
                        // Preencher formulário
                        document.getElementById('modalTituloTexto').textContent = 'Editar Cupom';
                        document.getElementById('acao').value = 'editar';
                        document.getElementById('cupom_id').value = cupom.id;
                        document.getElementById('btnSubmitTexto').textContent = 'Atualizar Cupom';
                        
                        document.getElementById('loja_id').value = cupom.loja_id;
                        $('#loja_id').trigger('change');
                        
                        document.getElementById('tipo').value = cupom.tipo;
                        document.getElementById('tipo').dispatchEvent(new Event('change'));
                        
                        document.getElementById('valor').value = cupom.valor;
                        document.getElementById('codigo').value = cupom.codigo;
                        document.getElementById('descricao').value = cupom.descricao;
                        document.getElementById('detalhes').value = cupom.detalhes || '';
                        document.getElementById('link_afiliado').value = cupom.link_afiliado;
                        document.getElementById('data_inicio').value = cupom.data_inicio;
                        document.getElementById('data_fim').value = cupom.data_fim;
                        document.getElementById('limite_uso').value = cupom.limite_uso || '';
                        document.getElementById('destaque').checked = cupom.destaque == 1;
                        
                        // Abrir modal
                        new bootstrap.Modal(document.getElementById('modalCupom')).show();
                    } else {
                        alert('Erro ao carregar cupom: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    alert('Erro ao carregar cupom.');
                });
        }

        // Limpar formulário ao fechar modal
        document.getElementById('modalCupom').addEventListener('hidden.bs.modal', function () {
            document.getElementById('formCupom').reset();
            document.getElementById('modalTituloTexto').textContent = 'Adicionar Novo Cupom';
            document.getElementById('acao').value = 'adicionar';
            document.getElementById('btnSubmitTexto').textContent = 'Cadastrar Cupom';
            document.getElementById('cupom_id').value = '';
            $('#loja_id').val(null).trigger('change');
        });

        // Validação de datas
        document.getElementById('data_inicio').addEventListener('change', function() {
            const dataFim = document.getElementById('data_fim');
            if (dataFim.value && this.value > dataFim.value) {
                alert('Data de início não pode ser maior que data de fim!');
                this.value = '';
            }
        });

        document.getElementById('data_fim').addEventListener('change', function() {
            const dataInicio = document.getElementById('data_inicio');
            if (dataInicio.value && dataInicio.value > this.value) {
                alert('Data de fim não pode ser menor que data de início!');
                this.value = '';
            }
        });

        // Tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    </script>
</body>
</html>