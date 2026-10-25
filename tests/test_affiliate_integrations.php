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
// Cabeçalhos não podem conter CR/LF, mesmo quando as credenciais vêm do ambiente.
putenv('HOTMART_BASIC_TOKEN=Basic basic-test' . "\r\n" . 'X-Test: injected');
$called = false;
try {
    (new AffiliateIntegrations(static function () use (&$called): array {
        $called = true;
        return ['status' => 200, 'body' => '{}'];
    }))->hotmartSalesSummary();
    throw new RuntimeException('Aceitou injeção de cabeçalho Basic');
} catch (RuntimeException $e) {
    checkAffiliate(str_contains($e->getMessage(), 'inválido'), 'Basic com CRLF rejeitado');
}
checkAffiliate(!$called, 'não envia Basic inválido ao transporte');

putenv('HOTMART_BASIC_TOKEN=Basic ' . str_repeat('a', 8193));
try {
    (new AffiliateIntegrations(static function () use (&$called): array {
        $called = true;
        return ['status' => 200, 'body' => '{}'];
    }))->hotmartSalesSummary();
    throw new RuntimeException('Aceitou Basic excessivamente longo');
} catch (RuntimeException $e) {
    checkAffiliate(str_contains($e->getMessage(), 'inválido'), 'Basic longo rejeitado');
}
checkAffiliate(!$called, 'não envia Basic longo ao transporte');

putenv('HOTMART_BASIC_TOKEN=Basic basic-test');
$oauthRequests = 0;
$badBearer = new AffiliateIntegrations(static function () use (&$oauthRequests): array {
    $oauthRequests++;
    return ['status' => 200, 'body' => '{"access_token":"safe\\r\\nX-Test: injected","expires_in":3600}'];
});
try {
    $badBearer->hotmartSalesSummary();
    throw new RuntimeException('Aceitou token Bearer com CRLF');
} catch (RuntimeException $e) {
    checkAffiliate(str_contains($e->getMessage(), 'token de acesso válido'), 'Bearer com CRLF rejeitado');
}
checkAffiliate($oauthRequests === 1, 'não consulta API de vendas com Bearer inválido');

$spaceBearer = new AffiliateIntegrations(static fn () => [
    'status' => 200,
    'body' => '{"access_token":"token com espaco","expires_in":3600}',
]);
try {
    $spaceBearer->hotmartSalesSummary();
    throw new RuntimeException('Aceitou token Bearer com espaço');
} catch (RuntimeException $e) {
    checkAffiliate(str_contains($e->getMessage(), 'token de acesso válido'), 'Bearer com espaço rejeitado');
}
// Uma resposta de sumário não pode ultrapassar o max_results solicitado.
$oversizedSummaryCalls = 0;
$oversizedSummary = new AffiliateIntegrations(static function () use (&$oversizedSummaryCalls): array {
    $oversizedSummaryCalls++;
    if ($oversizedSummaryCalls === 1) {
        return ['status' => 200, 'body' => '{"access_token":"synthetic-token","expires_in":300}'];
    }
    return ['status' => 200, 'body' => json_encode([
        'items' => array_fill(0, 11, ['total_items' => 1]),
    ], JSON_THROW_ON_ERROR)];
});
try {
    $oversizedSummary->hotmartSalesSummary(10);
    throw new RuntimeException('Aceitou sumário Hotmart acima do limite');
} catch (RuntimeException $e) {
    checkAffiliate($e->getMessage() === 'Hotmart retornou mais resultados que o limite solicitado.', 'rejeita sumário acima do limite');
}
checkAffiliate($oversizedSummaryCalls === 2, 'limite aplicado após a consulta de sumário');

// Um objeto indexado não é uma lista de itens e não pode ser tratado como tal.
$invalidSummary = new AffiliateIntegrations(static function (string $method, string $url): array {
    if (str_contains($url, '/oauth/token')) {
        return ['status' => 200, 'body' => '{"access_token":"synthetic-token","expires_in":300}'];
    }
    return ['status' => 200, 'body' => '{"items":{"unexpected":{"total_items":1}}}'];
});
try {
    $invalidSummary->hotmartSalesSummary(10);
    throw new RuntimeException('Aceitou sumário Hotmart com items não-lista');
} catch (RuntimeException $e) {
    checkAffiliate($e->getMessage() === 'Resposta da Hotmart não contém uma lista válida de resultados.', 'rejeita items não-lista');
}

// O limite exato continua válido, preservando a consulta legítima.
$boundarySummary = new AffiliateIntegrations(static function (string $method, string $url): array {
    if (str_contains($url, '/oauth/token')) {
        return ['status' => 200, 'body' => '{"access_token":"synthetic-token","expires_in":300}'];
    }
    return ['status' => 200, 'body' => json_encode([
        'items' => array_fill(0, 10, ['total_items' => 1]),
    ], JSON_THROW_ON_ERROR)];
});
checkAffiliate(count($boundarySummary->hotmartSalesSummary(10)['items']) === 10, 'aceita limite exato do sumário');

// Credenciais OAuth da Hotmart são enviadas na URL: rejeitar controles e excesso
// antes de invocar o transporte, sem ecoar segredos nas exceções.
$invalidHotmartCredentials = [
    ['HOTMART_CLIENT_ID', "client\r\nX-Injected: yes"],
    ['HOTMART_CLIENT_SECRET', "secret\twith-tab"],
    ['HOTMART_CLIENT_ID', str_repeat('i', 4097)],
    ['HOTMART_CLIENT_SECRET', str_repeat('s', 4097)],
];
foreach ($invalidHotmartCredentials as [$key, $value]) {
    putenv($key . '=' . $value);
    $calls = 0;
    try {
        (new AffiliateIntegrations(static function () use (&$calls): array {
            $calls++;
            return ['status' => 200, 'body' => '{"access_token":"unexpected"}'];
        }))->hotmartSalesSummary();
        throw new RuntimeException('Aceitou credencial OAuth Hotmart insegura.');
    } catch (RuntimeException $e) {
        checkAffiliate($e->getMessage() === 'Credenciais OAuth Hotmart inválidas.', 'erro seguro para ' . $key);
    }
    checkAffiliate($calls === 0, 'credencial insegura não enviada: ' . $key);
    putenv($key . '=' . ($key === 'HOTMART_CLIENT_ID' ? 'client-test' : 'secret-test'));
}
// Separadores visíveis legítimos devem continuar codificados em RFC3986.
putenv('HOTMART_CLIENT_SECRET=abc+&=xyz');
$oauthUrl = null;
$validCredentialClient = new AffiliateIntegrations(static function (string $method, string $url) use (&$oauthUrl): array {
    if (str_contains($url, '/oauth/token')) {
        $oauthUrl = $url;
        return ['status' => 200, 'body' => '{"access_token":"safe-token","expires_in":300}'];
    }
    return ['status' => 200, 'body' => '{"items":[]}'];
});
checkAffiliate($validCredentialClient->hotmartSalesSummary(1)['items'] === [], 'credencial codificada permite consulta');
checkAffiliate(is_string($oauthUrl) && str_contains($oauthUrl, 'client_secret=abc%2B%26%3Dxyz'), 'segredo codificado com RFC3986');
putenv('HOTMART_CLIENT_SECRET=secret-test');

echo "Integração Hotmart: testes com transporte simulado aprovados.\n";
