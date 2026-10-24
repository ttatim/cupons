<?php
declare(strict_types=1);

/**
 * Paginação explícita para consultas manuais da API de afiliados.
 * Rejeita arrays, floats, valores negativos e paginações excessivas.
 */
final class AffiliateImportPage
{
    public static function parse(mixed $input): int
    {
        if ($input === null) {
            return 1;
        }
        if (!is_string($input) && !is_int($input)) {
            throw new InvalidArgumentException('Página inválida.');
        }
        $value = (string) $input;
        if (!preg_match('/\\A[1-9][0-9]{0,3}\\z/D', $value)) {
            throw new InvalidArgumentException('Página inválida: informe um número entre 1 e 1000.');
        }
        $page = (int) $value;
        if ($page > 1000) {
            throw new InvalidArgumentException('Página inválida: máximo 1000.');
        }
        return $page;
    }
}
