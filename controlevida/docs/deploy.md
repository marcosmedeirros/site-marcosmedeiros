# Publicação em marcosmedeiros.site

O deploy é o mesmo do site: a Hostinger publica o repositório a cada push no `main`. A pasta `controlevida/` chega junto e não altera nenhum arquivo do site profissional.

## Primeira instalação (sem acesso ao terminal)

1. Abra `https://marcosmedeiros.site/controlevida/`. Enquanto não houver configuração, o endereço leva ao instalador.
2. Informe a **chave de instalação**. Ela foi gerada na máquina de quem preparou a publicação e fica em `controlevida/.private/chave-instalacao.txt` (fora do Git). O repositório guarda apenas o SHA-256 dela em `server/install-key.php`; sem a chave o instalador recusa qualquer pedido.
3. Informe os dados do banco MySQL. Para trazer os dados do app anterior, use o mesmo banco dele (hPanel → Bancos de dados). Se o banco estiver em outro plano de hospedagem, use o host remoto do MySQL e libere o acesso remoto no hPanel.
4. Defina nome, e-mail e senha de acesso. A senha é gravada somente como hash.
5. Com "Trazer dados do app anterior" marcado, a migração roda em seguida e a página mostra o que foi importado.

Depois de concluído, `install.php` responde 404. Para reinstalar (por exemplo, para redefinir a senha), apague o arquivo de configuração e repita os passos com a mesma chave: os registros existentes são mantidos.

A configuração e a pasta de dados (sessões, log do PHP, backup da migração) ficam em `domains/marcosmedeiros.site/.controlevida/`, fora do `public_html`. Se o servidor não permitir, ficam em `controlevida/.private/`, bloqueada pelo `.htaccess`.

## O que a migração faz

- Lê, sem alterar, as tabelas do app anterior: finanças (`fin_transactions`, `finances`, categorias e saldo inicial), tarefas e conclusões, atividades, hábitos, rotina, eventos, plano e histórico de treino, corridas, alimentação, notas, metas e regras pessoais. Tabelas de outros produtos no mesmo banco são ignoradas.
- Grava um backup JSON das linhas lidas na pasta de dados (as fotos embutidas das notas não são copiadas).
- É repetível: cada registro antigo gera sempre o mesmo ID, então uma segunda execução não duplica nada. O botão "Importar do app anterior", em Ajustes, executa a mesma rotina.
- Linhas com dados inválidos (valor zerado, tipo desconhecido, data impossível) ficam de fora e são contadas no resultado, sem interromper o restante.

Se o banco antigo não estiver acessível a partir do `.site`, há um caminho alternativo: `node scripts/export-legacy.mjs --from=2025-01 --to=2026-12` exporta os dados pela API do app antigo para `.private/legacy-snapshot.json`, e Ajustes → "Importar arquivo" carrega esse arquivo (finanças, tarefas, hábitos, metas e corridas).

## Conferência depois de publicar

- A raiz do site continua igual e `https://marcosmedeiros.site/controlevida/` abre o login.
- `controlevida/server/`, `scripts/`, `tests/`, `docs/`, `.private/`, `package.json` e `README.md` respondem 403/404.
- Totais por mês, saldo inicial e saldo acumulado batem com o app antigo.
- Conexão MCP: o assistente recebe 401 com `resource_metadata`, descobre o servidor de autorização, registra o cliente e pede o consentimento.

## Aposentar o app antigo

Depois de conferir os dados, tire o `marcosmedeiros.page` do ar ou redirecione-o para `https://marcosmedeiros.site/controlevida/`. Atenção: o app antigo não tem login e expõe os dados a qualquer visitante, então vale fazer isso logo após a conferência. O banco e o backup devem ser preservados.

## Próximas etapas

- Google Agenda: OAuth com escopo mínimo e sincronização idempotente por ID externo.
- Notificações: consentimento por aparelho, Web Push e rotina de envio no servidor.
- Widgets no celular: exigem um app complementar; uma PWA sozinha não entrega widgets nativos.
