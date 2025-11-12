<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// Processar formulário
if ($_POST) {
    $loja_id = $_POST['loja_id'];
    $nome = $_POST['nome'];
    $descricao = $_POST['descricao'];
    $preco = $_POST['preco'];
    $preco_original = $_POST['preco_original'];
    $cupom_desconto = $_POST['cupom_desconto'];
    $link_afiliado = $_POST['link_afiliado'];
    
    // Processar imagens
    $imagens = [];
    for ($i = 1; $i <= 3; $i++) {
        if (!empty($_FILES["imagem_$i"]['name'])) {
            $imagem_name = uploadImagem($_FILES["imagem_$i"]);
            if ($imagem_name) {
                $imagens[] = $imagem_name;
            }
        }
    }
    
    $stmt = $pdo->prepare("INSERT INTO produtos (loja_id, nome, descricao, preco, preco_original, cupom_desconto, link_afiliado, imagens) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$loja_id, $nome, $descricao, $preco, $preco_original, $cupom_desconto, $link_afiliado, json_encode($imagens)]);
    
    header('Location: produtos.php?success=1');
    exit;
}

// Upload de imagens
function uploadImagem($file) {
    $allowed = ['jpg', 'jpeg', 'png', 'gif'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (in_array($ext, $allowed)) {
        $filename = uniqid() . '.' . $ext;
        $path = '../assets/images/produtos/' . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $path)) {
            return $filename;
        }
    }
    return null;
}

// Buscar produtos
$stmt = $pdo->query("
    SELECT p.*, l.nome as loja_nome 
    FROM produtos p 
    JOIN lojas l ON p.loja_id = l.id 
    ORDER BY p.created_at DESC
");
$produtos = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Produtos - Administração</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include 'includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Gerenciar Produtos</h1>
                </div>

                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success">Produto cadastrado com sucesso!</div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Adicionar Produto</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" enctype="multipart/form-data">
                                    <div class="mb-3">
                                        <label class="form-label">Loja</label>
                                        <select name="loja_id" class="form-control" required>
                                            <?php
                                            $stmt = $pdo->query("SELECT * FROM lojas ORDER BY nome");
                                            while ($loja = $stmt->fetch()) {
                                                echo "<option value='{$loja['id']}'>{$loja['nome']}</option>";
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Nome do Produto</label>
                                        <input type="text" name="nome" class="form-control" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Descrição</label>
                                        <textarea name="descricao" class="form-control" rows="3" required></textarea>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Preço Original</label>
                                                <input type="number" name="preco_original" class="form-control" step="0.01" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label class="form-label">Preço com Desconto</label>
                                                <input type="number" name="preco" class="form-control" step="0.01" required>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Cupom de Desconto (Opcional)</label>
                                        <input type="text" name="cupom_desconto" class="form-control">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Link Afiliado</label>
                                        <input type="url" name="link_afiliado" class="form-control" required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Imagens do Produto (Máx 3)</label>
                                        <input type="file" name="imagem_1" class="form-control mb-2" accept="image/*">
                                        <input type="file" name="imagem_2" class="form-control mb-2" accept="image/*">
                                        <input type="file" name="imagem_3" class="form-control" accept="image/*">
                                    </div>
                                    
                                    <button type="submit" class="btn btn-success">Salvar Produto</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Produtos Cadastrados</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Produto</th>
                                                <th>Loja</th>
                                                <th>Preço</th>
                                                <th>Ações</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($produtos as $produto): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($produto['nome']) ?></td>
                                                <td><?= htmlspecialchars($produto['loja_nome']) ?></td>
                                                <td>R$ <?= number_format($produto['preco'], 2, ',', '.') ?></td>
                                                <td>
                                                    <button class="btn btn-sm btn-outline-primary">Editar</button>
                                                    <button class="btn btn-sm btn-outline-danger">Excluir</button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
</body>
</html>