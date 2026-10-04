<?php

/**
 * TaskFlow — equipe: grupo, contas, perfis e vinculos.
 *
 * Cria o grupo tecnico, as contas que faltarem, os perfis de cada pessoa e a
 * associacao ao grupo. Idempotente: identifica o grupo pelo nome e cada conta
 * pelo login; reexecutar acrescenta o que falta em vez de duplicar. Nunca
 * remove perfil nem vinculo — retirar acesso e decisao manual, nao efeito
 * colateral de reexecucao.
 *
 * NAO define senha. Ver o bloco "Autenticacao" no fim deste comentario.
 *
 * Uso (dentro do container da aplicacao):
 *   php tools/taskflow_seed_support_team.php                    # aplica tudo
 *   php tools/taskflow_seed_support_team.php --dry-run          # so mostra
 *   php tools/taskflow_seed_support_team.php --only=renato.feijo
 *
 * `--only` existe porque a ordem importa: a conta de quem administra pode ser
 * ajustada ja, mas as contas novas dependem de SMTP funcionando para o dono
 * definir a propria senha.
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
 * As pessoas, com os perfis de cada uma.
 *
 * `login` sai da parte local do e-mail, convencao que as contas desta
 * instalacao ja seguem. `firstname` / `realname` na convencao do GLPI:
 * nome(s) de tratamento no primeiro campo, sobrenomes no segundo.
 *
 * `perfis`: o primeiro da lista vira o perfil padrao ao entrar.
 *   Admin       — atende, fecha, encaminha, manda para a lixeira, e parametriza
 *                 modulos, locais, regras, grupos e usuarios.
 *   Super-Admin — o acima, mais Configuracao > Geral (SMTP, mascaras, tema).
 *   Self-Service— o portal do usuario final, para ver o produto pelo olho de
 *                 quem abre chamado.
 *
 * `grupo`: se entra no grupo que recebe os chamados. Os tres analistas sim; a
 * conta que administra fica fora, para a fila do grupo nao virar a caixa de
 * entrada de quem nao esta na escala. Isso nao limita nada — Admin e
 * Super-Admin leem e agem em qualquer chamado (READALL + UPDATE + ASSIGN).
 */
const PESSOAS = [
    [
        'login'     => 'renato.feijo',
        'firstname' => 'Renato',
        'realname'  => 'Feijó dos Santos',
        'email'     => 'renato.feijo@softplan.com.br',
        'perfis'    => ['Super-Admin', 'Self-Service'],
        'grupo'     => false,
    ],
    [
        'login'     => 'aluisio.bulhoes',
        'firstname' => 'Aluisio Tadeu',
        'realname'  => 'Alves Bulhões',
        'email'     => 'aluisio.bulhoes@softplan.com.br',
        'perfis'    => ['Admin'],
        'grupo'     => true,
    ],
    [
        'login'     => 'mario.filho',
        'firstname' => 'Mario Jorge',
        'realname'  => 'Alves Nogueira Filho',
        'email'     => 'mario.filho@softplan.com.br',
        'perfis'    => ['Admin'],
        'grupo'     => true,
    ],
    [
        'login'     => 'felype.silva',
        'firstname' => 'Felype Gabriel',
        'realname'  => 'Carneiro da Silva',
        'email'     => 'felype.silva@softplan.com.br',
        'perfis'    => ['Admin'],
        'grupo'     => true,
    ],
];

/** Entidade raiz, com herança para as filhas. */
const ENTITIES_ID  = 0;
const IS_RECURSIVE = 1;

require dirname(__DIR__) . '/vendor/autoload.php';

$kernel = new Kernel();
$kernel->boot();

$dry_run = in_array('--dry-run', $argv, true);

$only = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--only=')) {
        $only = substr($arg, 7);
    }
}

/** Resolve o perfil pelo nome e garante que ele existe. */
function perfil_id(string $nome): int
{
    static $cache = [];
    if (isset($cache[$nome])) {
        return $cache[$nome];
    }
    $p = new Profile();
    if (!$p->getFromDBByCrit(['name' => $nome])) {
        printf("!  perfil '%s' não encontrado\n", $nome);
        exit(1);
    }
    return $cache[$nome] = (int) $p->fields['id'];
}

/** O perfil permite receber chamado? Só faz sentido exigir de quem atende. */
function pode_atender(int $profiles_id): bool
{
    $pr = new ProfileRight();
    $achado = $pr->find(['profiles_id' => $profiles_id, 'name' => 'ticket']);
    $rights = (int) (($achado ? reset($achado) : [])['rights'] ?? 0);
    return ($rights & Ticket::OWN) > 0;
}

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

// --- pessoas -----------------------------------------------------------------
$criadas   = 0;
$ajustadas = 0;
$intactas  = 0;

foreach (PESSOAS as $pessoa) {
    if ($only !== null && $pessoa['login'] !== $only) {
        continue;
    }

    printf("\n%s (%s %s)\n", $pessoa['login'], $pessoa['firstname'], $pessoa['realname']);

    $perfis = array_map('perfil_id', $pessoa['perfis']);
    $padrao = $perfis[0];

    if ($pessoa['grupo'] && !pode_atender($padrao)) {
        printf("!  perfil '%s' não permite receber chamado (falta Ticket::OWN),\n", $pessoa['perfis'][0]);
        printf("   e esta pessoa entra no grupo que recebe os chamados\n");
        exit(1);
    }

    $user = new User();
    $existe = $user->getFromDBbyName($pessoa['login']);
    $mudou = false;

    if (!$existe) {
        printf("   + conta nova\n");
        $criadas++;

        if ($dry_run) {
            printf("   + perfis: %s\n", implode(', ', $pessoa['perfis']));
            if ($pessoa['grupo']) { printf("   + no grupo '%s'\n", GRUPO); }
            printf("   + e-mail %s\n", $pessoa['email']);
            continue;
        }

        $users_id = (int) $user->add([
            'name'         => $pessoa['login'],
            'firstname'    => $pessoa['firstname'],
            'realname'     => $pessoa['realname'],
            'entities_id'  => ENTITIES_ID,
            'profiles_id'  => $padrao,
            'is_active'    => 1,
            'authtype'     => Auth::DB_GLPI,
            '_useremails'  => [-1 => $pessoa['email']],
        ]);

        if ($users_id <= 0) {
            printf("!  falhou ao criar '%s'\n", $pessoa['login']);
            exit(1);
        }
    } else {
        $users_id = (int) $user->fields['id'];
        printf("   = conta existe (id %d)\n", $users_id);
    }

    if ($dry_run && !$existe) {
        continue;
    }

    // --- perfis --------------------------------------------------------------
    $pu = new Profile_User();
    foreach ($perfis as $i => $pid) {
        $tem = $pu->find([
            'users_id'    => $users_id,
            'profiles_id' => $pid,
            'entities_id' => ENTITIES_ID,
        ]);
        if ($tem !== []) {
            printf("   = perfil %s\n", $pessoa['perfis'][$i]);
            continue;
        }
        printf("   + perfil %s%s\n", $pessoa['perfis'][$i], $i === 0 ? ' (padrão)' : '');
        $mudou = true;
        if (!$dry_run) {
            $pu->add([
                'users_id'           => $users_id,
                'profiles_id'        => $pid,
                'entities_id'        => ENTITIES_ID,
                'is_recursive'       => IS_RECURSIVE,
                'is_default_profile' => $i === 0 ? 1 : 0,
            ]);
        }
    }

    // Perfis que a pessoa tem e nao estao na lista: reportados, nao removidos.
    foreach ($pu->find(['users_id' => $users_id]) as $link) {
        if (!in_array((int) $link['profiles_id'], $perfis, true)) {
            $p = new Profile();
            $p->getFromDB($link['profiles_id']);
            printf("   . também tem '%s' (fora da lista; não removido)\n", $p->fields['name'] ?? '?');
        }
    }

    // --- grupo ---------------------------------------------------------------
    if ($pessoa['grupo']) {
        $gu = new Group_User();
        if ($gu->find(['users_id' => $users_id, 'groups_id' => $groups_id]) === []) {
            printf("   + no grupo '%s'\n", GRUPO);
            $mudou = true;
            if (!$dry_run) {
                $gu->add(['users_id' => $users_id, 'groups_id' => $groups_id]);
            }
        } else {
            printf("   = no grupo '%s'\n", GRUPO);
        }
    }

    // --- e-mail --------------------------------------------------------------
    $ue = new UserEmail();
    if ($ue->find(['users_id' => $users_id, 'email' => $pessoa['email']]) === []) {
        printf("   + e-mail %s\n", $pessoa['email']);
        $mudou = true;
        if (!$dry_run) {
            $ue->add(['users_id' => $users_id, 'email' => $pessoa['email'], 'is_default' => 1]);
        }
    } else {
        printf("   = e-mail %s\n", $pessoa['email']);
    }

    // --- senha ---------------------------------------------------------------
    if (!$dry_run) {
        $u2 = new User();
        $u2->getFromDB($users_id);
        if (empty($u2->fields['password'])) {
            printf("   ! sem senha — não entra até alguém definir, ou até o SMTP\n");
            printf("     permitir o \"esqueci minha senha\"\n");
        }
    }

    if ($existe && !$mudou) {
        $intactas++;
    } elseif ($existe) {
        $ajustadas++;
    }
}

printf(
    "\n%s: %d conta(s) criada(s), %d ajustada(s), %d sem mudança\n",
    $dry_run ? 'Simulação' : 'Concluído',
    $criadas,
    $ajustadas,
    $intactas
);
