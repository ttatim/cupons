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
