# Etapa 03 — Preparação de revisão de eventos Monetizze

Data: 24/10/2026. Requisito 3/11, **parcial**.

## Entrega
- `includes/monetizze_webhook_review.php` é uma função de prévia **local**, sem endpoint HTTP, sem requisições externas e sem persistência.
- Recebe um lote em memória de 1 a 50 eventos, exige `id` de disparo e `chave_unica` da conta (ambos com formato hexadecimal esperado nos exemplos oficiais), além de `codigo_status` e `postback_evento`.
- Conta duplicatas pelo **id do disparo**, nunca pela chave da conta. Reenvios do mesmo evento no mesmo lote são deduplicados; **não existe idempotência entre execuções**, pois nenhum dado é gravado.
- Filtra a conta configurada com comparação constante (`hash_equals`). Correspondência da chave **não autentica o remetente**: os dados ainda são não confiáveis.
- A prévia devolve **somente contagens**: recebidos, únicos, repetidos, outra conta, positivos aparentes, risco e revisão. Descarta comprador, e-mail, CPF, valor, venda, código, hash e demais campos.
- Marca risco em `postback_evento=9` (solicitação de reembolso no exemplo oficial) ou `codigo_status` em 3/4/5. Marca status aparentemente positivo somente para `postback_evento=2` com status 2/6; demais combinações exigem revisão. Essa classificação é **preliminar** e depende de homologação com o fornecedor.
- Sempre retorna `authenticated=false` e `financial_ready=false`. Não há conciliação, saldo, lançamento, cobrança, pagamento ou publicação automática.

## Como validar sem credenciais
```bash
php -l includes/monetizze_webhook_review.php
php tests/test_monetizze_webhook_review.php
```
O CI de compatibilidade executa o teste em PHP 8.3 e 8.4. Não exige MySQL, cURL, API, credenciais ou dados reais. Os eventos de teste são totalmente sintéticos.

## Bloqueios para uso operacional
1. Homologar os formatos reais de identificadores e códigos da API/postback com documentação e conta autorizada. Não assumir que os exemplos cobrem todos os eventos.
2. Projetar **autenticação de origem** antes de expor qualquer rota HTTP. `chave_unica` é identificador de conta, não assinatura do webhook. Não aceitar dados externos como fatos financeiros apenas por corresponderem a essa chave.
3. Projetar armazenamento mínimo com idempotência persistente por `id`, retenção aprovada, rastreabilidade e isolamento por vendedor (requisito 10).
4. Confirmar ordem e semântica dos eventos de reembolso, devolução, bloqueio, cancelamento e chargeback; sem isso não há conciliação.
5. Testar com credenciais e ambiente autorizados sem inserir PII em logs ou repositório.

## Referências oficiais
- Webhooks Monetizze: https://apidoc.monetizze.com.br/postback/index.html
- API Monetizze: https://help.monetizze.com.br/books/integracoes/page/api-monetizze

**Instalação:** nenhuma migração SQL, biblioteca ou variável de ambiente nova nesta subetapa. O código não altera dados existentes nem habilita uma nova rota.
