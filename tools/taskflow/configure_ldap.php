<?php

/**
 * TaskFlow — corrige e completa a configuração do servidor LDAP (AD do DER/PE).
 *
 * O servidor `DERPE` já existe em `glpi_authldaps` (id 1, ativo e padrão), mas
 * com três defeitos independentes:
 *
 *   1. `condition` malformado: `((&(objectClass=user)(objectCategory=person))`
 *      abre dois parênteses e fecha um. O AD recusa o filtro, e qualquer busca
 *      de usuário falha. **Este script sempre corrige.**
 *   2. `host` = 192.168.100.8, um IP da LAN do DER/PE que a VM na nuvem não
 *      alcança. Só passa a valer quando o túnel WireGuard estiver de pé.
 *   3. senha do bind (`rootdn_passwd`) não definida, então o bind falha.
 *
 * Idempotente e conservador: só altera o que for explicitamente informado pelo
 * ambiente. Rodar sem nenhuma variável corrige apenas o filtro e não toca em
 * host, porta nem senha — dá para aplicar a correção antes do túnel existir.
 *
 * A SENHA NÃO FICA NESTE ARQUIVO. Vem do ambiente, como no script de SMTP:
 *
 *   TASKFLOW_LDAP_PASSWORD='...' \
 *   TASKFLOW_LDAP_HOST=192.168.213.2 \
 *   TASKFLOW_LDAP_PORT=636 \
 *   php tools/taskflow/configure_ldap.php
 *
 * Uso:
 *   php tools/taskflow/configure_ldap.php --dry-run   # só mostra
 *   php tools/taskflow/configure_ldap.php             # aplica
 *   php tools/taskflow/configure_ldap.php --test      # testa a conexão depois
 *
 * Sobre LDAPS: no GLPI a coluna `use_tls` significa **STARTTLS na porta 389**,
 * não LDAPS. Para LDAPS (636) o esquema vai no próprio host, como
 * `ldaps://192.168.213.2`. Este script monta isso sozinho a partir da porta,
 * porque errar aí gera um "não conecta" sem mensagem útil.
 *
 * Sobre `sync_field`: em AD o campo estável é `objectguid` — é o que evita que
 * renomear alguém no AD vire um usuário duplicado no GLPI. Trocar isso depois
 * de já ter usuários sincronizados é problemático; como hoje há zero usuários
 * LDAP, este é o momento certo. Só preenche se estiver vazio.
 */

use Glpi\Kernel\Kernel;

if (PHP_SAPI !== 'cli') {
    echo "Este script roda só pela linha de comando\n";
    exit(1);
}

/** O servidor LDAP, identificado pelo nome. */
const SERVIDOR = 'DERPE';

/** O filtro correto de usuários de um Active Directory. */
const FILTRO_CORRETO = '(&(objectClass=user)(objectCategory=person))';

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$kernel = new Kernel();
$kernel->boot();

$dry_run = in_array('--dry-run', $argv, true);
$testar  = in_array('--test', $argv, true);

$env_host = getenv('TASKFLOW_LDAP_HOST') ?: null;
$env_port = getenv('TASKFLOW_LDAP_PORT') ?: null;
$env_pass = getenv('TASKFLOW_LDAP_PASSWORD');

$ldap = new AuthLDAP();
if (!$ldap->getFromDBByCrit(['name' => SERVIDOR])) {
    printf("!  servidor LDAP '%s' não encontrado em glpi_authldaps\n", SERVIDOR);
    printf("   este script corrige um servidor existente; não cria do zero\n");
    exit(1);
}

$atual = $ldap->fields;
$id    = (int) $atual['id'];

printf("Servidor '%s' (id %d)\n\n", SERVIDOR, $id);

$mudancas = [];

// --- 1. filtro de busca ------------------------------------------------------
// Sempre conferido: é o defeito que não depende de nada externo.
if ($atual['condition'] !== FILTRO_CORRETO) {
    printf("~  condition\n");
    printf("     de : %s\n", $atual['condition']);
    printf("     para: %s\n", FILTRO_CORRETO);
    $mudancas['condition'] = FILTRO_CORRETO;
} else {
    printf("=  condition já correto\n");
}

// --- 2. host e porta ---------------------------------------------------------
// Só mexe se o ambiente pedir. Sem isso, o host da LAN fica como está.
$porta_final = $env_port !== null ? (int) $env_port : (int) $atual['port'];

if ($env_host !== null) {
    // Em LDAPS o esquema vai no host; em 389 o host é o endereço puro.
    $host_final = $porta_final === 636
        ? 'ldaps://' . preg_replace('#^ldaps?://#', '', $env_host)
        : preg_replace('#^ldaps?://#', '', $env_host);

    if ($atual['host'] !== $host_final) {
        printf("~  host: %s -> %s\n", $atual['host'], $host_final);
        $mudancas['host'] = $host_final;
    } else {
        printf("=  host já é %s\n", $host_final);
    }
} else {
    printf(".  host mantido em %s (defina TASKFLOW_LDAP_HOST para mudar)\n", $atual['host']);
}

if ($env_port !== null && (int) $atual['port'] !== $porta_final) {
    printf("~  port: %s -> %d\n", $atual['port'], $porta_final);
    $mudancas['port'] = $porta_final;
} elseif ($env_port !== null) {
    printf("=  port já é %d\n", $porta_final);
}

// STARTTLS só faz sentido na 389. Na 636 o TLS já é implícito pelo esquema.
if (isset($mudancas['port']) || $env_host !== null) {
    $tls_final = 0;
    if ((int) $atual['use_tls'] !== $tls_final) {
        printf("~  use_tls: %s -> %d (TLS vem do esquema ldaps://, não daqui)\n", $atual['use_tls'], $tls_final);
        $mudancas['use_tls'] = $tls_final;
    }
}

// --- 3. campo de sincronização ----------------------------------------------
if (empty($atual['sync_field'])) {
    printf("~  sync_field: (vazio) -> objectguid\n");
    printf("     evita que renomear alguém no AD gere usuário duplicado\n");
    $mudancas['sync_field'] = 'objectguid';
} else {
    printf("=  sync_field já definido (%s)\n", $atual['sync_field']);
}

// --- 4. senha do bind --------------------------------------------------------
// Passa pelo update() do GLPI para que ele cuide da criptografia do campo.
if ($env_pass !== false && $env_pass !== '') {
    if (empty($atual['rootdn_passwd'])) {
        printf("+  senha do bind (vinda do ambiente)\n");
    } else {
        printf("~  senha do bind substituída pela do ambiente\n");
    }
    $mudancas['rootdn_passwd'] = $env_pass;
} elseif (empty($atual['rootdn_passwd'])) {
    printf("!  senha do bind não definida, e TASKFLOW_LDAP_PASSWORD não veio\n");
    printf("   o bind vai falhar até ela existir\n");
} else {
    printf("=  senha do bind já definida\n");
}

// --- aplica ------------------------------------------------------------------
if ($mudancas === []) {
    printf("\nNada a fazer.\n");
} elseif ($dry_run) {
    // A senha nunca aparece, nem na simulação.
    $campos = array_keys($mudancas);
    printf("\nSimulação: %d campo(s) seriam gravados: %s\n", count($campos), implode(', ', $campos));
} else {
    if (!$ldap->update(['id' => $id] + $mudancas)) {
        printf("\n!  falhou ao gravar\n");
        exit(1);
    }
    printf("\nConcluído: %d campo(s) gravados.\n", count($mudancas));
}

// --- teste opcional ----------------------------------------------------------
if ($testar) {
    printf("\nTestando a conexão...\n");
    if ($dry_run) {
        printf(".  --dry-run: o teste usaria a configuração ainda não gravada\n");
    }
    if (AuthLDAP::testLDAPConnection($id)) {
        printf("=  conexão bem-sucedida\n");
    } else {
        printf("!  falhou — confira, nesta ordem: o túnel está de pé? a porta\n");
        printf("   responde? a senha do bind está correta?\n");
        exit(1);
    }
}
