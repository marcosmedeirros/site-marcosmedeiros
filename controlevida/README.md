# Controle Vida

Central pessoal em `https://marcosmedeiros.site/controlevida/`. Tudo o que pertence a ela fica nesta pasta; o site profissional da raiz não depende de nada daqui. A única alteração fora da pasta é uma regra no `.htaccess` da raiz para a descoberta OAuth do MCP (`/.well-known/oauth-*`).

Aplicação PHP + PDO (MySQL em produção, SQLite nos testes e na prévia local), interface sem etapa de build e PWA instalável.

## O que está implementado

- Hoje: tarefas pendentes, agenda, tarefas da casa, hábitos, resumo financeiro, treinos e refeições.
- Tarefas em um quadro da semana, uma coluna por dia (mais "Sem dia" para o que ainda não tem lugar), com categoria por tarefa e troca de dia arrastando pela alça ou tocando nela para escolher da lista.
- Eventos, hábitos, lançamentos, treinos, refeições, notas e metas, com repetições diárias, semanais e mensais, conclusões por data, histórico e arquivo recuperável. Metas são apenas concluídas ou não.
- Finanças organizadas por mês, não por data: o lançamento guarda o mês (dia 01), o formulário já abre no mês atual com um atalho para o anterior e a descrição é opcional — sem ela vale a categoria. Valores em centavos inteiros, categorias, CSV e exportação dos próprios dados.
- Agenda mensal com exportação ICS e assinatura de calendário: um endereço com token revogável publica eventos, tarefas e treinos (com repetição como RRULE) para o calendário do celular e para o Google Agenda, em leitura. Dinheiro, refeições, notas e hábitos nunca entram nesse feed.
- Widget na tela de início do celular pelo app Scriptable: `widget.php` devolve o dia e a sequência da semana em JSON, e serve o próprio script já com o endereço pessoal dentro. Notificações push não existem e não são apresentadas como conectadas.
- Login por sessão com senha em hash, opção "manter conectado" (60 dias), CSRF, limite de tentativas, validação no servidor, revisões contra sobrescrita concorrente e auditoria.
- MCP HTTP com OAuth, PKCE S256, consentimento, permissões de leitura/escrita, tokens curtos, rotação e revogação.
- Visual editorial: fundo preto, títulos em serifada, azul nos detalhes, divisões por fios no lugar de caixas, e atalhos na barra inferior no celular.

## Estrutura

| Caminho | Conteúdo |
| --- | --- |
| `index.php`, `api.php`, `mcp.php`, `oauth.php`, `metadata.php`, `calendar.php`, `widget.php` | Páginas e endpoints públicos |
| `install.php` | Instalador web de uso único (some depois de configurado) |
| `assets/` | Interface (CSS, JS, ícones Lucide) |
| `server/` | Código do servidor; nunca servido |
| `scripts/`, `tests/`, `docs/` | Ferramentas de desenvolvimento; nunca servidas |
| `.private/` | Configuração e dados locais; ignorada pelo Git e nunca servida |

## Dados e privacidade

O navegador guarda os dados pessoais apenas em memória. O service worker armazena os assets e uma página offline neutra, nunca respostas da API. Sem conexão, alterações são recusadas e mostradas como não salvas.

Senhas, backups e credenciais não fazem parte do Git. A configuração é gravada fora do `public_html` (`../../.controlevida/config.php`) quando o servidor permite; caso contrário, em `controlevida/.private/`, bloqueada pelo `.htaccess`. O banco usa tabelas `cv_*`, o que permite reaproveitar o MySQL do app anterior sem alterar as tabelas antigas.

## Desenvolvimento

Requisitos: PHP 8.0+ com PDO, `pdo_sqlite` (testes), `pdo_mysql` (produção) e `mbstring`; Node 20+ apenas para os testes. Todos os comandos rodam dentro de `controlevida/`.

1. `npm ci` (e `npm run vendor` para atualizar a cópia do Lucide, já versionada).
2. Defina `CV_ADMIN_EMAIL`, `CV_ADMIN_PASSWORD` e `CV_URL` (por exemplo `http://127.0.0.1:8873/controlevida`) apenas no ambiente do comando e execute `php scripts/setup.php` uma vez.
3. `php -S 127.0.0.1:8873 -t .. scripts/router.php` sobe o site inteiro; o roteador reproduz as regras do Apache.
4. `npm test` cobre autenticação, CSRF, conflitos de revisão, importação, migração do app anterior, instalador web, PKCE, rotação/revogação e interoperabilidade com o SDK oficial do MCP. `PHP_BIN` escolhe o executável do PHP.

## Publicação e migração

Veja [docs/deploy.md](docs/deploy.md).

## Links do celular

Em Ajustes → Integrações, cada botão gera um endereço próprio: `calendar.php?t=<token>` para o calendário e `widget.php?t=<token>` para o widget. São tokens separados, criados e revogados um sem o outro, porque o endereço do calendário é entregue aos servidores da Apple ou do Google enquanto o do widget fica só no celular.

`widget.php` devolve JSON com os registros de hoje, quantos treinos da semana foram cumpridos e o próximo evento; com `&script=1` devolve o script do Scriptable já apontando para o endereço pessoal. As regras de repetição desse arquivo espelham as de `assets/app.js` — mexer em uma pede conferir a outra. Clientes de calendário não têm onde fazer login, então esse token na URL é a credencial inteira: ele é aleatório, longo, nunca indexado e revogável no mesmo lugar. Quem tiver o endereço vê a agenda, por isso ele deve ser tratado como senha.

O feed de calendário traz eventos, tarefas e treinos não arquivados — repetições viram `RRULE`, horários saem em UTC e registros avulsos com mais de 30 dias ficam de fora. Lançamentos, refeições, notas e hábitos não entram.

## MCP

Endereço: `https://marcosmedeiros.site/controlevida/mcp.php` (também em Ajustes e conexões). As ferramentas consultam, criam/atualizam, concluem e arquivam registros com a mesma validação da interface. Alimentação é registrada de forma descritiva; não há cálculo de calorias, metas de peso nem prescrição.

Para conectar um assistente (Claude, ChatGPT ou outro), adicione o endereço como conector MCP personalizado com OAuth; o próprio servidor faz o registro dinâmico do cliente e pede seu consentimento. O endpoint autentica só por token Bearer, que o navegador não envia sozinho, então não restringe a origem de quem chama e aceita qualquer revisão datada do protocolo. Cada conexão pode ser revogada em Ajustes.
