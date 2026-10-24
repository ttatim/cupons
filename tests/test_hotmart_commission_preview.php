<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/affiliate_integrations.php';
require_once __DIR__ . '/../includes/hotmart_commission_preview.php';

function assertCommission(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Falhou: ' . $message);
    }
}
putenv('HOTMART_CLIENT_ID=test-client');
putenv('HOTMART_CLIENT_SECRET=test-secret');
putenv('HOTMART_BASIC_TOKEN=Basic test-token');
$calls = [];
$transport = static function (string $method, string $url, array $headers, ?string $body) use (&$calls): array {
    $calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers];
    if (count($calls) === 1) {
        return ['status' => 200, 'body' => '{"access_token":"temporary-test-token","expires_in":3600}'];
    }
    return ['status' => 200, 'body' => json_encode([
        'items' => [
            ['transaction' => 'PRIVATE-TRANSACTION', 'buyer' => ['email' => 'private@example.invalid'], 'commissions' => [
                ['source' => 'AFFILIATE', 'commission' => ['currency_value' => 'BRL', 'value' => 19.95], 'user' => ['name' => 'PRIVATE PERSON']],
                ['source' => 'PRODUCER', 'commission' => ['currency_value' => 'BRL', 'value' => 2.5]],
            ]],
            ['commissions' => [
                ['source' => 'AFFILIATE', 'commission' => ['currency_value' => 'BRL', 'value' => '0.05']],
                ['source' => 'ADDON', 'commission' => ['currency_value' => 'USD', 'value' => 1]],
            ]],
        ],
        'page_info' => ['total_results' => 2, 'next_page_token' => 'eyJwYWdlIjoyfQ=='],
    ], JSON_THROW_ON_ERROR)];
};
$client = new AffiliateIntegrations(Closure::fromCallable($transport));
$data = $client->hotmartSalesCommissions(10);
assertCommission(count($calls) === 2, 'OAuth e consulta de comissões');
assertCommission($calls[1]['method'] === 'GET' && str_contains($calls[1]['url'], '/sales/commissions?max_results=10'), 'endpoint oficial');
assertCommission(in_array('Authorization: Bearer temporary-test-token', $calls[1]['headers'], true), 'Bearer utilizado');
$summary = HotmartCommissionPreview::summarize($data['items']);
assertCommission($summary['transactions_in_page'] === 2 && $summary['commissions_in_page'] === 4, 'contagem');
assertCommission($summary['totals'][0]['amount'] === '20.00' && $summary['totals'][0]['source'] === 'AFFILIATE', 'agregação sem ponto flutuante');
assertCommission($summary['totals'][1]['amount'] === '2.50' && $summary['totals'][2]['amount'] === '1.00', 'moedas e origens separadas');
assertCommission(!str_contains(json_encode($summary, JSON_THROW_ON_ERROR), 'PRIVATE'), 'sem PII nem identificadores de transação');
$client->hotmartSalesCommissions(5);
assertCommission(count($calls) === 3, 'OAuth reutilizado');
assertCommission($data['page_info']['next_page_token'] === 'eyJwYWdlIjoyfQ==', 'cursor validado');
$nextPage = $client->hotmartSalesCommissions(10, $data['page_info']['next_page_token']);
assertCommission(count($calls) === 4, 'próxima página sem renovar OAuth');
assertCommission(str_contains($calls[3]['url'], 'page_token=eyJwYWdlIjoyfQ%3D%3D'), 'cursor codificado por http_build_query');
assertCommission($nextPage['page_info']['next_page_token'] === 'eyJwYWdlIjoyfQ==', 'próximo cursor devolvido');
foreach (["bad\\r\\nInjected: yes", "bad&other=1", str_repeat('x', 1025), ''] as $badCursor) {
    try {
        $client->hotmartSalesCommissions(10, $badCursor);
        throw new RuntimeException('Cursor inválido aceito');
    } catch (InvalidArgumentException $expected) {}
}
assertCommission(count($calls) === 4, 'cursor inválido não gera requisição');
$client->hotmartSalesCommissions(10, null, 'REFUNDED');
assertCommission(str_contains($calls[4]['url'], 'transaction_status=REFUNDED'), 'filtro de reembolso codificado');
try {
    $client->hotmartSalesCommissions(10, null, 'UNSUPPORTED');
    throw new RuntimeException('Filtro de status inválido aceito');
} catch (InvalidArgumentException $expected) {}
assertCommission(count($calls) === 5, 'status inválido não gera requisição');
$invalidCursorTransport = static function (string $method, string $url): array {
    if (str_contains($url, '/oauth/token')) {
        return ['status' => 200, 'body' => '{"access_token":"test-token","expires_in":3600}'];
    }
    return ['status' => 200, 'body' => '{"items":[],"page_info":{"next_page_token":"bad&unsafe=1"}}'];
};
try {
    (new AffiliateIntegrations(Closure::fromCallable($invalidCursorTransport)))->hotmartSalesCommissions(10);
    throw new RuntimeException('Cursor inválido retornado pela API foi aceito');
} catch (RuntimeException $expected) {
    assertCommission(str_contains($expected->getMessage(), 'cursor'), 'resposta da API com cursor inválido bloqueada');
}
foreach ([0, 51] as $limit) {
    try {
        $client->hotmartSalesCommissions($limit);
        throw new RuntimeException('Aceitou limite inválido');
    } catch (InvalidArgumentException $expected) {}
}
foreach ([
    [['commissions' => [['source' => 'AFFILIATE', 'commission' => ['currency_value' => 'BRL', 'value' => '1.234']]]]],
    [['commissions' => [['source' => 'AFFILIATE', 'commission' => ['currency_value' => 'BRL', 'value' => '1e6']]]]],
    [['commissions' => [['source' => "AFFILIATE\r\n", 'commission' => ['currency_value' => 'BRL', 'value' => 1]]]]],
    [['commissions' => [['source' => 'AFFILIATE', 'commission' => ['currency_value' => 'BRL', 'value' => INF]]]]],
    [['commissions' => 'not-an-array']],
] as $invalid) {
    try {
        HotmartCommissionPreview::summarize($invalid);
        throw new RuntimeException('Aceitou payload inválido');
    } catch (InvalidArgumentException $expected) {}
}
$unknown = HotmartCommissionPreview::summarize([['commissions' => [['source' => 'SOMETHING_NEW', 'commission' => ['currency_value' => 'BRL', 'value' => -0.25]]]]]);
assertCommission($unknown['totals'][0]['source'] === 'OUTROS' && $unknown['totals'][0]['amount'] === '-0.25', 'origem desconhecida e valor negativo');
echo "Prévia Hotmart: autenticação simulada, agregação e privacidade aprovadas.\n";
