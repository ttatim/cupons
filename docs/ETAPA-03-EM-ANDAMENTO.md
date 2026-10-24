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
