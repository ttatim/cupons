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

- A classe `includes/monetizze_signed_relay_review.php` valida um **envelope HMAC-SHA256 produzido por um relay de confiança controlado pelo operador**. **Não é uma assinatura nativa da Monetizze**. O relay precisa autenticar a origem upstream de forma independente antes de assinar os bytes recebidos.
- Protocolo interno v1: `hex(HMAC_SHA256(secret, "monetizze-relay-v1\n" + timestamp + "\n" + nonce + "\n" + rawJson))`. `timestamp` é Unix epoch decimal em segundos, `nonce` tem 32 caracteres hexadecimais minúsculos aleatórios, `signature` tem 64 caracteres hexadecimais minúsculos. Segredo aleatório de 32 a 512 bytes, mantido **fora do repositório**. Não existe variável de ambiente operacional nova porque nenhum endpoint ou relay foi habilitado.
- Rejeita assinatura incorreta, timestamp fora de ±300 segundos, nonce/assinatura malformados, segredo inadequado, JSON inválido, corpo acima de 64 KiB e lotes fora das regras da prévia. Assina **bytes exatos**, não JSON recodificado. Comparação da assinatura com `hash_equals`.
- Recebe callback obrigatório `Closure(string $nonce, int $expiresAt): bool` que deve implementar **reserva atômica e persistente de nonce**, retornando `false` para repetição, inclusive entre workers e reinícios. Sem essa implementação durável não existe proteção operacional contra replay. Falha do callback bloqueia o processamento. O teste utiliza armazenamento **somente em memória** para simular a interface.
- A saída contém apenas contagens, `relay_authenticated=true` (assinatura do relay), `authenticated=false` (origem Monetizze **não** comprovada) e `financial_ready=false`. Não registra compradores, transações, valores, credenciais ou eventos.
- **Sem endpoint HTTP**, sem webhook público, sem migração, sem persistência de eventos, sem conciliação, pagamentos ou reconhecimento de receita. Não implantar como webhook de produção sem autenticação upstream, armazenamento durável de nonce, homologação e isolamento por vendedor.
- Testar com `php tests/test_monetizze_signed_relay_review.php`; CI em PHP 8.3 e 8.4. Casos: assinatura correta, adulteração, expiração, futuro, replay, JSON malformado, limites e falha de armazenamento.


## Incremento: reserva atômica de nonce em MySQL (24/10/2026)

- A classe `includes/monetizze_relay_nonce_store.php` fornece `MonetizzeRelayNonceStore::callback(PDO $pdo)` compatível com o callback exigido pelo verificador de relay. Usa `INSERT` preparado e chave primária única em InnoDB; apenas erro de chave duplicada (MySQL 1062) é tratado como replay. Demais falhas de banco bloqueiam a operação.
- **Migração:** após `sql/database.sql`, aplicar uma única vez `sql/updates/20261024_monetizze_relay_nonces.sql` em banco autorizado. Cria tabela vazia `monetizze_relay_nonces`, sem tocar em tabelas ou dados existentes. Fazer backup antes de migrar ambiente em uso.
- Cada claim grava apenas nonce aleatório hexadecimal de 32 caracteres, expiração Unix e data de criação; não grava corpo, comprador, chave, identificador da conta, evento, venda, valor ou segredo. Todos os workers devem compartilhar o **mesmo MySQL**. Não há fallback para memória.
- Nonces não são excluídos automaticamente: política de retenção e rotina de limpeza dependem de aprovação do proprietário. O verificador HMAC também recusa timestamps fora da janela de 300 segundos. Monitorar crescimento da tabela antes de qualquer implantação.
- **Integração futura (não exposta por HTTP):** passar `MonetizzeRelayNonceStore::callback($pdo)` como sétimo argumento de `MonetizzeSignedRelayReview::verifyAndPreview(...)`. A reserva é feita somente após validar assinatura, JSON e lote; repetição entre processos é rejeitada pela chave única.
- **Testes sintéticos:** `php tests/test_monetizze_relay_nonce_store.php`. Com `TEST_DB_DSN` contendo exatamente `dbname=cupons_test`, `TEST_DB_USER` e `TEST_DB_PASSWORD`, testa replay entre duas conexões, rejeição de assinatura adulterada, minimização e limpeza somente dos dois nonces aleatórios criados no banco descartável. Sem MySQL, o teste informa explicitamente que a integração não foi executada. CI em PHP 8.3/8.4 com MySQL 8.
- **Limites:** ainda não há receptor HTTP, autenticação da origem Monetizze, armazenamento de eventos, segregação por vendedor, conciliação, pagamentos ou uso financeiro. `relay_authenticated=true` **não** comprova autenticidade da Monetizze. O requisito 3 continua parcial.


## Incremento: observações idempotentes de eventos do relay (24/10/2026)

- `includes/monetizze_relay_event_staging.php` fornece `MonetizzeRelayEventStaging::verifyAndStage(PDO, rawBody, timestamp, nonce, signature, relaySecret, expectedAccountKey, hashKey, now)`. **Não há endpoint HTTP nem integração automática com a Monetizze.**
- A função exige envelope HMAC válido do **relay interno** e executa **na mesma transação MySQL** a reserva do nonce e a gravação das observações. Se a gravação falhar, desfaz ambas. Um nonce repetido falha sem gravar observações.
- A nova tabela `monetizze_event_observations` armazena somente HMAC-SHA256 da conta e do ID de disparo (domínios separados), status/evento numéricos, flag de revisão e data local de observação. **Não grava** corpo, e-mail, CPF, comprador, chave de conta em claro, ID de disparo em claro, valor ou token.
- A chave `hashKey` deve ser aleatória, estável e mantida fora do repositório e do banco, com pelo menos 32 bytes. **Não reutilize o segredo do relay.** A função recebe a chave por argumento, sem ativar nova configuração de produção.
- A chave primária `(account_hash,event_hash)` impede duplicação entre workers. A primeira observação é imutável: reenvio com o mesmo ID, mas outro status, **não substitui** a evidência anterior; o operador precisa investigar divergências.
- A classificação de risco é **provisória**, derivada de códigos exemplificativos. `relay_authenticated=true` **não comprova origem Monetizze**. `authenticated=false` e `financial_ready=false` permanecem obrigatórios. Não há pagamentos, conciliação, saldo ou reconhecimento de receita.
- **Migração:** executar `sql/updates/20261024_monetizze_event_observations.sql` uma vez, **depois** de `sql/database.sql` e `sql/updates/20261024_monetizze_relay_nonces.sql`. Cria apenas tabela vazia. Faça backup antes de qualquer migração em ambiente existente.
- **Testes sintéticos:** `php -l includes/monetizze_relay_event_staging.php` e `php tests/test_monetizze_relay_event_staging.php`. Com `TEST_DB_DSN` contendo `dbname=cupons_test`, `TEST_DB_USER` e `TEST_DB_PASSWORD`, testa MySQL real; sem DSN informa que a integração foi ignorada. CI usa PHP 8.3/8.4 e MySQL 8.
- **Bloqueios:** autenticação upstream real, validação de códigos/formatos, política de retenção, isolamento por vendedor (requisito 10) e homologação com credenciais autorizadas. Não implantar para receber tráfego real antes dessas etapas.


## Incremento: bloqueio de observações divergentes (24/10/2026)

- A prévia `MonetizzeWebhookReview::preview` rejeita IDs de disparo repetidos **no mesmo lote** quando `codigo_status` ou `postback_evento` diferem. Duplicatas idênticas continuam sendo contadas, sem processamento duplicado.
- Os códigos numéricos devem ter formato decimal canônico, sem zeros à esquerda (`2`, `9`, `0`; não `02` nem `09`). Isso evita classificação inconsistente entre a prévia e a persistência. A aceitação de formatos alternativos depende de homologação com a Monetizze.
- A persistência `MonetizzeRelayEventStaging` verifica o registro existente em caso de chave duplicada: códigos idênticos são idempotentes; códigos divergentes geram erro controlado e **rollback de todo o lote e da reserva de nonce**. O registro original é preservado; nenhuma observação é sobrescrita.
- Esse bloqueio **não é um histórico completo de alterações de status**. É necessário desenhar a trilha de mudanças legítimas e a revisão humana antes de habilitar conciliação financeira. A falha não contém ID de evento, comprador, conta, valor ou payload.
- Testes: `php tests/test_monetizze_webhook_review.php` e `php tests/test_monetizze_relay_event_staging.php` (este último exige `cupons_test` MySQL). O CI existente executa os dois testes; sem `TEST_DB_DSN`, a integração MySQL é explicitamente ignorada.
- **Instalação:** nenhuma migração ou dependência nova. Não existe receptor HTTP, autenticação nativa Monetizze, homologação de eventos reais ou autorização financeira. Não utilizar como webhook de produção.


## Incremento: prévia de divergências e riscos de transição (25/10/2026)

- A classe `includes/monetizze_status_transition_preview.php` implementa uma **comparação exclusivamente de leitura** entre um lote local de eventos candidatos e as primeiras observações persistidas em `monetizze_event_observations`.
- Reutiliza a validação estrita de lotes `MonetizzeWebhookReview::preview` e o HMAC-SHA256 com domínios separados para conta e ID de disparo. Exige MySQL e a mesma `hashKey` estável usada no staging; não aceita chaves curtas ou caracteres de controle.
- Retorna apenas contagens agregadas: novos, idênticos, divergentes, novos com risco, escaladas de risco, aparente redução de risco **não verificada**, outra conta e duplicatas idênticas. Não retorna IDs, nomes, comprador, valor, token, conta, evento individual ou payload.
- Um candidato aparentemente positivo **não elimina** um alerta de reembolso/estorno anterior. Divergências continuam bloqueadas no fluxo de persistência existente; esta prévia não altera essa regra, não faz `INSERT`, `UPDATE`, `DELETE`, transação ou alteração de saldos.
- **Não comprova** que os eventos são legítimos, que existe ordem cronológica, que uma mudança de status ocorreu na Monetizze ou que o operador é titular da conta. `authenticated=false` e `financial_ready=false` são invariantes. Não expor esta prévia como endpoint público ou como autorização financeira.
- **Testes sintéticos:** `php -l includes/monetizze_status_transition_preview.php` e `php tests/test_monetizze_status_transition_preview.php`. A integração exige `TEST_DB_DSN` apontando exclusivamente para `dbname=cupons_test`, `TEST_DB_USER` e `TEST_DB_PASSWORD`; sem DSN, informa que o teste MySQL não foi executado. CI `affiliate-staging.yml` testa em PHP 8.3 e 8.4 com MySQL 8.
- **Instalação:** nenhuma migração ou dependência adicional nesta subetapa; requer a migração `sql/updates/20261024_monetizze_event_observations.sql` já aplicada. Não há alteração de registros reais.
- **Próximos bloqueios:** autenticação nativa de origem Monetizze, semântica real dos eventos, comprovação de titularidade, fluxo de revisão humana e histórico cronológico append-only com autorização por vendedor. Sem essas etapas, não existe conciliação financeira.

## Incremento: diário append-only de divergências para revisão humana (25/10/2026)

- `includes/monetizze_review_candidate_journal.php` disponibiliza `MonetizzeReviewCandidateJournal::verifyAndRecord(...)` para uso **interno/offline**, sem rota HTTP, cron, integração automática, aprovação ou efeitos financeiros.
- A função valida a assinatura HMAC do **relay interno**, a janela de 300 segundos, os campos e o lote inteiro, e reserva o nonce na **mesma transação MySQL** da gravação. Assinatura de relay **não autentica a origem Monetizze**; a autenticação upstream e a titularidade continuam pendentes.
- Somente divergências de códigos frente à primeira observação em `monetizze_event_observations` geram candidatos. Eventos idênticos são ignorados; eventos sem observação original são classificados como `untracked`, **não** criam uma observação presumida. Duplicatas com códigos diferentes dentro do mesmo lote são recusadas.
- A tabela `monetizze_review_candidates` armazena apenas hashes HMAC-SHA256 de conta e disparo, códigos numéricos original/candidato, classificação preliminar de risco e data **local de primeiro registro**. Nunca armazena comprador, CPF, e-mail, URL, valor, token, payload ou identificador externo em claro.
- A chave única `(account_hash,event_hash,candidate_status,candidate_event)` evita duplicar um candidato em reenvios ou workers distintos. A primeira observação original **não é alterada**. O serviço não executa `UPDATE` nem `DELETE`; nenhuma rotina de limpeza está habilitada.
- `first_seen_at` e `id` são apenas **ordem de observação local**; não comprovam a cronologia dos eventos no fornecedor. Um candidato aparentemente positivo nunca cancela risco anterior.
- A saída contém somente contagens agregadas, `relay_authenticated=true`, `authenticated=false` e `financial_ready=false`. **Não** há interface de revisão humana nem mecanismo de aprovação; este diário apenas prepara a trilha de evidências para um fluxo futuro, com autorização por vendedor (item 10).
- **Migração incremental:** após `sql/database.sql`, `20261024_monetizze_relay_nonces.sql` e `20261024_monetizze_event_observations.sql`, aplicar **uma vez** `sql/updates/20261025_monetizze_review_candidates.sql`. A tabela é criada vazia; fazer backup antes de migrar bancos em uso. Não apagar dados existentes. Retenção e limpeza dependem de decisão formal.
- **Testes:** `php -l includes/monetizze_review_candidate_journal.php` e `php tests/test_monetizze_review_candidate_journal.php`. A integração real exige `TEST_DB_DSN` apontando exclusivamente para `dbname=cupons_test`, `TEST_DB_USER` e `TEST_DB_PASSWORD`. Sem DSN o teste declara que não executou MySQL. O CI executa PHP 8.3/8.4 com MySQL 8 e dados sintéticos.
- **Pendências:** comprovar origem/autenticidade Monetizze, semântica e ordem upstream, autorização por vendedor, interface de revisão com trilha de decisões humanas, retenção, homologação com credenciais autorizadas e conciliação financeira. Não usar em produção como webhook.


## Incremento: fila de revisão humana somente leitura (25/10/2026)

- `includes/monetizze_review_queue.php` disponibiliza `MonetizzeReviewQueue::listForAccount(PDO, accountKey, hashKey, afterId=0, limit=25)` para preparar uma **fila interna de revisão**, sem endpoint HTTP, painel público ou decisão/aprovação de eventos.
- A consulta exige MySQL com PDO em modo de exceção e as tabelas já criadas por `sql/updates/20261025_monetizze_review_candidates.sql`. **Nenhuma migração nova** nesta subetapa.
- O operador precisa estar **autenticado e autorizado a acessar a conta específica pelo sistema chamador**, antes de fornecer `accountKey` e `hashKey`. A função isola resultados pelo HMAC da conta, mas **não implementa login, autorização por vendedor ou prova de titularidade**. Não a exponha diretamente por HTTP.
- Paginação keyset por `id` local crescente, com `afterId >= 0`, `limit` entre 1 e 100, `has_more` e `next_after_id`. Não usa `OFFSET` nem interpola o limite na consulta SQL.
- Cada item contém apenas ID **local** de revisão, códigos numéricos originais/candidatos, direção de risco preliminar e data de **primeira observação local**. Não retorna hashes, IDs externos, conta, comprador, CPF, e-mail, valores, token ou payload.
- Não executa `INSERT`, `UPDATE`, `DELETE`, aprovações, ajustes de saldo, conciliação ou pagamentos. A saída mantém `authenticated=false`, `financial_ready=false` e `read_only=true`. Nenhum item da fila deve ser considerado fato financeiro validado.
- **Testes sintéticos:** `php -l includes/monetizze_review_queue.php` e `php tests/test_monetizze_review_queue.php`. A integração MySQL só roda com `TEST_DB_DSN` contendo `dbname=cupons_test`, `TEST_DB_USER` e `TEST_DB_PASSWORD`; sem isso o teste informa que foi ignorado. O CI testa PHP 8.3/8.4 com MySQL 8.
- **Próximas decisões:** estabelecer autorização por vendedor/conta, interface de revisão e auditoria de decisões humanas, validação de origem upstream e de cronologia, política de retenção e homologação autorizada. **Nenhuma aprovação financeira automática foi implementada.**


## Incremento: decisões de triagem humana append-only (25/10/2026)

- Novo serviço interno includes/monetizze_review_decision_journal.php para registrar ações humanas sobre candidatos NÃO verificados: request_evidence (solicitar comprovação), escalate (escalar investigação) e acknowledge_unverified (ciência, sem aprovação). Nenhuma ação significa aprovação financeira, baixa de risco ou conciliação.
- A função record(PDO, accountKey, hashKey, operatorRef, authorize, reviewId, requestId, action) exige callback Closure(string accountKey, string operatorRef): bool que autentica o operador e autoriza o acesso à conta específica. operatorRef deve vir EXCLUSIVAMENTE da sessão autenticada do servidor, nunca do cliente. Falha ou exceção no autorizador bloqueia tudo. Ainda não existe UI nem endpoint HTTP; autorização multi-vendedor (item 10) continua pendente.
- O serviço bloqueia candidatos de outras contas, usa SELECT FOR UPDATE, transação e chave única de solicitação. requestId deve ser gerado no servidor com 16 bytes aleatórios (32 hex); a mesma solicitação e ação é idempotente entre processos, mas reuso divergente é rejeitado.
- Migração incremental sql/updates/20261025_monetizze_review_decisions.sql: aplicar após 20261025_monetizze_review_candidates.sql. Cria apenas tabela vazia com chave única, FK e índice. Fazer backup antes de migrar banco em uso; não há DELETE, UPDATE ou rotina de retenção no serviço.
- Guarda HMAC-SHA256 separados por domínio para conta, operador e solicitação, usando hashKey estável do diário. Não guarda comprador, e-mail, CPF, IDs externos, payload, valores, token ou comentários livres. Rotação da chave exige migração controlada.
- historyForCandidate(...) exige a mesma autorização por conta, retorna somente ID local da decisão, ação e data LOCAL de registro; paginação por ID com limite 1 a 100. Não comprova cronologia upstream.
- Testes sintéticos: php -l includes/monetizze_review_decision_journal.php e php tests/test_monetizze_review_decision_journal.php. Integração MySQL requer TEST_DB_DSN com dbname=cupons_test, TEST_DB_USER e TEST_DB_PASSWORD; sem DSN declara que não executou integração. CI PHP 8.3/8.4 e MySQL 8.
- Bloqueios: origem Monetizze autenticada, homologação de eventos/cronologia, UI autenticada, autorização por vendedor, política de retenção e conciliação. Não utilizar o diário como comprovante de receita ou pagamento.
