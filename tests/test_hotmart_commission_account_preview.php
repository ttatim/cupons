<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/hotmart_commission_account_preview.php';

function checkAccount(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Falhou: ' . $message);
    }
}
$owner = 'A1B2C3D4-1234-5678-9ABC-ABCDEF012345';
$other = 'b1b2c3d4-1234-5678-9abc-abcdef012345';
$items = [
    ['transaction' => 'SECRET-TX', 'buyer' => ['email' => 'hidden@example.invalid'], 'commissions' => [
        ['user' => ['ucode' => strtolower($owner), 'name' => 'SECRET PERSON'], 'source' => 'AFFILIATE', 'commission' => ['currency_value' => 'BRL', 'value' => '19.95']],
        ['user' => ['ucode' => $other], 'source' => 'PRODUCER', 'commission' => ['currency_value' => 'BRL', 'value' => '80.05']],
        ['user' => ['name' => 'UNKNOWN'], 'source' => 'AFFILIATE', 'commission' => ['currency_value' => 'BRL', 'value' => '100.00']],
    ]],
    ['commissions' => [
        ['user' => ['ucode' => $owner], 'source' => 'AFFILIATE', 'commission' => ['currency_value' => 'BRL', 'value' => '0.05']],
        ['user' => ['ucode' => $other], 'source' => 'AFFILIATE', 'commission' => ['currency_value' => 'USD', 'value' => '200.00']],
    ]],
];
$summary = HotmartCommissionAccountPreview::summarize($items, $owner);
checkAccount($summary['transactions_in_page'] === 2 && $summary['transactions_with_match'] === 2, 'transações');
checkAccount($summary['matched_commissions'] === 2 && $summary['other_participants'] === 2 && $summary['without_valid_ucode'] === 1, 'classificação');
checkAccount(count($summary['totals']) === 1 && $summary['totals'][0]['amount'] === '20.00', 'somente valores do UCODE');
$serialized = json_encode($summary, JSON_THROW_ON_ERROR);
foreach ([$owner, strtolower($owner), $other, 'SECRET', 'hidden@example.invalid', '200.00'] as $private) {
    checkAccount(!str_contains($serialized, $private), 'sem dados pessoais ou valores de terceiros: ' . $private);
}
foreach (['', 'not-an-uuid', $owner . "\r\n", str_repeat('a', 1025)] as $bad) {
    try {
        HotmartCommissionAccountPreview::summarize($items, $bad);
        throw new RuntimeException('UCODE inválido aceito');
    } catch (InvalidArgumentException $expected) {}
}
try {
    HotmartCommissionAccountPreview::summarize([['commissions' => 'invalid']], $owner);
    throw new RuntimeException('Payload inválido aceito');
} catch (InvalidArgumentException $expected) {}
try {
    HotmartCommissionAccountPreview::summarize([['commissions' => [[
        'user' => ['ucode' => $owner], 'source' => 'AFFILIATE',
        'commission' => ['currency_value' => 'BRL', 'value' => '1.999'],
    ]]]], $owner);
    throw new RuntimeException('Comissão inválida atribuída');
} catch (InvalidArgumentException $expected) {}
$empty = HotmartCommissionAccountPreview::summarize([['commissions' => []]], $owner);
checkAccount($empty['matched_commissions'] === 0 && $empty['totals'] === [], 'página sem coincidências');
echo "Hotmart: filtragem por UCODE configurado, privacidade e validações aprovadas.\n";
