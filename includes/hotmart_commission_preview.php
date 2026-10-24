<?php
declare(strict_types=1);

/**
 * Prévia agregada de comissões Hotmart em memória, sem nomes, IDs de
 * participantes, compradores, transações ou credenciais no resultado.
 * NÃO representa conciliação financeira nem saldo disponível.
 */
final class HotmartCommissionPreview
{
    /** @return array{transactions_in_page:int,commissions_in_page:int,totals:array} */
    public static function summarize(array $items): array
    {
        if (!array_is_list($items) || count($items) > 50) {
            throw new InvalidArgumentException('Página de comissões inválida.');
        }
        $totals = [];
        $commissionCount = 0;
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['commissions'])
                || !is_array($item['commissions']) || !array_is_list($item['commissions'])
                || count($item['commissions']) > 100) {
                throw new InvalidArgumentException('Transação com lista de comissões inválida.');
            }
            foreach ($item['commissions'] as $entry) {
                if (!is_array($entry) || !is_array($entry['commission'] ?? null)) {
                    throw new InvalidArgumentException('Comissão inválida.');
                }
                $source = $entry['source'] ?? null;
                $currency = $entry['commission']['currency_value'] ?? null;
                $amount = $entry['commission']['value'] ?? null;
                if (!is_string($source) || !preg_match('/\A[A-Z_]{2,32}\z/D', $source)
                    || !is_string($currency) || !preg_match('/\A[A-Z]{3}\z/D', $currency)) {
                    throw new InvalidArgumentException('Origem ou moeda da comissão inválida.');
                }
                // Não atribuir origens desconhecidas a uma categoria financeira conhecida.
                if (!in_array($source, ['PRODUCER', 'COPRODUCER', 'AFFILIATE', 'ADDON'], true)) {
                    $source = 'OUTROS';
                }
                $cents = self::toCents($amount);
                $key = $currency . ':' . $source;
                if (!isset($totals[$key])) {
                    $totals[$key] = ['currency' => $currency, 'source' => $source, 'cents' => 0, 'count' => 0];
                }
                $totals[$key]['cents'] += $cents;
                $totals[$key]['count']++;
                $commissionCount++;
            }
        }
        ksort($totals, SORT_STRING);
        $rows = [];
        foreach ($totals as $group) {
            $cents = $group['cents'];
            $absolute = abs($cents);
            $rows[] = [
                'currency' => $group['currency'],
                'source' => $group['source'],
                'amount' => ($cents < 0 ? '-' : '') . intdiv($absolute, 100) . '.' . str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT),
                'count' => $group['count'],
            ];
        }
        return [
            'transactions_in_page' => count($items),
            'commissions_in_page' => $commissionCount,
            'totals' => $rows,
        ];
    }

    private static function toCents(mixed $value): int
    {
        if ((!is_string($value) && !is_int($value) && !is_float($value))
            || (is_float($value) && !is_finite($value))) {
            throw new InvalidArgumentException('Valor de comissão inválido.');
        }
        $text = (string) $value;
        if (!preg_match('/\A(-?)([0-9]{1,12})(?:\.([0-9]{1,2}))?\z/D', $text, $matches)) {
            throw new InvalidArgumentException('Precisão ou formato de comissão inválido.');
        }
        $fraction = str_pad($matches[3] ?? '', 2, '0', STR_PAD_RIGHT);
        $cents = (int) $matches[2] * 100 + (int) $fraction;
        return $matches[1] === '-' ? -$cents : $cents;
    }
}
