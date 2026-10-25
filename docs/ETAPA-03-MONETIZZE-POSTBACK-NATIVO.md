# Etapa 03 — prévia de postbacks nativos Monetizze (25/10/2026)

## Escopo e finalidade

A classe `includes/monetizze_native_postback_preview.php` aceita **um** postback de vendas em formato `application/json` ou `application/x-www-form-urlencoded` (com `charset` opcional), conforme a documentação oficial da Monetizze: https://apidoc.monetizze.com.br/postback/index.html .

Esta é uma **prévia local de dados NÃO autenticados**, sem rota HTTP, sem armazenamento e sem integração financeira. Não usar diretamente em produção. A chave `chave_unica` identifica a conta/integração, mas **não prova a origem**; a documentação pública consultada não estabelece uma assinatura criptográfica nativa verificável para esse fluxo. Uma validação de formato, correspondência de conta ou assinatura de relay interno não comprova que a Monetizze enviou o evento.

## Contrato e limites

- Uso: `MonetizzeNativePostbackPreview::preview($rawBody, $contentType, $expectedAccountKey)`.
- Até 65.536 bytes por requisição e até 200 campos de primeiro nível em JSON ou formulário; rejeita codificação percentual inválida, duplicação de campos mínimos em formulários, **qualquer chave JSON de primeiro nível duplicada** (inclusive escapes Unicode equivalentes) e tipos MIME desconhecidos.
- Apenas eventos de **vendas** `1,2,3,4,5,6,7,8,9,98,99`; assinaturas, rastreio e ingressos exigem contrato separado. Exige `id`, `chave_unica`, `codigo_status` e `postback_evento` válidos.
- O campo `id` identifica o disparo; `chave_unica` identifica a conta. Não confundir os dois para idempotência.
- O processamento reduz o payload a quatro campos antes da classificação existente. Ignora e não retorna comprador, CPF, email, comissões, URLs de pagamento e quaisquer outros campos.
- A saída contém apenas contadores e **sempre** `origin_verified=false`, `authenticated=false`, `financial_ready=false`. Um evento de solicitação de reembolso (`9`) permanece risco mesmo quando `codigo_status=2`.
- Não permite conciliação, atualização de saldo, publicação, registro de receita ou pagamento. Nenhum segredo é necessário para testes sintéticos; nenhuma migração SQL adicional.

## Validação

```bash
php -l includes/monetizze_native_postback_preview.php
php -l tests/test_monetizze_native_postback_preview.php
php tests/test_monetizze_native_postback_preview.php
php tests/test_monetizze_native_postback_ambiguity.php
```

CI: PHP 8.3 e 8.4; teste isolado sem banco de dados. O parser de formulário **não** utiliza `parse_str` para evitar sobrescrita silenciosa dos quatro campos mínimos. Campos não essenciais são ignorados.

## Bloqueios e próximos passos

1. Confirmar com a Monetizze o mecanismo oficialmente suportado para verificar a **origem** do postback; homologar com credenciais autorizadas e ambiente de teste, sem presumir assinatura nativa.
2. Só após comprovação de origem, definir receptor HTTP protegido, trilha de auditoria, reprocessamento e integração com a persistência idempotente existente.
3. Validar semântica de códigos/status reais, retenção de evidências e isolamento por vendedor (requisito 10) antes de qualquer conciliação.
4. Testar a interface administrativa HTTP em ambiente isolado com MySQL 8 e navegador.

A documentação e o código não habilitam endpoint de recebimento externo.

## Endurecimento de ambiguidades JSON (25/10/2026)

- O decodificador JSON padrão do PHP aceita objetos com chaves duplicadas e mantém silenciosamente o último valor. Isso poderia alterar `id`, `chave_unica`, `codigo_status` ou `postback_evento` conforme a interpretação do intermediário.
- Agora a prévia valida as chaves de primeiro nível antes de utilizar o objeto decodificado. Escapes Unicode equivalentes são normalizados para detectar colisões; valores aninhados são percorridos sem interpretar nem armazenar dados pessoais.
- O mesmo teto de 200 campos vale para JSON e formulários. Entradas ambíguas falham sem qualquer gravação; não houve migração SQL.
- Testes sintéticos verificam duplicações, aliases Unicode, limites de campos, JSON aninhado e ausência de informações pessoais. CI executa o novo teste em PHP 8.3/8.4.
- **Bloqueio mantido:** essa correção não autentica a origem Monetizze. Não foi criado endpoint HTTP, e o sistema não deve aceitar postbacks externos para fins financeiros até homologação do mecanismo oficial de origem.
