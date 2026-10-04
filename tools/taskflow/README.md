# Scripts de parametrização do TaskFlow

Esta pasta guarda tudo que é **nosso** dentro de `tools/`. O resto de
`tools/` vem do GLPI upstream (`build_glpi.sh`, `make_release.sh`,
`patches/`, `src/`…); mantendo nossos arquivos aqui, um merge do upstream
em `11.0/bugfixes` não os arrasta pro diff nem gera conflito.

Cada script parametriza um pedaço da instância — categorias, localidades,
regras de atribuição, usuários — e roda contra o **banco de produção**.

## Como rodar

Os scripts rodam pela CLI, de dentro do container da aplicação, com o
working directory na raiz do GLPI. O id do container muda a cada deploy,
então descubra ele primeiro:

```sh
ssh taskflow-01 'docker ps --filter name=taskflow_app --format "{{.ID}}"'
```

E então:

```sh
ssh taskflow-01 'docker exec -w /var/www/glpi <container> \
  php tools/taskflow/seed_modules.php --dry-run'
```

Se o script não estiver na imagem ainda (foi escrito depois do último
deploy), leve ele pelo `docker cp` em vez de esperar o CI/CD:

```sh
scp tools/taskflow/seed_modules.php taskflow-01:/tmp/
ssh taskflow-01 'docker cp /tmp/seed_modules.php \
  <container>:/var/www/glpi/tools/taskflow/seed_modules.php'
```

O arquivo copiado assim vive só naquele container e some no próximo
redeploy — o que, para uma operação de uma vez só, é o comportamento
desejado.

## As duas regras

**1. Todo script aceita `--dry-run`, e o `--dry-run` vem primeiro.**
Sem a flag, o script escreve em produção. Com ela, imprime exatamente o que
faria e não grava nada. Rode o dry-run, leia a contagem final, e só então
rode pra valer.

**2. Todo script é idempotente.**
Rodar de novo não duplica: cada script identifica o que já existe por uma
chave natural (o login em `glpi_users`, o `completename` em categorias, e
assim por diante), cria só o que falta e completa só campo vazio — nunca
sobrescreve o que já está lá. Se um script parar no meio, a correção é
rodar de novo.

Vale lembrar que `--dry-run` não é transação: a aplicação real não tem
rollback. É por isso que a regra 2 existe.

## Ordem de execução

Numa instância zerada, nesta ordem — os de baixo dependem dos de cima:

| # | Script | O que faz |
|---|---|---|
| 1 | `configure_notifications.php` | SMTP e templates de notificação |
| 2 | `configure_ldap.php` | servidor LDAP/AD do DER/PE |
| 3 | `seed_modules.php` | módulos do sistema (siglas usadas adiante) |
| 4 | `seed_locations.php` | árvore de localidades (DROs, sede) |
| 5 | `seed_solution_types.php` | tipos de solução |
| 6 | `import_sider_categories.php` | árvore de categorias do SIDER |
| 7 | `import_derpe_users.php` | contas do GLPI legado do DER/PE |
| 8 | `seed_support_team.php` | equipe de suporte e seus perfis |
| 9 | `seed_assignment_rules.php` | regras de atribuição (usa 3, 4 e 6) |
| 10 | `disable_demo_accounts.php` | desativa as contas de demonstração |

`seed_assignment_rules.php` avisa e segue adiante se um módulo ou categoria
que ele espera não existir — não é erro fatal, mas é sinal de que um script
anterior não rodou.

## `data/`

Entrada dos scripts de importação, em JSON — hoje só
`derpe_users.json`, o export de `glpi_users` do GLPI legado.

**Está no `.gitignore` de propósito.** São dados pessoais de servidores do
DER/PE (login, nome e DN de AD); commitar coloca isso no histórico do
repositório de forma permanente. O arquivo fica na máquina de quem importa
e vai pro container via `docker cp`.

## Sobre acessos

`import_derpe_users.php` cria **contas, não acessos**. O export de origem
não traz perfis (`glpi_profiles_users`) nem e-mails (`glpi_useremails`), e
senhas e tokens são deliberadamente descartados. As contas entram sem
nenhum direito; quem concede acesso são as regras de autorização e o sync
do AD.

A conta `glpi` do export fica de fora: já existe aqui uma conta local de
mesmo login, a de administração da instância. Ver o cabeçalho do script.

## `themes-backup/`

Cópias dos temas customizados em SCSS. Atenção ao compilar: o
`build:compile_scss` emite CSS de 0 byte para temas em `files/_themes`.
