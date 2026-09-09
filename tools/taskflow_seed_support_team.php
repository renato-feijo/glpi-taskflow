<?php

/**
 * TaskFlow — equipe de atendimento N1: grupo, contas e vinculos.
 *
 * Cria o grupo tecnico, as contas dos analistas, o perfil de cada um e a
 * associacao ao grupo. Idempotente: identifica o grupo pelo nome e cada conta
 * pelo login; reexecutar corrige perfil e vinculo em vez de duplicar.
 *
 * NAO define senha. Ver o bloco "Autenticacao" no fim deste comentario.
 *
 * Uso (dentro do container da aplicacao):
 *   php tools/taskflow_seed_support_team.php            # aplica
 *   php tools/taskflow_seed_support_team.php --dry-run  # so mostra
 *
 * Autenticacao: as contas nascem sem senha e, com SMTP desligado nesta
 * instalacao, o "esqueci minha senha" nao funciona — nao ha como o proprio
 * analista definir a dele. Depois de rodar, alguem com perfil Super-Admin
 * precisa definir a senha de cada um em Administracao > Usuarios, ou a
 * instalacao precisa de SMTP configurado. Senha nao entra em script
 * versionado.
 */

use Glpi\Kernel\Kernel;

if (PHP_SAPI !== 'cli') {
    echo "Este script roda só pela linha de comando\n";
    exit(1);
}

/** O grupo que recebe os chamados. Precisa casar com o do seeder de regras. */
const GRUPO = 'Suporte N1';

/**
 * Os analistas. `login` sai da parte local do e-mail, que e a convencao que
 * as contas existentes desta instalacao já seguem (renato.feijo).
 *
 * `firstname` / `realname` na convencao do GLPI: nome(s) de tratamento no
 * primeiro campo, sobrenomes no segundo.
 */
const ANALISTAS = [
    [
        'login'     => 'aluisio.bulhoes',
        'firstname' => 'Aluisio Tadeu',
        'realname'  => 'Alves Bulhões',
        'email'     => 'aluisio.bulhoes@softplan.com.br',
    ],
    [
        'login'     => 'mario.filho',
        'firstname' => 'Mario Jorge',
        'realname'  => 'Alves Nogueira Filho',
        'email'     => 'mario.filho@softplan.com.br',
    ],
    [
        'login'     => 'felype.silva',
        'firstname' => 'Felype Gabriel',
        'realname'  => 'Carneiro da Silva',
        'email'     => 'felype.silva@softplan.com.br',
    ],
];

/** Perfil dos analistas. 'Technician' é o perfil de atendimento do GLPI. */
const PERFIL = 'Technician';

/** Entidade raiz, com herança para as filhas. */
const ENTITIES_ID  = 0;
const IS_RECURSIVE = 1;

require dirname(__DIR__) . '/vendor/autoload.php';

$kernel = new Kernel();
$kernel->boot();

$dry_run = in_array('--dry-run', $argv, true);

// --- perfil ------------------------------------------------------------------
$profile = new Profile();
if (!$profile->getFromDBByCrit(['name' => PERFIL])) {
    printf("!  perfil '%s' não encontrado\n", PERFIL);
    exit(1);
}
$profiles_id = (int) $profile->fields['id'];

// Um perfil sem o direito de "ser atribuído" faria o chamado cair em alguém
// que não abre a interface central — silencioso e chato de descobrir depois.
$pr = new ProfileRight();
$achado = $pr->find(['profiles_id' => $profiles_id, 'name' => 'ticket']);
$rights = (int) (($achado ? reset($achado) : [])['rights'] ?? 0);
if (!($rights & Ticket::OWN)) {
    printf("!  perfil '%s' não permite receber chamado (falta Ticket::OWN)\n", PERFIL);
    exit(1);
}
printf("perfil: [%d] %s (pode receber chamado)\n", $profiles_id, PERFIL);

// --- grupo -------------------------------------------------------------------
$group = new Group();
$achado = $group->find(['name' => GRUPO, 'entities_id' => ENTITIES_ID]);

if ($achado !== []) {
    $groups_id = (int) reset($achado)['id'];
    printf("=  grupo '%s' já existe (id %d)\n", GRUPO, $groups_id);
} else {
    printf("+  grupo '%s'\n", GRUPO);
    $groups_id = -1;
    if (!$dry_run) {
        $groups_id = (int) $group->add([
            'name'         => GRUPO,
            'comment'      => 'Atendimento N1 dos módulos do DER/PE.',
            'entities_id'  => ENTITIES_ID,
            'is_recursive' => IS_RECURSIVE,
            'is_assign'    => 1,   // pode receber chamado
            'is_requester' => 1,
        ]);
        if ($groups_id <= 0) {
            printf("!  falhou ao criar o grupo\n");
            exit(1);
        }
    }
}

// --- analistas ---------------------------------------------------------------
$criados = 0;
$ajustados = 0;
$inalterados = 0;

foreach (ANALISTAS as $a) {
    $user = new User();
    $existe = $user->getFromDBbyName($a['login']);

    if (!$existe) {
        printf("+  conta '%s' (%s %s)\n", $a['login'], $a['firstname'], $a['realname']);
        $criados++;

        if ($dry_run) {
            continue;
        }

        $users_id = (int) $user->add([
            'name'         => $a['login'],
            'firstname'    => $a['firstname'],
            'realname'     => $a['realname'],
            'entities_id'  => ENTITIES_ID,
            'profiles_id'  => $profiles_id,
            'is_active'    => 1,
            'authtype'     => Auth::DB_GLPI,
            '_useremails'  => [-1 => $a['email']],
            'comment'      => 'Analista de atendimento N1.',
        ]);

        if ($users_id <= 0) {
            printf("!  falhou ao criar '%s'\n", $a['login']);
            exit(1);
        }
    } else {
        $users_id = (int) $user->fields['id'];
        printf("=  conta '%s' já existe (id %d)\n", $a['login'], $users_id);
        $inalterados++;
    }

    if ($dry_run) {
        continue;
    }

    // --- perfil na entidade raiz ---------------------------------------------
    $pu = new Profile_User();
    $tem_perfil = $pu->find([
        'users_id'    => $users_id,
        'profiles_id' => $profiles_id,
        'entities_id' => ENTITIES_ID,
    ]);
    if ($tem_perfil === []) {
        $pu->add([
            'users_id'     => $users_id,
            'profiles_id'  => $profiles_id,
            'entities_id'  => ENTITIES_ID,
            'is_recursive' => IS_RECURSIVE,
            'is_default_profile' => 1,
        ]);
        printf("   + perfil %s\n", PERFIL);
        $ajustados++;
    }

    // --- associação ao grupo -------------------------------------------------
    $gu = new Group_User();
    $no_grupo = $gu->find(['users_id' => $users_id, 'groups_id' => $groups_id]);
    if ($no_grupo === []) {
        $gu->add(['users_id' => $users_id, 'groups_id' => $groups_id]);
        printf("   + no grupo '%s'\n", GRUPO);
        $ajustados++;
    }

    // --- e-mail --------------------------------------------------------------
    $ue = new UserEmail();
    $tem_email = $ue->find(['users_id' => $users_id, 'email' => $a['email']]);
    if ($tem_email === []) {
        $ue->add(['users_id' => $users_id, 'email' => $a['email'], 'is_default' => 1]);
        printf("   + e-mail %s\n", $a['email']);
        $ajustados++;
    }

    // --- senha ---------------------------------------------------------------
    // Conta sem senha nao autentica. Com SMTP desligado, o proprio analista
    // tambem nao consegue definir a dele pelo "esqueci minha senha".
    $u2 = new User();
    $u2->getFromDB($users_id);
    if (empty($u2->fields['password'])) {
        printf("   ! sem senha definida — não conseguirá entrar até alguém definir\n");
    }
}

printf(
    "\n%s: %d conta(s) criada(s), %d vínculo(s) ajustado(s), %d já existia(m)\n",
    $dry_run ? 'Simulação' : 'Concluído',
    $criados,
    $ajustados,
    $inalterados
);

if (!$dry_run) {
    printf("\nFalta, e não sai de script: definir a senha de cada conta em\n");
    printf("Administração > Usuários, ou configurar SMTP para o fluxo de\n");
    printf("recuperação de senha funcionar.\n");
}
