<?php

/**
 * TaskFlow — importa os usuários exportados do GLPI legado do DER/PE.
 *
 * Idempotente: cada conta é identificada pelo `name` (login), que é único em
 * `glpi_users`. Rodar de novo só cria quem falta e completa campos vazios de
 * quem já existe; nunca duplica e nunca sobrescreve dado já preenchido.
 *
 * Uso (dentro do container da aplicação):
 *   php tools/taskflow/import_derpe_users.php --dry-run             # só mostra
 *   php tools/taskflow/import_derpe_users.php --scope=ldap          # aplica
 *   php tools/taskflow/import_derpe_users.php --scope=local --dry-run
 *   php tools/taskflow/import_derpe_users.php --limit=20 --dry-run
 *
 * Opções:
 *   --dry-run          não escreve nada; imprime o que faria
 *   --scope=all|ldap|local   quais contas considerar (padrão: all)
 *   --limit=N          processa só as N primeiras (para teste)
 *   --authldap=ID      id do servidor LDAP a vincular; se omitido, resolve
 *                      sozinho quando existe exatamente um configurado
 *
 * Origem: export de `glpi_users` do GLPI legado do DER/PE (2026-09-14),
 * normalizado em tools/data/derpe_users.json. Descartadas as contas de
 * sistema da instância de origem (glpi-system, administrador,
 * Plugin_GLPI_Inventory, GRUPO).
 *
 * Descartada também a conta de AD `glpi`: existe aqui uma conta local de
 * mesmo login, a de administração desta instância. Importar a homônima
 * gravaria um `user_dn` do AD do DER/PE nela, e o sync do LDAP passaria a
 * tratar o superadmin como conta de diretório. Com ela fora, a importação
 * só cria contas — não altera nenhuma já existente.
 *
 * O QUE ESTE EXPORT **NÃO** TRAZ, por não estar em `glpi_users`:
 *   - e-mails      (`glpi_useremails`)   -> contas entram sem endereço
 *   - autorizações (`glpi_profiles_users`) -> contas entram sem nenhum direito
 *   - grupos       (`glpi_groups_users`)
 * Ou seja: isto cria as contas, não os acessos. Perfil e e-mail vêm depois,
 * pelas regras de autorização e pelo sync do AD.
 *
 * O que é deliberadamente NÃO importado, por segurança: `password`,
 * `personal_token`, `api_token`, `cookie_token` e `password_forget_token`.
 * São credenciais válidas na instalação de origem; recriá-las aqui faria
 * token antigo abrir porta nova. Preferências de UI também ficam de fora
 * (`display_options` vem corrompido no export, com JSON malformado).
 *
 * Usa User::add()/update() em vez de SQL direto para que o GLPI calcule
 * `user_dn_hash` (User::pre_addInDB()) e dispare os hooks de criação.
 */

use Glpi\Kernel\Kernel;

if (PHP_SAPI !== 'cli') {
    echo "Este script roda só pela linha de comando\n";
    exit(1);
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$kernel = new Kernel();
$kernel->boot();

$opts   = getopt('', ['dry-run', 'scope::', 'limit::', 'authldap::']);
$dryRun = array_key_exists('dry-run', $opts);
$scope  = $opts['scope'] ?? 'all';
$limit  = isset($opts['limit']) ? (int) $opts['limit'] : 0;

if (!in_array($scope, ['all', 'ldap', 'local'], true)) {
    fwrite(STDERR, "--scope precisa ser all, ldap ou local\n");
    exit(1);
}

$path = __DIR__ . '/data/derpe_users.json';
$rows = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

/** Campos copiados na criação. `id` nunca vem do export: o GLPI atribui o seu. */
const FIELDS = [
    'realname', 'firstname', 'phone', 'phone2', 'mobile',
    'comment', 'registration_number', 'user_dn',
];

/**
 * Resolve o servidor LDAP ao qual vincular as contas de AD.
 *
 * O export traz `auths_id = 1` da instalação de origem, que não tem relação
 * com os ids daqui. Vincular ao servidor errado faz o sync não reconhecer a
 * conta e criar uma duplicata.
 */
function resolveAuthLdapId(?string $forced): int
{
    global $DB;

    if ($forced !== null && $forced !== '') {
        $ldap = new AuthLDAP();
        if (!$ldap->getFromDB((int) $forced)) {
            fwrite(STDERR, "Servidor LDAP {$forced} não existe\n");
            exit(1);
        }
        return (int) $forced;
    }

    $ids = [];
    foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_authldaps']) as $r) {
        $ids[(int) $r['id']] = $r['name'];
    }

    if (count($ids) === 1) {
        return (int) array_key_first($ids);
    }

    fwrite(STDERR, count($ids) === 0
        ? "Nenhum servidor LDAP configurado; use --scope=local ou configure o LDAP antes\n"
        : "Mais de um servidor LDAP configurado, use --authldap=ID: " . json_encode($ids) . "\n");
    exit(1);
}

$needsLdap  = $scope !== 'local'
    && (bool) array_filter($rows, static fn(array $r): bool => $r['authtype'] === Auth::LDAP);
$authLdapId = $needsLdap ? resolveAuthLdapId($opts['authldap'] ?? null) : 0;

$created = $completed = $unchanged = $failed = 0;
$done    = 0;

foreach ($rows as $row) {
    $isLdap = $row['authtype'] === Auth::LDAP;
    if ($scope === 'ldap' && !$isLdap) {
        continue;
    }
    if ($scope === 'local' && $isLdap) {
        continue;
    }
    if ($limit > 0 && $done >= $limit) {
        break;
    }
    $done++;

    $name = $row['name'];
    $user = new User();

    if ($user->getFromDBbyName($name)) {
        // Já existe: só completa campo vazio, nunca sobrescreve o que está lá.
        $fill = [];
        foreach (FIELDS as $f) {
            if (isset($row[$f]) && ($user->fields[$f] ?? '') === '') {
                $fill[$f] = $row[$f];
            }
        }

        if ($fill === []) {
            $unchanged++;
            continue;
        }

        printf("~ %-28s completa: %s\n", $name, implode(', ', array_keys($fill)));
        if (!$dryRun) {
            $fill['id'] = $user->fields['id'];
            if ($user->update($fill) === false) {
                fwrite(STDERR, "  falhou ao atualizar {$name}\n");
                $failed++;
                continue;
            }
        }
        $completed++;
        continue;
    }

    $input = ['name' => $name, 'is_active' => $row['is_active']];
    foreach (FIELDS as $f) {
        if (isset($row[$f])) {
            $input[$f] = $row[$f];
        }
    }
    if ($isLdap) {
        $input['authtype'] = Auth::LDAP;
        $input['auths_id'] = $authLdapId;
    }

    printf("+ %-28s %s\n", $name, $isLdap ? "LDAP(auths_id={$authLdapId})" : 'local');
    if (!$dryRun) {
        $user = new User();
        if ($user->add($input) === false) {
            fwrite(STDERR, "  falhou ao criar {$name}\n");
            $failed++;
            continue;
        }
    }
    $created++;
}

printf(
    "\n%s | escopo=%s | %d criados, %d completados, %d sem mudança, %d falhas\n",
    $dryRun ? 'DRY-RUN (nada gravado)' : 'APLICADO',
    $scope,
    $created,
    $completed,
    $unchanged,
    $failed
);

exit($failed > 0 ? 1 : 0);
