<?php
declare(strict_types=1);

/**
 * Prévia local, não autenticada e não persistente de notificações Monetizze.
 * Não é um endpoint HTTP e NÃO autoriza processamento financeiro.
 *
 * O id identifica um disparo de webhook; chave_unica identifica a conta.
 * Um evento com chave_unica correspondente NÃO comprova sua autenticidade.
 */
final class MonetizzeWebhookReview
{
    /**
     * @return array{received:int,unique:int,duplicates:int,other_account:int,positive:int,risk:int,needs_review:int,financial_ready:bool,authenticated:bool}
     */
    public static function preview(array $events, string $expectedAccountKey): array
    {
        if (!array_is_list($events) || count($events) < 1 || count($events) > 50) {
            throw new InvalidArgumentException('Lote Monetizze inválido.');
        }
        if (!preg_match('/\A[a-fA-F0-9]{32}\z/D', $expectedAccountKey)) {
            throw new InvalidArgumentException('Chave de conta Monetizze inválida.');
        }

        $result = [
            'received' => count($events), 'unique' => 0, 'duplicates' => 0,
            'other_account' => 0, 'positive' => 0, 'risk' => 0,
            'needs_review' => 0, 'financial_ready' => false, 'authenticated' => false,
        ];
        $seen = [];
        foreach ($events as $event) {
            if (!is_array($event) || array_is_list($event)) {
                throw new InvalidArgumentException('Evento Monetizze inválido.');
            }
            $id = $event['id'] ?? null;
            $accountKey = $event['chave_unica'] ?? null;
            $status = $event['codigo_status'] ?? null;
            $eventCode = $event['postback_evento'] ?? null;
            if (!is_string($id) || !preg_match('/\A[a-fA-F0-9]{64}\z/D', $id)
                || !is_string($accountKey) || !preg_match('/\A[a-fA-F0-9]{32}\z/D', $accountKey)
                || (!is_string($status) && !is_int($status))
                || !preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/D', (string) $status)
                || (!is_string($eventCode) && !is_int($eventCode))
                || !preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/D', (string) $eventCode)) {
                throw new InvalidArgumentException('Campos mínimos do evento Monetizze inválidos.');
            }
            if (!hash_equals(strtolower($expectedAccountKey), strtolower($accountKey))) {
                $result['other_account']++;
                continue;
            }
            $id = strtolower($id);
            // The same delivery ID with different codes is ambiguous: fail closed
            // instead of silently dropping a potentially important risk event.
            $codes = [(string) $status, (string) $eventCode];
            if (isset($seen[$id])) {
                if ($seen[$id] !== $codes) {
                    throw new InvalidArgumentException('Disparo Monetizze duplicado com códigos divergentes.');
                }
                $result['duplicates']++;
                continue;
            }
            $seen[$id] = $codes;
            // Status da venda e tipo do webhook são conceitos diferentes.
            // 9 = solicitação de reembolso no exemplo oficial, ainda que status = 2.
            if ((string) $eventCode === '9' || in_array((string) $status, ['3', '4', '5'], true)) {
                $result['risk']++;
            } elseif ((string) $eventCode === '2' && in_array((string) $status, ['2', '6'], true)) {
                $result['positive']++;
            } else {
                $result['needs_review']++;
            }
        }
        $result['unique'] = count($seen);
        return $result;
    }
}
