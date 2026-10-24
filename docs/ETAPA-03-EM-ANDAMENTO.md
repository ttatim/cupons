# Etapa 03 — Integrações de afiliados (em andamento)

## Entregue nesta subetapa (24/10/2026)
- Adaptador Hotmart com OAuth 2.0 `client_credentials` e consulta **somente leitura** ao sumário de vendas.
- Painel autenticado em `admin/integracoes-afiliados.php`, acessível pelo menu Integração > Afiliados.
- Indicação explícita de configuração versus conexão comprovada; Monetizze e Kiwify aparecem como **pendentes**, não conectadas.
- Transporte HTTPS com validação TLS e host fixo, sem redirects, timeout, limite de 1 MiB e mensagens de erro sem tokens ou payloads.
- Credenciais Hotmart lidas de variáveis de ambiente; nenhuma chave incluída no código, banco ou logs.
- Testes isolados com transporte simulado em `tests/test_affiliate_integrations.php`; adicionados ao CI PHP 8.3/8.4.
- Verificação TLS habilitada no cliente de APIs legado, que ainda requer revisão de autenticação e mapeamento de cada fornecedor.

## Complemento da etapa: Kiwify (24/10/2026)
- Cliente Kiwify somente leitura: OAuth via POST form-urlencoded em `/v1/oauth/token` e GET `/v1/products` com cabeçalhos Bearer e `x-kiwify-account-id`.
- Painel administrativo permite consultar até 10 produtos sem persistir dados, compradores ou credenciais.
- Configure `KIWIFY_CLIENT_ID`, `KIWIFY_CLIENT_SECRET` e `KIWIFY_ACCOUNT_ID` em variáveis de ambiente fora do webroot; não coloque segredos no repositório.
- HTTPS com TLS validado, host fixo, sem redirects, limite de resposta 1 MiB, timeout de 15 s, paginação limitada e mensagens sem conteúdo sensível.
- Teste isolado `php tests/test_kiwify_integration.php` com respostas simuladas, incluído no CI PHP 8.3 e 8.4.
- A Kiwify não disponibiliza sandbox de API segundo sua documentação: nenhum teste com dados reais foi realizado. Os dados retornados não possuem necessariamente checkout/URL de afiliado, portanto **não são publicados automaticamente**.
- A configuração de credenciais **não comprova conexão**; o teste autenticado requer credenciais reais e permissão `products`.
- Limite oficial: 100 requisições/minuto; erros 429 não são repetidos automaticamente.
- Fontes oficiais: https://docs.kiwify.com.br/api-reference/general ; https://docs.kiwify.com.br/api-reference/auth/oauth ; https://docs.kiwify.com.br/api-reference/products/list

## Configuração Hotmart
1. Na Hotmart, crie credenciais de API com acesso autorizado a vendas.
2. Configure no servidor (fora do repositório e do webroot) `HOTMART_CLIENT_ID`, `HOTMART_CLIENT_SECRET`, `HOTMART_BASIC_TOKEN` (token Basic fornecido pela Hotmart, com ou sem prefixo `Basic `).
3. Garanta PHP 8.3+, extensão cURL, certificados CA válidos e saída HTTPS para `api-sec-vlc.hotmart.com` e `developers.hotmart.com`.
4. Entre como administrador, abra Integração > Afiliados e use **Consultar sumário**. O painel não importa compradores nem grava comissões automaticamente.
5. Para validar sem credenciais: `php tests/test_affiliate_integrations.php`.

## Limitações e próximos trabalhos
- **Não há teste real contra a Hotmart nesta subetapa**: depende de credenciais válidas, escopo e conectividade do servidor.
- O cliente Hotmart é somente leitura; não sincroniza automaticamente produtos nem comissões com o banco.
- Conector Monetizze, sincronização persistente e normalização de ofertas/comissões permanecem pendentes. Kiwify oferece consulta de produtos somente leitura, sem publicação.
- A autenticação do sistema ainda não possui isolamento de vendedores; integrações por vendedor serão feitas no item 10. Até lá, não configurar segredos de diferentes vendedores no mesmo ambiente.
- A sincronização legada em `admin/api-sync.php` não foi migrada para o novo adaptador e seus resultados não devem ser considerados prova de conexão.
- Nenhum dado real de produção deve ser importado para a instalação limpa de testes.

## Documentação dos fornecedores
- Hotmart autenticação: https://developers.hotmart.com/docs/pt-BR/start/app-auth/
- Hotmart sumário: https://developers.hotmart.com/docs/pt-BR/v1/sales/sales-summary/
- Kiwify API: https://ajuda.kiwify.com.br/pt-br/article/como-funciona-a-api-da-kiwify-1iosjhu/
- Monetizze API: https://help.monetizze.com.br/books/integracoes/page/api-monetizze

## Status do item 3
**Parcial**. Hotmart e Kiwify têm consultas somente leitura implementadas e testadas com mocks; Monetizze, sincronização e validação com credenciais reais continuam pendentes.

## Complemento da etapa: Monetizze — verificação de chave (24/10/2026)
- Implementado `includes/monetizze_integration.php` para **verificar autenticação**, não para importar vendas, produtos ou comissões.
- Gere uma chave em **Monetizze > Ferramentas > API** e configure `MONETIZZE_CONSUMER_KEY` em variável de ambiente fora do webroot. Não inclua a chave em arquivos versionados.
- O painel apresenta **Verificar chave Monetizze** somente quando a variável está presente. A verificação chama `GET https://api.monetizze.com.br/2.1/token` com o cabeçalho `X_CONSUMER_KEY`, valida a resposta e **descarta** o TOKEN retornado.
- A implementação não faz consultas de vendas, não persiste dados, não configura webhooks e não comprova autorização para outras operações. **O item 3 continua parcial.**
- HTTPS/TLS obrigatório, sem redirects, limite de 1 MiB, timeout de 15 s, bloqueio de CRLF na chave e erros sem dados sensíveis.
- Testes simulados: `php tests/test_monetizze_integration.php`; execução adicionada à matriz PHP 8.3/8.4.
- A autenticação real depende de chave válida e acesso de rede; não foi testada com credenciais reais.
- Documentação oficial da API: https://help.monetizze.com.br/books/integracoes/page/api-monetizze e https://api.monetizze.com.br/2.1/apidoc/ .
- A consulta de produtos/comissões depende da confirmação de endpoints e formatos atuais da API 2.1 e da política de autorização do fornecedor. Não inferir essas rotas nem tratar verificação de chave como sincronização concluída.

## Complemento da etapa: staging de produtos Kiwify (24/10/2026)

- Nova tabela `affiliate_product_staging` com chave única por fornecedor, hash da conta e identificador externo; nenhuma alteração nas tabelas públicas `produtos` ou `cupons`.
- Na área **Integração > Afiliados**, o botão **Importar 10 produtos para revisão** consulta a primeira página da Kiwify e grava **apenas** identificador externo, nome, status e moeda. Os demais campos da API, inclusive eventuais dados pessoais, URLs, preços e tokens, são descartados.
- Importação repetida atualiza o registro existente (upsert), sem duplicação. Produtos inválidos são rejeitados e contabilizados. A gravação é transacional e o escopo de consulta usa um hash SHA-256 do `KIWIFY_ACCOUNT_ID`.
- **Não publica automaticamente, não inventa links de checkout e não altera comissões.** Revisão, mapeamento de loja, preço e URL de checkout devem ocorrer antes de qualquer publicação, em evolução posterior.
- Para banco existente ou instalação nova, aplique `sql/updates/20261024_affiliate_product_staging.sql` **após** `sql/database.sql` e antes de utilizar o botão. A migração cria apenas a tabela vazia, sem dados de exemplo.
- Testes de normalização: `php tests/test_affiliate_product_staging.php`. Testes reais de persistência exigem `TEST_DB_DSN` apontando explicitamente para `dbname=cupons_test`, `TEST_DB_USER` e `TEST_DB_PASSWORD`. O workflow `affiliate-staging.yml` provisiona MySQL 8 e PHP 8.3/8.4 para essa validação.
- Sem credenciais reais da Kiwify, não é possível homologar a leitura da API em produção. Não configurar credenciais de vendedores distintos neste ambiente até o item 10 (segregação multiusuário).
- Esta implementação não executa cron nem importa todo o catálogo: limite de 10 produtos por ação manual, com paginação e publicação automatizadas ainda pendentes.

## Complemento: paginação manual Kiwify e revisão persistida (24/10/2026)

- O painel **Integração > Afiliados** aceita número de página entre 1 e 1000 para consultar ou importar produtos Kiwify. Cada ação consulta somente 10 produtos daquela página; **não** executa varredura automática do catálogo nem publica ofertas.
- A importação mantém a estratégia de upsert no `affiliate_product_staging`, com isolamento pelo hash da conta configurada, sem armazenar compradores, preços ou links.
- A ação **Ver catálogo em revisão** exibe até 20 registros persistidos mais recentes sem chamar a API Kiwify; exige sessão autenticada e token CSRF, além de `KIWIFY_ACCOUNT_ID` válido.
- O campo de página é validado no servidor: valores não inteiros, arrays, números fora do intervalo e entradas ambíguas são recusados.
- Para importar a próxima página, informe manualmente o número seguinte. A interface não interpreta paginação da API como garantia de que há mais registros.
- Testes: `php tests/test_affiliate_import_page.php` e `php tests/test_affiliate_product_staging.php`. A workflow `affiliate-staging.yml` executa ambos em PHP 8.3 e 8.4, com MySQL 8 para o staging.
- **Limitações:** credenciais reais não foram usadas; a lista em revisão ainda não permite editar preço, checkout, loja, imagens nem publicar produtos. Sincronização de comissões e outras plataformas continuam pendentes.


## Complemento: enriquecimento manual do catálogo Kiwify (24/10/2026)

- O painel **Integração > Afiliados > Ver catálogo em revisão** permite editar, para cada produto já importado, descrição (até 1500 caracteres), preço, preço original, URL HTTPS de checkout, URL HTTPS de imagem e loja existente.
- A ação **Salvar revisão (não publicar)** atualiza exclusivamente o registro Kiwify da conta atualmente configurada. Não altera a tabela pública de produtos, não gera links automaticamente, não chama as URLs informadas e não publica anúncios.
- Preços usam ponto decimal, até duas casas; o preço original não pode ser inferior ao preço atual. URLs usam HTTPS, sem credenciais embutidas, hosts locais ou IPs privados/reservados. Campos opcionais podem ficar vazios.
- Os dados manuais de revisão são preservados quando a importação da API atualiza nome ou status do produto. O isolamento por hash da conta e o token CSRF continuam obrigatórios.
- **Migração obrigatória:** execute sql/updates/20261024_affiliate_product_review.sql após sql/updates/20261024_affiliate_product_staging.sql. A migração adiciona campos opcionais e referência à tabela lojas, sem inserir dados de exemplo. Faça backup antes de aplicar em banco existente.
- **Testes:** php tests/test_affiliate_product_review.php valida entradas mesmo sem banco; com TEST_DB_DSN apontando para cupons_test, testa gravação, isolamento entre contas, rollback e preservação no upsert. A workflow affiliate-staging.yml executa o teste com MySQL 8 e PHP 8.3/8.4.
- **Limitações:** não existe botão de publicação; checkout e imagens são URLs fornecidas pelo operador, não verificadas por conexão externa. Ainda faltam autorização por vendedor, publicação supervisionada, sincronização de comissões e homologação com credenciais reais.


## Complemento: publicação supervisionada de produtos Kiwify (24/10/2026)

- O painel **Integração > Afiliados > Ver catálogo em revisão** agora oferece **Publicar oferta revisada**, uma ação explícita com sessão administrativa, token CSRF e confirmação do operador. Salvar revisão continua sem publicar.
- A publicação usa **exclusivamente os campos previamente salvos na revisão**, não valores arbitrários enviados no pedido de publicação. Exige produto de origem com status `active`, moeda `BRL`, descrição de pelo menos 20 caracteres, preço maior que zero, checkout HTTPS de domínio público aceito também por `go.php` e loja existente.
- Imagem HTTPS e preço original são opcionais; quando presentes, são transferidos para o produto. O operador deve conferir manualmente a legitimidade do link de checkout/afiliado: **o sistema não verifica titularidade do link**.
- A transação MySQL utiliza `SELECT ... FOR UPDATE` e associa o registro de revisão ao ID publicado, evitando duplicações em requisições simultâneas e preservando a associação em novas importações. Produtos publicados são identificados na interface; a revisão não atualiza automaticamente uma oferta já publicada.
- **Migração obrigatória e única:** após `sql/database.sql`, `20261024_affiliate_product_staging.sql` e `20261024_affiliate_product_review.sql`, executar `sql/updates/20261024_affiliate_product_publication.sql`. Faça backup antes de migrar bancos existentes. A migração adiciona associação e índice únicos, sem inserir registros reais.
- **Testes:** `php tests/test_affiliate_product_publication.php` verifica entradas inseguras e incompletas. Com `TEST_DB_DSN` restrito a `dbname=cupons_test`, valida transação, isolamento entre contas, ausência de duplicação e reimportação. O CI testa PHP 8.3/8.4 com MySQL 8.
- **Limitações:** a publicação não faz conciliação de comissões, não valida a titularidade dos checkouts, não faz moderação por múltiplos administradores e não implementa segregação por vendedor (item 10). Não configurar credenciais de vendedores diferentes até essa etapa. Nenhuma integração real com a API foi homologada.
- O PR #7 anterior permanece incompatível com o schema atual e não deve ser mesclado sem rebase/revisão; esta implementação foi reconstruída a partir da `main` atual, respeitando as colunas existentes de `produtos` e `lojas`.

- Corrigida a consulta de `oferta.php`: o schema atual de `lojas` não possui a coluna `ativo`. A página de detalhes passa a filtrar apenas a atividade do produto, mantendo a associação com a loja cadastrada.


## Complemento: prévia de comissões Hotmart (24/10/2026)

- Consulta manual somente leitura de \`GET /payments/api/v1/sales/commissions\`, usando OAuth existente e no máximo 10 transações da **primeira página**. O filtro padrão da API, sem parâmetros adicionais, contempla APPROVED e COMPLETE.
- O painel mostra exclusivamente valores agregados **por moeda e papel do participante** (PRODUCER, COPRODUCER, AFFILIATE, ADDON; outras origens como OUTROS). Não retorna nomes, IDs de usuários, compradores, IDs de transação ou credenciais para a interface. Não persiste os resultados.
- **A soma de participantes NÃO é comissão pessoal do operador, saldo a receber, valor liquidado nem conciliação financeira.** Podem existir vários participantes por transação e outras páginas, e reembolsos/chargebacks exigem tratamento próprio.
- Validação estrita de formatos monetários com até duas casas decimais, agregação em centavos inteiros, separação por moeda e rejeição de payloads inválidos. Não há conversão cambial.
- Testes: \`php tests/test_hotmart_commission_preview.php\`, incluído na matriz de CI PHP 8.3/8.4. Sem credenciais reais, o endpoint foi testado somente com respostas simuladas.
- Próximos trabalhos: paginação por cursor, filtragem de status, identificação da conta comissionada, persistência mínima com idempotência, conciliação de estornos e validação real da API. Não executar pagamentos ou lançar receita contábil a partir desta prévia.
- Documentação oficial: https://developers.hotmart.com/docs/pt-BR/v1/sales/sales-commissions/


## Complemento: paginação segura de comissões Hotmart (24/10/2026)

- A consulta manual de comissões permite avançar página a página com `page_token` documentado oficialmente. Cada consulta processa no máximo 10 transações; os totais mostrados são **apenas da página corrente**, sem somar páginas anteriores.
- O cursor opaco `next_page_token` é validado (até 1024 caracteres, sem caracteres de controle ou separadores de query) e mantido exclusivamente na sessão PHP autenticada. Ele **não aparece no HTML, em URLs do navegador, em logs da aplicação ou no banco**.
- O botão **Consultar próxima página** é exibido somente quando a API informa um próximo cursor. **Iniciar consulta** reinicia a paginação. Ambas as ações são POST com proteção CSRF.
- Não há importação de compradores, persistência de transações, cálculo de saldo do usuário nem conciliação financeira. Não tratar os totais de participantes como comissões próprias.
- Testes de OAuth simulado, paginação, codificação de cursor e rejeição de entradas inválidas: `php tests/test_hotmart_commission_preview.php`; executados no CI PHP 8.3/8.4.
- Homologação real depende de credenciais Hotmart autorizadas. Antes de persistir comissões será necessário definir titularidade da conta, isolamento por vendedor, identificador de transação minimizado, idempotência, reembolsos e política de retenção.
- Documentação oficial: https://developers.hotmart.com/docs/pt-BR/v1/sales/sales-commissions/ e https://developers.hotmart.com/docs/en/start/pagination/ .

## Complemento: prévia de comissões por UCODE de participante (24/10/2026)

- A API Hotmart documenta `commissions[].user.ucode` para identificar o participante que recebe cada parcela. O painel agora oferece **prévia separada** apenas para parcelas cujo UCODE coincida com `HOTMART_ACCOUNT_UCODE` (UUID informado manualmente pelo operador no ambiente do servidor).
- Configure `HOTMART_ACCOUNT_UCODE` **fora do repositório e do webroot**, utilizando o identificador oficial do participante na conta autorizada. O valor configurado **não aparece no HTML, logs da aplicação, resultados agregados ou banco**. A comparação não consulta endpoint de validação de titularidade.
- Sem variável configurada, a prévia filtrada fica desativada; a visão anterior de **todos os participantes** permanece disponível e claramente identificada. Com UCODE inválido, a consulta retorna erro de configuração em vez de produzir valores falsamente atribuídos.
- UCODEs ausentes, malformados ou diferentes são excluídos da soma filtrada. O painel apresenta somente contagens e agregados por moeda/origem da **página corrente**. Não persiste dados, não calcula saldo, não converte moedas nem cria lançamentos contábeis.
- **Aviso de negócio:** coincidência com UCODE configurado não comprova que o usuário autenticado no Cupons é o titular, que a transação está liquidada ou que a comissão pertence à instalação. Ainda faltam verificação autorizada da titularidade, segregação por vendedor (item 10), deduplicação entre páginas, tratamento de estornos/chargebacks e homologação com credenciais reais.
- Teste: `php tests/test_hotmart_commission_account_preview.php` (PHP 8.3/8.4 no CI), com coincidência exata sem diferenciar maiúsculas, UCODE ausente, terceiros, valores inválidos e ausência de identificadores pessoais no resultado.
- Fonte oficial: https://developers.hotmart.com/docs/pt-BR/v1/sales/sales-commissions/

## Complemento: registro mínimo de observações de comissões Hotmart (24/10/2026)

- Implementada a tabela vazia `hotmart_commission_staging` (migração `sql/updates/20261024_hotmart_commission_staging.sql`), independente do catálogo. Aplique após `sql/database.sql`, sem importar registros reais ou alterar dados existentes. Faça backup antes de migrar um banco em uso.
- O administrador pode selecionar explicitamente `APPROVED`, `COMPLETE`, `REFUNDED`, `CHARGEBACK` ou `PARTIALLY_REFUNDED` e registrar manualmente até 10 transações da **primeira página** do endpoint oficial, sem percorrer outras páginas automaticamente. A API é consultada com `transaction_status` explícito.
- Configure `HOTMART_ACCOUNT_UCODE` e `HOTMART_COMMISSION_HASH_KEY` (segredo aleatório estável com no mínimo 32 caracteres) exclusivamente no ambiente do servidor, fora do webroot e do Git. A rotação da chave **altera os hashes e pode gerar duplicatas lógicas**: exige migração controlada. Nunca compartilhe a chave.
- A gravação é restrita a sessão com papel `admin` e token CSRF válido. Somente parcelas com UCODE coincidente são consideradas; demais participantes e informações de compradores são descartados. Identificadores de conta e transação são transformados por HMAC-SHA256, sem guardar UCODE, código da transação, nomes, e-mails ou tokens em claro.
- Uma chave única por conta, transação, papel e moeda impede duplicação de grupos em consultas repetidas. Os valores são observações em centavos, **não valores a receber**. Reembolsos, chargebacks e reembolsos parciais apenas acionam uma marca permanente `review_required`; **não** se presume valor de estorno nem se faz ajuste de saldo ou pagamento.
- O status é aquele **solicitado no filtro**, não uma confirmação independente do ciclo de vida da transação. Consultas com status anteriores não apagam o alerta de revisão. Sem webhook/histórico validado, não existe conciliação financeira confiável.
- O isolamento é por conta configurada no ambiente, **não por vendedor autenticado**. Não usar múltiplos vendedores neste estágio; autorização multi-tenant permanece para o item 10. Uma mesma transação em contas distintas terá hash de conta diferente.
- Testes locais sem banco: `php tests/test_hotmart_commission_staging.php`; com `TEST_DB_DSN` contendo obrigatoriamente `dbname=cupons_test`, os testes verificam MySQL, idempotência, isolamento e marcação de estorno. CI em PHP 8.3 e 8.4 com MySQL 8.
- **Pendências:** homologação com credenciais reais, revisão da retenção de dados, conciliação efetiva por status/tempo, múltiplas páginas e períodos, confirmação de titularidade, webhook assinado, acesso por vendedor e tratamento monetário de reembolsos. Não usar estes registros para contabilidade.

## Complemento: paginação manual das observações Hotmart (24/10/2026)

- O administrador pode iniciar a consulta de um status e, após gravar com sucesso cada página, usar **Registrar observações da próxima página**. São no máximo **10 transações por ação** e **50 páginas por sequência**, sem processamento automático em segundo plano.
- O cursor opaco de paginação é armazenado **exclusivamente na sessão PHP**; não aparece no formulário, HTML, URL do navegador ou banco. O status da sequência seguinte também é recuperado da sessão, nunca de campos manipuláveis pelo usuário.
- A sessão de paginação é vinculada ao HMAC da conta configurada por `HOTMART_ACCOUNT_UCODE` e `HOTMART_COMMISSION_HASH_KEY`. Se a conta ou chave mudar, a continuação anterior é rejeitada. Para trocar de status, inicie nova consulta.
- O cursor da próxima página só é atualizado **após** a persistência transacional bem-sucedida; falhas de API ou banco permitem repetir a mesma página sem duplicar grupos. Cursores repetidos/cíclicos interrompem a sequência, e o limite de 50 páginas evita consultas indefinidas.
- A consulta usa sempre `transaction_status` explícito; `APPROVED`, `COMPLETE`, `REFUNDED`, `CHARGEBACK` e `PARTIALLY_REFUNDED` são sequências separadas. Os totais continuam sendo **observações para revisão**, sem saldo, liquidação ou cálculo de estorno.
- Não foi necessário alterar o esquema nesta subetapa. Para instalar do zero, aplique a migração `sql/updates/20261024_hotmart_commission_staging.sql` após o schema base, conforme instruções anteriores. A migração cria tabela vazia, sem dados reais.
- Teste: `php tests/test_hotmart_commission_stage_pagination.php` (PHP 8.3/8.4 no CI), incluindo escopo de conta, status, cursor manipulado, repetição e limite. Os testes de staging MySQL existentes permanecem ativos.
- **Pendências:** homologação com credenciais reais, conciliação de estornos/reembolsos, comprovação de titularidade da conta, isolamento por vendedor (item 10), política de retenção e confirmação da semântica dos status junto à Hotmart. Não utilizar como extrato contábil.


## Complemento: fila de revisão de reembolsos e estornos Hotmart (24/10/2026)

- O painel administrativo **Integração > Afiliados > Fila de alertas para revisão humana** permite consultar, sob autenticação administrativa e CSRF, apenas as **contagens agrupadas por status** dos registros sinalizados para revisão na conta Hotmart configurada. A consulta usa somente o banco local, sem chamar a API nem revelar UCODE, hash de conta, hash de transação, comprador, valor ou token.
- Para consultar, configure `HOTMART_ACCOUNT_UCODE` e `HOTMART_COMMISSION_HASH_KEY` fora do repositório e aplique `sql/updates/20261024_hotmart_commission_staging.sql`. A função exige a tabela existente, mas **não requer migração adicional**.
- Uma observação posterior filtrada como `APPROVED` ou `COMPLETE` **não substitui valor, quantidade nem status** de uma observação previamente sinalizada como `REFUNDED`, `CHARGEBACK` ou `PARTIALLY_REFUNDED`. Novas observações de risco atualizam o último status de risco, mantendo o alerta. Isso evita que páginas antigas desfaçam silenciosamente o registro de alerta.
- Os grupos continuam idempotentes por conta/transação/papel/moeda. A fila mostra o **último status de alerta registrado**, não o histórico completo de eventos nem a situação financeira real da plataforma. Alertas não são baixados automaticamente e não há botão para marcar como conciliado.
- **Sem saldos, ajustes monetários, pagamentos ou reconhecimento de receita.** O filtro de status da API não comprova ordem cronológica dos eventos. Homologação com credenciais reais, validação de titularidade, webhooks assinados, conciliação de reembolsos e isolamento por vendedor (item 10) continuam pendentes.
- Teste automatizado: `php tests/test_hotmart_commission_staging.php`; com `TEST_DB_DSN` apontando estritamente para `cupons_test`, cobre regressão de status/valores, mudança de alerta, contagens agregadas e isolamento por conta. CI executa PHP 8.3 e 8.4 com MySQL 8.


## Complemento: histórico mínimo de observações de comissões Hotmart (24/10/2026)

- O registro manual de comissões agora conserva **observações distintas** por conta, transação (somente HMAC), origem, moeda, status consultado, valor e quantidade, na tabela `hotmart_commission_observations`. Um registro `APPROVED` recebido depois de um `REFUNDED` não elimina o histórico anterior nem resolve o alerta; não se presume ordem cronológica na API.
- **Migração obrigatória para instalações que usam o registro de comissões:** aplique `sql/updates/20261024_hotmart_commission_observations.sql` **depois** de `sql/updates/20261024_hotmart_commission_staging.sql`, com backup prévio de bancos existentes. A nova tabela é criada vazia, sem usuários, transações ou dados reais. O código não cria tabelas automaticamente no painel e não exclui registros preexistentes.
- A escrita do histórico e o upsert de `hotmart_commission_staging` ocorrem **na mesma transação PDO**. Falha em qualquer uma das duas gravações desfaz ambas. Uma repetição idêntica é idempotente por fingerprint HMAC; a mesma combinação com outro status, valor ou quantidade produz observação distinta. A chave `HOTMART_COMMISSION_HASH_KEY` deve permanecer estável e secreta, pois sua rotação altera os hashes.
- No painel, o administrador vê somente contagens por status consultado e quantidade de novas observações. Não são exibidos ou armazenados nomes, e-mails, códigos de transação, UCODE ou tokens em claro. O horário `observed_at` registra **a coleta local**, não a data do evento na Hotmart. Não há webhook, autenticação de titularidade, reconciliação, ajuste de saldo, pagamento ou reconhecimento de receita.
- **Retenção e conformidade:** a política de retenção, descarte seguro e necessidade de histórico financeiro deverão ser aprovadas antes de uso produtivo prolongado. A tabela é um histórico técnico de observações, não uma trilha contábil certificada. Isolamento é por conta configurada, não por vendedor do sistema (item 10).
- Teste: `php tests/test_hotmart_commission_staging.php`; com `TEST_DB_DSN` apontando obrigatoriamente para `cupons_test`, valida histórico idempotente, preservação de status de risco, segregação por conta e não regressão. CI em PHP 8.3/8.4 e MySQL 8.0. Sem banco, apenas validações locais são executadas.
- **Próximas pendências:** homologação com credenciais reais, validação de titularidade, definição de retenção, confirmação da semântica de status e ordenação dos eventos junto à Hotmart, integração de eventos autenticados, conciliação e segregação por vendedor. Não usar a tabela para calcular saldos.

## Complemento: inventário não destrutivo de retenção Hotmart (24/10/2026)

- Na área **Integração > Afiliados > Consultar alertas registrados**, o administrador visualiza um inventário de observações históricas da conta configurada: total e quantidades com mais de **30, 90 e 365 dias**. As faixas são **cumulativas**, não exclusivas.
- A idade é calculada a partir de `observed_at` (data da coleta local), **não** da venda, do estorno ou da liquidação. A consulta SQL é somente leitura, usa o HMAC da conta atual e retorna **apenas contagens**, sem transações, hashes, compradores, valores ou identificadores.
- **Nenhuma exclusão, anonimização adicional, compactação, ajuste de saldo ou rotação de chave é realizada.** Não existe tarefa automática de limpeza nem prazo de descarte presumido. A decisão de retenção e descarte seguro depende de aprovação do proprietário, análise da finalidade e obrigações aplicáveis.
- Requer a migração existente `sql/updates/20261024_hotmart_commission_observations.sql`, aplicada depois de `20261024_hotmart_commission_staging.sql`. **Não há nova migração** nem inserção de dados nesta evolução.
- Teste de integração: `php tests/test_hotmart_commission_staging.php` com `TEST_DB_DSN` apontando exclusivamente para `cupons_test`; verifica faixas, isolamento por conta, rejeição de hash inválido e ausência de exclusão. Sem banco, apenas os testes unitários preexistentes são executados.
- **Pendências:** aprovação formal de uma política de retenção, homologação da API com credenciais reais, titularidade da conta, semântica dos status, conciliação financeira e isolamento por vendedor (item 10). Os números do inventário não devem ser tratados como extrato financeiro.
