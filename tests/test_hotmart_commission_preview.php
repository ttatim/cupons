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
        'page_info' => ['total_results' => 2],
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
