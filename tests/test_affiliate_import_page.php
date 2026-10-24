<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/affiliate_import_page.php';

function assertPage(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('Falhou: ' . $label);
    }
}
assertPage(AffiliateImportPage::parse(null) === 1, 'página padrão');
assertPage(AffiliateImportPage::parse('1') === 1, 'primeira página');
assertPage(AffiliateImportPage::parse(1000) === 1000, 'última página');
assertPage(AffiliateImportPage::parse('245') === 245, 'página intermediária');

foreach (['0', '-1', '1001', '9999', '1.5', '1e2', ' 1', '1 ', '', '01', '1 OR 1=1', [], ['2'], true, 2.5] as $invalid) {
    try {
        AffiliateImportPage::parse($invalid);
    } catch (InvalidArgumentException $expected) {
        continue;
    }
    throw new RuntimeException('Aceitou paginação inválida: ' . get_debug_type($invalid));
}
echo "Paginação Kiwify: validação de entradas aprovada.\n";
