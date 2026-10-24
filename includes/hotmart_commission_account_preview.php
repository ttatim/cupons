<?php
declare(strict_types=1);
require_once __DIR__ . '/hotmart_commission_preview.php';

/**
 * Filtra comissões por UCODE de participante informado pelo operador.
 * A coincidência NÃO comprova titularidade da conta, liquidação ou saldo.
 * Nenhum UCODE, nome, comprador ou identificador de transação é retornado.
 */
final class HotmartCommissionAccountPreview
{
    public static function summarize(array $items, string $configuredUcode): array
    {
        if (!self::validUcode($configuredUcode)) {
            throw new InvalidArgumentException('HOTMART_ACCOUNT_UCODE inválido: informe um UUID Hotmart.');
        }
        if (!array_is_list($items) || count($items) > 50) {
            throw new InvalidArgumentException('Página de comissões inválida.');
        }
        $expected = strtolower($configuredUcode);
        $matched = 0;
        $notMatched = 0;
        $withoutUcode = 0;
        $matchedTransactions = 0;
        $filtered = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_array($item['commissions'] ?? null)
                || !array_is_list($item['commissions']) || count($item['commissions']) > 100) {
                throw new InvalidArgumentException('Transação com lista de comissões inválida.');
            }
            $entries = [];
            foreach ($item['commissions'] as $entry) {
                if (!is_array($entry)) {
                    throw new InvalidArgumentException('Comissão inválida.');
                }
                $ucode = is_array($entry['user'] ?? null) ? ($entry['user']['ucode'] ?? null) : null;
                if (!is_string($ucode) || !self::validUcode($ucode)) {
                    $withoutUcode++;
                    continue;
                }
                if (!hash_equals($expected, strtolower($ucode))) {
                    $notMatched++;
                    continue;
                }
                $entries[] = $entry;
                $matched++;
            }
            if ($entries !== []) {
                $matchedTransactions++;
            }
            $filtered[] = ['commissions' => $entries];
        }
        $summary = HotmartCommissionPreview::summarize($filtered);
        return [
            'transactions_in_page' => count($items),
            'transactions_with_match' => $matchedTransactions,
            'matched_commissions' => $matched,
            'other_participants' => $notMatched,
            'without_valid_ucode' => $withoutUcode,
            'totals' => $summary['totals'],
        ];
    }

    private static function validUcode(string $ucode): bool
    {
        return (bool) preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/iD', $ucode);
    }
}
