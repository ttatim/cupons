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


## Preparação de transporte HTTP para relay interno (25/10/2026)

- Nova classe `includes/monetizze_relay_http_guard.php`, **sem rota HTTP**. É apenas um adaptador puro para validar metadados de uma futura requisição privada antes de delegar à assinatura HMAC-SHA256 do relay interno existente.
- A chamada `MonetizzeRelayHttpGuard::verifyAndPreview($server, $rawBody, $relaySecret, $accountKey, $claimNonce, $upstreamVerified, $enabled)` começa **bloqueada por padrão**: `$enabled=false` e `$upstreamVerified=false`. Os dois parâmetros são afirmações de configuração de servidor confiável, **nunca** valores de cabeçalhos, query string ou JSON recebidos do cliente.
- Requer POST, `application/json` com charset UTF-8 opcional, até 64 KiB, Content-Length canônico e consistente (quando presente), corpo sem compressão/Transfer-Encoding e cabeçalhos `X-Monetizze-Relay-Timestamp`, `X-Monetizze-Relay-Nonce` e `X-Monetizze-Relay-Signature`. Validação criptográfica, janela de tempo e proteção contra repetição continuam delegadas ao verificador do relay e ao callback de nonce **atômico e persistente**.
- A flag `$upstreamVerified` **não comprova a origem** por si só: só poderá ser fornecida como verdadeira depois de verificar independentemente, em uma integração homologada, que o relay de confiança autenticou a Monetizze upstream. Sem esse mecanismo, manter o recurso desabilitado.
- A resposta mantém `origin_verified=false`, `authenticated=false`, `financial_ready=false`, mesmo quando o HMAC do relay é válido. Não há conciliação, atualização de saldo, receita, pagamento, receptor público, migração SQL ou novas credenciais nesta entrega.
- Testes sintéticos: `php tests/test_monetizze_relay_http_guard.php` (PHP 8.3/8.4 no CI), incluindo flags desligadas, método/tipo/limites/codificação, adulteração, nonce repetido e ausência de PII. O callback em memória dos testes **não** deve ser usado em produção.
- **Pendências para um endpoint real:** confirmação e homologação do mecanismo de autenticação upstream da Monetizze, TLS de ponta a ponta, segredo seguro fora do repositório, nonce MySQL compartilhado, monitoramento, proteção contra abuso e testes HTTP E2E. Não expor este adaptador como rota pública.

## Validação integrada do guard HTTP com MySQL 8 (25/10/2026)

- O teste `tests/test_monetizze_relay_http_mysql_chain.php` encadeia o guard de transporte, HMAC do relay e reserva atômica de nonce em **duas conexões PDO independentes**, em banco descartável `cupons_test`. Complementa os testes unitários anteriores, que usavam callback de nonce em memória.
- Verifica que flags desligadas, `Content-Length` adulterado, assinatura inválida e JSON assinado com chaves duplicadas falham **antes** de consumir nonce; a primeira mensagem sintética válida grava um nonce e o replay na segunda conexão é rejeitado.
- Confirma que o resultado mantém `origin_verified=false`, `authenticated=false` e `financial_ready=false`, sem retornar informações do comprador. O teste cria e remove **somente os próprios nonces aleatórios**, sem apagar registros preexistentes ou criar usuários.
- O CI em PHP 8.3 e 8.4 com MySQL 8 executa obrigatoriamente o teste, falhando se `TEST_DB_DSN` estiver ausente em GitHub Actions. Fora do CI, sem DSN, informa explicitamente que a integração **não foi executada**.
- Execução isolada: configure `TEST_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=cupons_test;charset=utf8mb4'`, `TEST_DB_USER`, `TEST_DB_PASSWORD` para banco **descartável**, e rode `php tests/test_monetizze_relay_http_mysql_chain.php`. Nunca aponte para banco de produção.
- **Limite:** é uma validação da cadeia de funções PHP e persistência, não um teste HTTP E2E por socket/navegador, nem prova de autenticação da Monetizze. Nenhuma rota pública foi criada; integração real e conciliação financeira continuam bloqueadas até homologação upstream e isolamento por vendedor.

## Teste HTTP ponta a ponta em loopback (25/10/2026)

- Novo `tests/test_monetizze_relay_http_e2e.php` executa um servidor PHP embutido ligado **exclusivamente a 127.0.0.1**, com roteador sintético `tests/fixtures/monetizze_http_router.php`. **Nenhuma rota HTTP de produção foi criada.**
- O roteador de testes responde somente no SAPI `cli-server`, com `APP_ENV=testing`, `TEST_RELAY_HTTP_E2E=1`, origem local e caminho exato `/relay-test`; os demais contextos retornam 404. Não implantar `tests/` como conteúdo público.
- Testa POST HTTP real, HMAC do relay, validação de tipo de conteúdo, rejeição de GET e de JSON com chaves duplicadas, bloqueio por padrão, bloqueio sem comprovação upstream, gravação de nonce no MySQL 8 e rejeição de replay em requisição HTTP posterior. As respostas nunca incluem dados do comprador nem o segredo sintético.
- Executar **somente** com `TEST_DB_DSN` explicitamente apontando para `dbname=cupons_test`, `TEST_DB_USER` e `TEST_DB_PASSWORD`; exemplo: `php tests/test_monetizze_relay_http_e2e.php`. O teste cria a tabela de nonce quando necessário e remove somente os nonces aleatórios criados por ele. Não executa limpeza geral nem altera contas reais.
- CI: workflow `affiliate-staging.yml`, matriz PHP 8.3/8.4 com MySQL 8; falha se `TEST_DB_DSN` estiver ausente no GitHub Actions. Requer extensões `curl`, `pdo_mysql` e permissão de `proc_open` no runner de testes (não é requisito de produção).
- O parâmetro `upstream=true` é usado **somente dentro do teste sintético**, para exercitar a cadeia de validação. Ele **não comprova a origem Monetizze**. Nenhuma operação de conciliação, pagamento ou reconhecimento de receita é permitida. Continua bloqueada a homologação upstream com credenciais autorizadas, e ainda falta validação de navegador da interface administrativa.

## Teste HTTP do console administrativo (25/10/2026)

- `tests/test_monetizze_admin_http_e2e.php` valida o fluxo **HTTP real** de login, sessão, autorização por papel, fila, CSRF, decisão, histórico, idempotência e isolamento entre duas contas sintéticas. Usa servidor PHP embutido **somente em 127.0.0.1** e MySQL 8 descartável; não expõe uma nova rota.
- O teste cria nomes, senhas, chaves e candidatos aleatórios exclusivamente no banco `cupons_test`. Ao terminar, remove apenas os registros sintéticos de sua execução, na ordem decisões → candidatos → usuários. Não executa `TRUNCATE`, limpeza geral, seed de produção ou alteração em ambientes reais.
- Execução: configure `TEST_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=cupons_test;charset=utf8mb4'`, `TEST_DB_USER` e `TEST_DB_PASSWORD` para um banco **descartável**, e execute `php tests/test_monetizze_admin_http_e2e.php`. Sem DSN, o teste é explicitamente ignorado fora do CI e falha dentro do GitHub Actions.
- O workflow `affiliate-staging.yml` executa o teste com PHP 8.3 e 8.4 + MySQL 8, além do lint. Exige extensões `curl` e `pdo_mysql` e `proc_open` apenas no executor de testes.
- A validação cobre HTTP/HTML/cookies/CSRF via cURL, **não é automação de navegador visual**. Não comprova autenticação upstream da Monetizze, não habilita recebimento público, não reconcilia comissões e não concede permissão financeira.
