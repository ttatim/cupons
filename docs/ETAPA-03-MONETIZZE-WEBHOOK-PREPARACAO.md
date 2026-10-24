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


## Incremento: verificação de relay interno assinado (24/10/2026)

- A classe \`includes/monetizze_signed_relay_review.php\` valida um **envelope HMAC-SHA256 produzido por um relay de confiança controlado pelo operador**. **Não é uma assinatura nativa da Monetizze**. O relay precisa autenticar a origem upstream de forma independente antes de assinar os bytes recebidos.
- Protocolo interno v1: \`hex(HMAC_SHA256(secret, "monetizze-relay-v1\n" + timestamp + "\n" + nonce + "\n" + rawJson))\`. \`timestamp\` é Unix epoch decimal em segundos, \`nonce\` tem 32 caracteres hexadecimais minúsculos aleatórios, \`signature\` tem 64 caracteres hexadecimais minúsculos. Segredo aleatório de 32 a 512 bytes, mantido **fora do repositório**. Não existe variável de ambiente operacional nova porque nenhum endpoint ou relay foi habilitado.
- Rejeita assinatura incorreta, timestamp fora de ±300 segundos, nonce/assinatura malformados, segredo inadequado, JSON inválido, corpo acima de 64 KiB e lotes fora das regras da prévia. Assina **bytes exatos**, não JSON recodificado. Comparação da assinatura com \`hash_equals\`.
- Recebe callback obrigatório \`Closure(string $nonce, int $expiresAt): bool\` que deve implementar **reserva atômica e persistente de nonce**, retornando \`false\` para repetição, inclusive entre workers e reinícios. Sem essa implementação durável não existe proteção operacional contra replay. Falha do callback bloqueia o processamento. O teste utiliza armazenamento **somente em memória** para simular a interface.
- A saída contém apenas contagens, \`relay_authenticated=true\` (assinatura do relay), \`authenticated=false\` (origem Monetizze **não** comprovada) e \`financial_ready=false\`. Não registra compradores, transações, valores, credenciais ou eventos.
- **Sem endpoint HTTP**, sem webhook público, sem migração, sem persistência de eventos, sem conciliação, pagamentos ou reconhecimento de receita. Não implantar como webhook de produção sem autenticação upstream, armazenamento durável de nonce, homologação e isolamento por vendedor.
- Testar com \`php tests/test_monetizze_signed_relay_review.php\`; CI em PHP 8.3 e 8.4. Casos: assinatura correta, adulteração, expiração, futuro, replay, JSON malformado, limites e falha de armazenamento.
