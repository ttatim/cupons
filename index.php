<?php
require_once 'includes/config.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TTaTim Cupons - Melhores Ofertas e Descontos</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --royal-blue: #4169E1;
            --light-gray: #f8f9fa;
        }
        .header, .footer {
            background: var(--royal-blue);
            color: white;
        }
        .offer-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            padding: 15px;
        }
        .btn-red {
            background: #dc3545;
            color: white;
            width: 100%;
            padding: 10px;
        }
        .btn-red:hover {
            background: #c82333;
            color: white;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header py-3">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h1 class="h4 mb-0 text-white">TTaTim Cupons</h1>
                </div>
                <div class="col-md-6 text-end">
                    <a href="admin/login.php" class="btn btn-light btn-sm">Área Admin</a>
                </div>
            </div>
        </div>
    </header>

    <!-- Conteúdo Principal -->
    <main class="container my-4">
        <div class="row">
            <!-- Lista de Cupons -->
            <div class="col-md-8">
                <?php
                $stmt = $pdo->query("
                    SELECT c.*, l.nome as loja_nome, l.slug as loja_slug 
                    FROM cupons c 
                    JOIN lojas l ON c.loja_id = l.id 
                    WHERE c.ativo = 1 AND c.data_fim >= CURDATE() 
                    ORDER BY c.created_at DESC
                    LIMIT 20
                ");
                
                while ($cupom = $stmt->fetch()) {
                    $desconto = $cupom['tipo'] === 'percentual' ? $cupom['valor'] . '%' : 'R$ ' . $cupom['valor'];
                ?>
                <div class="offer-card">
                    <div class="row">
                        <div class="col-md-8">
                            <h5><?= htmlspecialchars($cupom['descricao']) ?></h5>
                            <p class="text-success mb-1"><strong><?= $desconto ?> de desconto</strong></p>
                            <p class="text-muted mb-1">Código: <code><?= htmlspecialchars($cupom['codigo']) ?></code></p>
                            <p class="text-muted">Válido até: <?= date('d/m/Y', strtotime($cupom['data_fim'])) ?></p>
                        </div>
                        <div class="col-md-4">
                            <button class="btn btn-red" onclick="abrirCupom(<?= $cupom['id'] ?>)">
                                Me leve para a loja
                            </button>
                        </div>
                    </div>
                </div>
                <?php } ?>
            </div>

            <!-- Sidebar com Lojas -->
            <div class="col-md-4">
                <div class="offer-card">
                    <h5>Lojas Parceiras</h5>
                    <?php
                    $stmt = $pdo->query("
                        SELECT l.*, COUNT(c.id) as total_cupons 
                        FROM lojas l 
                        LEFT JOIN cupons c ON l.id = c.loja_id AND c.ativo = 1 AND c.data_fim >= CURDATE()
                        GROUP BY l.id 
                        ORDER BY total_cupons DESC
                    ");
                    
                    while ($loja = $stmt->fetch()) {
                        echo "<a href='loja.php?slug={$loja['slug']}' class='d-block py-2 text-decoration-none'>{$loja['nome']} ({$loja['total_cupons']})</a>";
                    }
                    ?>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer py-4 mt-5">
        <div class="container text-center">
            <p class="mb-0">&copy; 2024 TTaTim Cupons. Todos os direitos reservados.</p>
        </div>
    </footer>

    <!-- Modal para Cupom -->
    <div class="modal fade" id="cupomModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Detalhes do Cupom</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalContent">
                    <!-- Conteúdo carregado via AJAX -->
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function abrirCupom(cupomId) {
        fetch(`ajax/cupom.php?id=${cupomId}`)
            .then(response => response.json())
            .then(data => {
                document.getElementById('modalContent').innerHTML = `
                    <h6>${data.descricao}</h6>
                    <p><strong>Desconto:</strong> ${data.desconto}</p>
                    <p><strong>Código:</strong> <code>${data.codigo}</code></p>
                    <p><strong>Validade:</strong> ${data.validade}</p>
                    <button class="btn btn-red" onclick="irParaLoja('${data.link}')">
                        Me leve para a loja
                    </button>
                `;
                new bootstrap.Modal(document.getElementById('cupomModal')).show();
            });
    }

    function irParaLoja(link) {
        window.open(link, '_blank');
    }
    </script>
</body>
</html>