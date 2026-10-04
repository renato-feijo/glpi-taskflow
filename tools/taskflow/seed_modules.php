<?php

/**
 * TaskFlow — cria/atualiza os módulos do DER/PE como categorias de ticket.
 *
 * Idempotente: identifica cada módulo pelo campo `code` (a sigla). Se já
 * existir, atualiza o nome e as flags; se não, cria. Rodar de novo é seguro.
 *
 * Uso (dentro do container da aplicação):
 *   php tools/taskflow_seed_modules.php            # aplica
 *   php tools/taskflow_seed_modules.php --dry-run  # só mostra o que faria
 *
 * Usa ITILCategory::add()/update() em vez de SQL direto para que o GLPI
 * mantenha `completename`, `level` e os caches da árvore consistentes.
 */

use Glpi\Kernel\Kernel;

if (PHP_SAPI !== 'cli') {
    echo "Este script roda só pela linha de comando\n";
    exit(1);
}

/**
 * Os 10 módulos, na ordem em que devem aparecer.
 *
 * 'code' é a sigla (usada como chave de idempotência e disponível para as
 * regras de negócio); 'name' é o rótulo mostrado ao usuário no formulário.
 */
const MODULES = [
    ['code' => 'SCO', 'name' => 'SCO - Sistema de Custos e Orçamento'],
    ['code' => 'SMO', 'name' => 'SMO - Sistema de Medição de Obras'],
    ['code' => 'CQM', 'name' => 'CQM - Controle de Quantitativo de Medições'],
    ['code' => 'SGF', 'name' => 'SGF - Sistema de Gestão Financeira'],
    ['code' => 'AET', 'name' => 'AET - Autorização Especial de Trânsito'],
    ['code' => 'FXD', 'name' => 'FXD - Faixa de Domínio'],
    ['code' => 'OAE', 'name' => 'OAE - Obras de Artes Especiais'],
    ['code' => 'REC', 'name' => 'REC - Gestão de Receitas'],
    ['code' => 'SAM', 'name' => 'SAM - Sistema de Administração da Manutenção'],
    ['code' => 'SAD', 'name' => 'SAD - Sistema de Apropriação e Desapropriação'],
];

/** Entidade raiz, com herança para as filhas. */
const ENTITIES_ID  = 0;
const IS_RECURSIVE = 1;

require dirname(__DIR__) . '/vendor/autoload.php';

$kernel = new Kernel();
$kernel->boot();

$dry_run = in_array('--dry-run', $argv, true);

$created  = 0;
$updated  = 0;
$skipped  = 0;

foreach (MODULES as $module) {
    // Uma instância por módulo: evita que os campos de um add() vazem no próximo.
    $category = new ITILCategory();

    $existing = $category->find([
        'code'       => $module['code'],
        'entities_id' => ENTITIES_ID,
    ]);

    $fields = [
        'name'               => $module['name'],
        'code'               => $module['code'],
        'itilcategories_id'  => 0,   // primeiro nível
        'entities_id'        => ENTITIES_ID,
        'is_recursive'       => IS_RECURSIVE,
        'is_helpdeskvisible' => 1,   // aparece no formulário do usuário final
        'is_request'         => 1,
        'is_incident'        => 1,
        'is_problem'         => 0,   // módulos são para tickets, não problemas
        'is_change'          => 0,
    ];

    if (count($existing) > 0) {
        $current = reset($existing);
        $diff    = [];
        foreach ($fields as $key => $value) {
            if ((string) ($current[$key] ?? '') !== (string) $value) {
                $diff[$key] = $value;
            }
        }

        if ($diff === []) {
            printf("=  %-4s já está como esperado (id %d)\n", $module['code'], $current['id']);
            $skipped++;
            continue;
        }

        printf(
            "~  %-4s atualizar (id %d): %s\n",
            $module['code'],
            $current['id'],
            implode(', ', array_keys($diff))
        );

        if (!$dry_run && !$category->update(['id' => $current['id']] + $diff)) {
            printf("!  %-4s falhou ao atualizar\n", $module['code']);
            exit(1);
        }
        $updated++;
        continue;
    }

    printf("+  %-4s criar (%s)\n", $module['code'], $module['name']);

    if (!$dry_run) {
        $id = $category->add($fields);
        if ($id === false) {
            printf("!  %-4s falhou ao criar\n", $module['code']);
            exit(1);
        }
    }
    $created++;
}

printf(
    "\n%s: %d criados, %d atualizados, %d sem mudança\n",
    $dry_run ? 'Simulação' : 'Concluído',
    $created,
    $updated,
    $skipped
);
