<?php
declare(strict_types=1);
require_once __DIR__ . '/hotmart_commission_staging.php';

/**
 * Paginação MANUAL das observações Hotmart, restrita a uma conta e status.
 * O cursor da API existe apenas na sessão autenticada, nunca no HTML.
 * Nenhuma varredura automática, saldo ou conciliação financeira é realizada.
 */
final class HotmartCommissionStagePagination
{
    private const SESSION_KEY = 'hotmart_commission_stage_page';
    private const MAX_PAGES = 50;

    private static function scope(string $ucode, string $key): string
    {
        // Reutiliza exatamente a validação e o escopo HMAC da persistência.
        return HotmartCommissionStaging::normalize([], $ucode, $key, 'APPROVED')['account_hash'];
    }

    private static function validCursor(mixed $cursor): bool
    {
        return is_string($cursor)
            && (bool) preg_match('/\\A[A-Za-z0-9+\\/=_-]{1,1024}\\z/D', $cursor);
    }

    /**
     * Recupera o status e cursor da próxima página SOMENTE da sessão.
     * @return array{status:string,cursor:string,page:int}
     */
    public static function nextRequest(array $session, string $ucode, string $key): array
    {
        $state = $session[self::SESSION_KEY] ?? null;
        if (!is_array($state)
            || !is_string($state['scope'] ?? null)
            || !hash_equals(self::scope($ucode, $key), $state['scope'])
            || !is_string($state['status'] ?? null)
            || !HotmartCommissionStaging::validStatus($state['status'])
            || !is_int($state['page'] ?? null)
            || $state['page'] < 1 || $state['page'] >= self::MAX_PAGES
            || !self::validCursor($state['next_token'] ?? null)) {
            throw new RuntimeException('Não há próxima página válida para esta conta e status. Inicie nova consulta.');
        }
        return [
            'status' => $state['status'],
            'cursor' => $state['next_token'],
            'page' => $state['page'] + 1,
        ];
    }

    /**
     * Chamar SOMENTE após a persistência bem-sucedida da página.
     * @return array{page:int,status:string,has_next:bool}
     */
    public static function record(
        array &$session,
        string $ucode,
        string $key,
        string $status,
        ?string $nextToken,
        bool $continuation = false,
        ?string $usedCursor = null
    ): array {
        $scope = self::scope($ucode, $key);
        if (!HotmartCommissionStaging::validStatus($status)) {
            throw new InvalidArgumentException('Status Hotmart inválido.');
        }
        if ($nextToken !== null && !self::validCursor($nextToken)) {
            throw new InvalidArgumentException('Cursor retornado pela Hotmart inválido.');
        }

        $page = 1;
        $seen = [];
        if ($continuation) {
            $request = self::nextRequest($session, $ucode, $key);
            if ($request['status'] !== $status || !is_string($usedCursor)
                || !hash_equals($request['cursor'], $usedCursor)) {
                throw new RuntimeException('O status ou cursor da sessão foi alterado.');
            }
            $page = $request['page'];
            $previous = $session[self::SESSION_KEY];
            $seen = is_array($previous['seen_hashes'] ?? null) ? $previous['seen_hashes'] : [];
            $seen[] = hash('sha256', $usedCursor);
        } elseif ($usedCursor !== null) {
            throw new InvalidArgumentException('Primeira página não aceita cursor.');
        }

        // Não entrar em ciclos de cursores, mesmo que a API retorne páginas repetidas.
        if ($page >= self::MAX_PAGES || ($nextToken !== null
            && in_array(hash('sha256', $nextToken), $seen, true))) {
            $nextToken = null;
        }
        $session[self::SESSION_KEY] = [
            'scope' => $scope,
            'status' => $status,
            'page' => $page,
            'next_token' => $nextToken,
            'seen_hashes' => $seen,
        ];
        return ['page' => $page, 'status' => $status, 'has_next' => $nextToken !== null];
    }

    /** Retorna somente metadados não sensíveis para a interface. */
    public static function progress(array $session, string $ucode, string $key): ?array
    {
        $state = $session[self::SESSION_KEY] ?? null;
        if (!is_array($state) || !is_string($state['scope'] ?? null)
            || !hash_equals(self::scope($ucode, $key), $state['scope'])
            || !is_string($state['status'] ?? null)
            || !HotmartCommissionStaging::validStatus($state['status'])
            || !is_int($state['page'] ?? null) || $state['page'] < 1
            || $state['page'] > self::MAX_PAGES) {
            return null;
        }
        return [
            'page' => $state['page'],
            'status' => $state['status'],
            'has_next' => $state['page'] < self::MAX_PAGES
                && self::validCursor($state['next_token'] ?? null),
        ];
    }
}
