# GLPI-TaskFlow

Fork do GLPI para o helpdesk N1 do DER/PE, com identidade visual própria
("TaskFlow") e roadmap de integração com WhatsApp (Evolution API) e
sincronização com o SCCD (Softplan) para chamados N2/N3.

Base: GLPI `11.0.9-dev`, branch `11.0/bugfixes`.

---

## 1. Como isto roda em produção

```
commit em 11.0/bugfixes
        │
        ▼
GitHub Actions (runner ubuntu-24.04-arm)
        │  build da imagem
        ▼
ghcr.io/renato-feijo/glpi-taskflow:sha-<commit>
        │  o mesmo job fixa a tag no compose e chama o webhook
        ▼
Portainer → stack Swarm (compose.swarm.yml)
        │
        ▼
Traefik → https://taskflow.renatofeijo.com.br
```

### Host

| Item | Valor |
|---|---|
| SSH | `taskflow-01` (136.248.90.216), OCI Ampere |
| Arquitetura | **aarch64** — a imagem precisa ser arm64 |
| Recursos | 2 vCPU, 11 GB RAM, 193 GB disco, sem swap |
| Docker | 29.8.0, **Swarm ativo** (nó único, Leader) |

### Serviços da stack

`glpi-taskflow_app` (Apache+PHP), `glpi-taskflow_db` (MariaDB 11.8),
`glpi-taskflow_cron` (laço do `glpi:cron`).

Volumes: `glpi_db_data`, `glpi_config`, `glpi_files`, `glpi_marketplace`.
**Sobrevivem a redeploys** — só se perdem se removidos explicitamente.

### Traefik

Roda como container standalone em `/opt/traefik`, v3.7.4. Alterado por nós:

- `--providers.swarm=true` + `--providers.swarm.exposedbydefault=false` +
  `--providers.swarm.network=traefik-net`, ao lado do provider `docker`
  já existente (a v3 permite os dois juntos).
- A rede externa **`traefik-net`** passou a ser declarada no compose. Antes o
  container só entrava nela por `docker network connect` manual, e qualquer
  `docker compose up -d` a perderia, derrubando o roteamento do Portainer.
- `--log.level` de `DEBUG` para `INFO`.

Backups do arquivo: `/opt/traefik/docker-compose.yaml.bak-*`.

### Deploy pelo Portainer

**Stacks → Add stack → Repository**, endpoint **Swarm**:

| Campo | Valor |
|---|---|
| Repository URL | `https://github.com/renato-feijo/glpi-taskflow` |
| Reference | `refs/heads/11.0/bugfixes` |
| Compose path | `compose.swarm.yml` |

Marcar **re-pull da imagem** ao atualizar. Sem isso o Swarm trata a mesma tag
`latest` como no-op e não recria nada.

#### Redeploy automático

Um push na branch vai sozinho até produção. Depois de publicar a imagem, o
workflow faz duas coisas:

1. **Fixa a tag no compose.** Reescreve o `image:` de `app` e `cron` para
   `sha-<commit>` e commita (`Deploy sha-... [skip ci]`). Só esse arquivo muda,
   e ele está no `paths-ignore` — o commit do bot não dispara outro build.
2. **Chama o webhook do GitOps**, que faz o Portainer reler a branch e
   redeployar a stack.

**Por que não basta o webhook.** O *Re-pull image* é Business; nesta instalação
(CE, self-hosted) ele não existe. Redeployar com a mesma tag `latest` seria
no-op no Swarm — o webhook responderia 200 e produção continuaria igual. Com a
tag imutável do commit, o nó não tem a imagem localmente e é obrigado a buscá-la.
Por isso **`compose.swarm.yml` não deve voltar para `:latest`**.

Configuração, uma vez só:

1. Portainer → **Stacks → `glpi-taskflow` → `Edit Git settings`** (painel
   *GitOps updates*): ligar as atualizações automáticas, escolher **Webhook**
   em vez de *Polling*, e copiar a URL
   (`https://portainer.renatofeijo.com.br/api/stacks/webhooks/<uuid>`).

   Não é a seção *Webhooks* da aba *Editor* — aquela é Business.
2. GitHub → *Settings → Secrets and variables → Actions* → segredo
   **`PORTAINER_WEBHOOK_URL`** com essa URL.

A URL é segredo porque **é a própria credencial**: um POST nela redeploya a
stack, sem outra autenticação. Se o segredo não existir, o workflow apenas
avisa e termina verde — a imagem fica no GHCR, o compose já aponta para ela, e
só falta acionar o redeploy pelo Portainer.

O webhook responde **204 imediatamente** e faz o deploy em background: o passo
verde significa "gatilho aceito", não "stack no ar". O resultado se confere no
Portainer ou com `docker service ps glpi-taskflow_app`. Durante a troca da
task o Traefik responde 404 (ver *Armadilhas*).

Como o bot commita na branch, `git pull` antes de continuar trabalhando.

Variáveis: `GLPI_DOMAIN`, `TRAEFIK_CERTRESOLVER`, `GLPI_DB_NAME`,
`GLPI_DB_USER`, `GLPI_DB_PASSWORD`, `GLPI_DB_ROOT_PASSWORD`,
`GLPI_DEFAULT_LANGUAGE`.

---

## 2. Onde alterar o quê

Decidir a camada certa evita divergência desnecessária do upstream e ciclos
de build de ~8 minutos.

| Quer mudar | Camada | Precisa build? |
|---|---|---|
| Cores, logo, espaçamento do tema | `files/_themes/moderndark.scss` | **Não** — vive no volume |
| Textos, campos e ordem de formulários | Banco (ou UI do construtor de formulários) | Não |
| Tiles da home, permissões, configs | Banco (ou UI) | Não |
| Arquivos de imagem (`public/pics/`) | Imagem | Sim |
| Templates, `src/`, CSS do core | Imagem | Sim |

Para iterar rápido no tema: editar, `docker cp` para
`/var/www/glpi/files/_themes/`, `php bin/console cache:clear
--allow-superuser`. O SCSS do GLPI é compilado **em tempo de execução**, não
pelo webpack — por isso funciona sem build.

---

## 3. Personalizações versionadas (estão no Git)

### Deploy

- `Dockerfile` — multi-stage, PHP 8.4 arm64. Lista única de extensões via
  `ARG PHP_EXTENSIONS`, `install-php-extensions` **pinado em 2.11.12**,
  compilação dos `.mo`, regras de rewrite do Apache, temas copiados para
  `/opt/glpi-themes`.
- `docker-entrypoint.sh` — aguarda o banco, `database:install` ou
  `database:update`, instala temas no volume, limpa cache.
- `compose.swarm.yml` — stack Swarm.
- `.dockerignore` — mantém os ~920 MB de `.git` fora do contexto.
- `.github/workflows/taskflow-image.yml` — build arm64 → GHCR.

### Identidade

- `files/_themes/moderndark.scss` — paleta escura e substituição da marca pelas
  variáveis `--glpi-logo-*`, com ajuste das caixas por causa da proporção da
  arte (2.46 contra 1.82 da arte original do GLPI).
- `public/pics/logos/logo-taskflow-{light,dark,mark}.png`, `public/pics/favicon.ico`.
- `src/autoload/CFG_GLPI.php` — `app_name = 'TaskFlow'` (título da aba, rodapé
  das notificações, rótulo do 2FA).
- `templates/layout/parts/user_header.html.twig` — "Sobre" com a marca própria,
  **mantendo a atribuição ao GLPI** (GPLv3 §5d exige preservar avisos legais em
  interfaces interativas).

### Correções de comportamento

- `src/Glpi/Controller/Form/RendererController.php` — título da página vinha do
  nome bruto do formulário enquanto o `<h1>` usava a tradução; agora ambos usam
  a tradução. **Candidato a PR upstream.**
- `css/includes/components/_fileupload.scss` — input de arquivo transbordava em
  telas estreitas.
- `css/includes/components/form/_form-renderer.scss` — `min-width: 0` nos itens
  flex das perguntas.
- `templates/pages/helpdesk/index.html.twig` + `css/helpdesk_home.scss` — tiles
  acima da faixa de busca.
- `src/Glpi/Helpdesk/DefaultDataManager.php` — ordem do formulário de incidente
  para instalações novas.

---

## 4. Configurações que vivem SÓ no banco

**Não são reproduzíveis a partir do repositório.** Uma instalação nova volta ao
padrão do GLPI. Persistem a redeploys, mas não a uma recriação de volume.
Para montar homologação, o caminho barato é restaurar um dump de produção.

### Formulário "Abrir um Chamado" (id 1)

Ordem: Título · Categoria · Localização · Cópia para: · Urgência · Descrição ·
Anexos. Campo "Dispositivos do usuário" removido.

Nome do formulário: `Abrir um Chamado`; descrição: `Reportar um problema`.

### Tiles da home

1. Pesquisar por Ajuda · 2. Abrir um Chamado · 3. Meus chamados

Removidos: catálogo de serviços, reservas e o tile do formulário de serviço.

### Configurações globais

| Chave | Valor | Efeito |
|---|---|---|
| `display_login_source` | `0` | Remove o seletor de origem no login |
| `login_remember_time` | `0` | Remove o "Lembrar de mim" |
| `login_remember_default` | `0` | — |
| `enable_helpdesk_service_catalog` (entidade 0) | `0` | Remove o menu e bloqueia `/ServiceCatalog` |

Perfil **Self-Service**: direito `reservation` = `0`, o que remove o item de
menu **e** bloqueia `/front/reservationitem.php`. Os perfis Observer, Admin,
Technician e Supervisor mantêm o direito.

Idiomas: reduzidos via interface a pt_BR, English e English (US).

Backups das alterações: `~/taskflow-backups/` no host.

---

## 5. Armadilhas já pagas

Registradas para não custarem duas vezes.

**O rótulo visível de um formulário não é a coluna `name`.** O
`form_renderer.html.twig` resolve por `translate_form_item_key()`, que lê
`glpi_itemtranslations_itemtranslations` — uma linha por item **e por idioma**,
semeada na instalação. Essa linha vence a coluna base. Alterar só o `name` não
tem efeito visível.

**O `bin/console` recusa rodar como root** sem `--allow-superuser`. E em um
fork de branch de desenvolvimento, `database:update` também exige
`--allow-unstable`, porque a versão sempre carrega o sufixo `-dev`.

**`app` e `cron` compartilham o volume `glpi_config`.** Se ambos rodarem
`database:install`, competem e a importação falha com chave duplicada. Daí o
`GLPI_SKIP_DB_INIT=1` no `cron`.

**Os `.mo` não vêm no código-fonte.** Os tarballs de release do GLPI os
trazem prontos; quem builda do fonte precisa compilá-los, ou o
`database:install` aborta em `en_GB.mo`. Usamos `msgfmt` direto, porque
`bin/console tools:locales:compile` está sob `Glpi\Tools\`, que é
`autoload-dev` e não existe após `composer install --no-dev`.

**O GLPI não fornece `.htaccess`.** Sem regras de rewrite no vhost, só a home
funciona (via `DirectoryIndex`) e toda rota legada dá 404 do Apache.

**Swarm ignora `build`, `restart`, `container_name` e `depends_on`,** e exige
redes overlay. Uma rede bridge remanescente com o mesmo nome é **reutilizada**
pelo `docker stack deploy` e faz o deploy falhar — é preciso removê-la.

**404 em texto puro durante o redeploy é o Traefik,** não a aplicação: com
`replicas: 1`, o router desaparece enquanto a task é substituída.

**`docker service update --image` com a mesma tag `latest` é no-op.** Para
forçar, usar `--force` ou o re-pull do Portainer — que é Business. Daí o
workflow fixar `sha-<commit>` no compose em vez de pedir um pull.

**As caixas de `.glpi-logo` são fixas** (100×55 no cabeçalho, sem
`background-size`) e o core reduz a marca a 40×40 **apenas** com o menu
recolhido em telas grandes. Uma regra sem esse escopo encolhe o cabeçalho.

---

## 6. Pendências

- **Senhas padrão** (`glpi`, `post-only`, `tech`, `normal`) ainda de fábrica,
  em instância exposta na internet. Mais urgente da lista.
- **API REST** respondendo publicamente; desativar em *Configuração → Geral →
  API* até a integração com Evolution API e SCCD.
- **`perf/slim-builder-extensions`** — branch local, sem push. Reduz o builder
  a 5 extensões em vez de 16 e corta ~3 min do build. Não validada.
- **Imagem com 3.8 GB** — o `COPY --from=builder` leva o `node_modules` com as
  devDependencies do webpack. Removê-las após o build de assets deve cortar
  mais de 1 GB.
- **`build:illustration-translations` não roda** no build (depende de
  `Glpi\Tools\`). Ilustrações do portal sem tradução.
- **Compilar só os idiomas em uso** (pt_BR, en_GB, en_US) em vez dos 67 `.po`
  encurtaria o build.
- **`update_config: order: start-first`** no compose eliminaria o 404 durante
  redeploys, ao custo de duas instâncias compartilhando volumes por alguns
  segundos.

---

## 7. Licença e marca

GLPI é GPLv3. Modificar e redistribuir é permitido; a licença cobre copyright,
não marca registrada. "GLPI" é marca da Teclib'.

Este repositório é **público** com a identidade visual substituída. A
atribuição foi mantida no "Sobre" e nos cabeçalhos dos arquivos-fonte, o que
atende a GPLv3 §5d. A logo atual traz "POWERED BY GLPI" na tagline.
