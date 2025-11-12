<?php
require_once '../includes/config.php';
require_once '../includes/auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    exit('Não autorizado');
}

// Parâmetros do período
$periodo = $_GET['periodo'] ?? '7d';
$data_inicio = $_GET['data_inicio'] ?? date('Y-m-d', strtotime('-7 days'));
$data_fim = $_GET['data_fim'] ?? date('Y-m-d');

// Configurar headers para download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=relatorio-estatisticas-' . $data_inicio . '-a-' . $data_fim . '.csv');

// Criar output
$output = fopen('php://output', 'w');

// Header do CSV
fputcsv($output, [
    'Data',
    'Cliques',
    'Conversões',
    'Taxa de Conversão (%)',
    'Itens Únicos'
], ';');

// Buscar dados
$stmt = $pdo->prepare("
    SELECT 
        data_acesso as data,
        SUM(cliques) as cliques,
        SUM(conversoes) as conversoes,
        CASE 
            WHEN SUM(cliques) > 0 THEN ROUND((SUM(conversoes) / SUM(cliques)) * 100, 2)
            ELSE 0 
        END as taxa_conversao,
        COUNT(DISTINCT item_id) as itens_unicos
    FROM estatisticas 
    WHERE data_acesso BETWEEN ? AND ?
    GROUP BY data_acesso 
    ORDER BY data_acesso
");
$stmt->execute([$data_inicio, $data_fim]);
$dados = $stmt->fetchAll();

// Escrever dados
foreach ($dados as $linha) {
    fputcsv($output, [
        $linha['data'],
        $linha['cliques'],
        $linha['conversoes'],
        $linha['taxa_conversao'],
        $linha['itens_unicos']
    ], ';');
}

fclose($output);
exit;