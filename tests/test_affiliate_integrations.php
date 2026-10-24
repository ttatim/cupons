<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/affiliate_integrations.php';

function checkAffiliate(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('Falhou: ' . $message);
    }
}
foreach (['HOTMART_CLIENT_ID', 'HOTMART_CLIENT_SECRET', 'HOTMART_BASIC_TOKEN'] as $key) {
    putenv($key);
}
checkAffiliate(!AffiliateIntegrations::credentialsPresent(), 'sem credenciais deve estar desabilitado');
checkAffiliate(AffiliateIntegrations::status()['kiwify']['implemented'] === true, 'Kiwify implementada em modo leitura');
try {
    (new AffiliateIntegrations())->hotmartSalesSummary();
    throw new RuntimeException('Credenciais ausentes não bloquearam consulta');
} catch (RuntimeException $e) {
    checkAffiliate(str_contains($e->getMessage(), 'Configure'), 'erro esperado de configuração');
}
putenv('HOTMART_CLIENT_ID=client-test');
putenv('HOTMART_CLIENT_SECRET=secret-test');
putenv('HOTMART_BASIC_TOKEN=Basic basic-test');
$requests = [];
$transport = static function (string $method, string $url, array $headers, ?string $body) use (&$requests): array {
    $requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers];
    if (count($requests) === 1) {
        return ['status' => 200, 'body' => '{"access_token":"temporary-token","expires_in":3600}'];
    }
    return ['status' => 200, 'body' => '{"items":[{"total_items":2,"total_value":{"value":99.9,"currency_code":"BRL"}}],"page_info":{"total_results":1}}'];
};
$client = new AffiliateIntegrations(Closure::fromCallable($transport));
$result = $client->hotmartSalesSummary(10);
checkAffiliate(count($result['items']) === 1, 'sumário retornado');
checkAffiliate(count($requests) === 2, 'OAuth seguido de consulta');
checkAffiliate($requests[0]['method'] === 'POST' && str_contains($requests[0]['url'], 'grant_type=client_credentials'), 'OAuth correto');
checkAffiliate(in_array('Authorization: Basic basic-test', $requests[0]['headers'], true), 'Basic correto');
checkAffiliate(in_array('Authorization: Bearer temporary-token', $requests[1]['headers'], true), 'Bearer correto');
checkAffiliate(str_contains($requests[1]['url'], '/sales/summary?max_results=10'), 'rota de sumário correta');
$client->hotmartSalesSummary(5);
checkAffiliate(count($requests) === 3, 'token reutilizado na mesma execução');
try {
    $client->hotmartSalesSummary(1000);
    throw new RuntimeException('Limite excessivo aceito');
} catch (InvalidArgumentException $e) {
    checkAffiliate(str_contains($e->getMessage(), 'Limite'), 'limite aplicado');
}
$failure = new AffiliateIntegrations(static fn () => ['status' => 401, 'body' => '{"secret":"do not print"}']);
try {
    $failure->hotmartSalesSummary();
    throw new RuntimeException('HTTP 401 foi aceito');
} catch (RuntimeException $e) {
    checkAffiliate(!str_contains($e->getMessage(), 'do not print') && str_contains($e->getMessage(), '401'), 'erro não expõe resposta sensível');
}
echo "Integração Hotmart: testes com transporte simulado aprovados.\n";
