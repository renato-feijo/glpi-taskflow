<?php

/**
 * TaskFlow — desativa as contas de demonstracao do GLPI.
 *
 * `post-only`, `tech` e `normal` vem na instalacao padrao do GLPI com senhas
 * publicadas na documentacao. Nesta instalacao, exposta na internet, isso e
 * um caminho de entrada aberto.
 *
 * Desativa, e nao apaga: `is_active = 0` e reversivel num clique, e apagar
 * levaria embora a referencia dessas contas em chamados historicos.
 *
 * Idempotente: contas ja desativadas sao reportadas e nao tocadas.
 *
 * Uso (dentro do container da aplicacao):
 *   php tools/taskflow_disable_demo_accounts.php            # aplica
 *   php tools/taskflow_disable_demo_accounts.php --dry-run  # so mostra
 */

use Glpi\Kernel\Kernel;

if (PHP_SAPI !== 'cli') {
    echo "Este script roda só pela linha de comando\n";
    exit(1);
}

/** Contas de demonstracao do GLPI, com a senha que vem de fabrica. */
const DEMO = [
    'post-only' => 'postonly',
    'tech'      => 'tech',
    'normal'    => 'normal',
];

/**
 * Contas que este script nunca toca, so audita.
 *
 * `glpi` e a conta Super-Admin em uso: desativa-la trancaria a administracao
 * fora do produto.
 */
const AUDITAR = [
    'glpi' => 'glpi',
];

require dirname(__DIR__) . '/vendor/autoload.php';

$kernel = new Kernel();
$kernel->boot();

$dry_run = in_array('--dry-run', $argv, true);

$desativadas = 0;
$ja_estavam  = 0;

foreach (DEMO as $login => $senha_padrao) {
    $user = new User();
    if (!$user->getFromDBbyName($login)) {
        printf("-  '%s' não existe nesta instalação\n", $login);
        continue;
    }

    $id = (int) $user->fields['id'];

    // Vale saber se a senha ainda é a de fábrica: se já foi trocada, a conta
    // deixa de ser um caminho de entrada conhecido, mesmo ativa.
    $padrao = Auth::checkPassword($senha_padrao, $user->fields['password']);

    if (!$user->fields['is_active']) {
        printf("=  '%s' (id %d) já está desativada%s\n",
            $login, $id, $padrao ? ' — senha ainda é a de fábrica' : '');
        $ja_estavam++;
        continue;
    }

    printf("~  '%s' (id %d) desativar%s\n",
        $login, $id, $padrao ? ' — senha É a de fábrica' : ' — senha já foi trocada');
    $desativadas++;

    if ($dry_run) {
        continue;
    }

    if (!$user->update(['id' => $id, 'is_active' => 0])) {
        printf("!  falhou ao desativar '%s'\n", $login);
        exit(1);
    }
}

printf("\n--- auditoria (nenhuma alteração) ---\n");
foreach (AUDITAR as $login => $senha_padrao) {
    $user = new User();
    if (!$user->getFromDBbyName($login)) {
        continue;
    }
    $padrao = Auth::checkPassword($senha_padrao, $user->fields['password']);
    printf("%s: ativa=%s, senha de fábrica=%s%s\n",
        $login,
        $user->fields['is_active'] ? 'sim' : 'nao',
        $padrao ? 'SIM' : 'nao',
        $padrao ? '  <- trocar' : '');
}

printf(
    "\n%s: %d desativada(s), %d já estava(m)\n",
    $dry_run ? 'Simulação' : 'Concluído',
    $desativadas,
    $ja_estavam
);
