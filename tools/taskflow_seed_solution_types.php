<?php

/**
 * TaskFlow — tipos de solucao.
 *
 * O GLPI nao tem status de cancelamento: os status sao Novo, Aprovacao, Em
 * atendimento (atribuido e planejado), Pendente, Solucionado e Fechado, e nao
 * existe constante de cancelamento no codigo. Cancelar um chamado, na
 * convencao do GLPI, e fecha-lo com um tipo de solucao que diga isso.
 *
 * A alternativa seria a lixeira, mas ela tira o chamado das estatisticas —
 * ruim justamente para medir quanta demanda e cancelada e por que.
 *
 * Como os tipos valem por tipo de chamado (is_incident, is_request,
 * is_problem, is_change), cada entrada declara onde aparece. Os modulos do
 * DER/PE atendem requisicao e incidente, o mesmo recorte das categorias.
 *
 * Idempotente: identifica pelo nome e corrige as flags que divergirem.
 *
 * Uso (dentro do container da aplicacao):
 *   php tools/taskflow_seed_solution_types.php            # aplica
 *   php tools/taskflow_seed_solution_types.php --dry-run  # so mostra
 */

use Glpi\Kernel\Kernel;

if (PHP_SAPI !== 'cli') {
    echo "Este script roda só pela linha de comando\n";
    exit(1);
}

const TIPOS = [
    [
        'name'    => 'Cancelado',
        'comment' => 'Chamado encerrado sem atendimento: aberto por engano, '
                   . 'desistência do solicitante ou fora do escopo do suporte N1.',
    ],
];

/** Entidade raiz, com herança para as filhas. */
const ENTITIES_ID  = 0;
const IS_RECURSIVE = 1;

require dirname(__DIR__) . '/vendor/autoload.php';

$kernel = new Kernel();
$kernel->boot();

$dry_run = in_array('--dry-run', $argv, true);

$criados     = 0;
$ajustados   = 0;
$inalterados = 0;

foreach (TIPOS as $tipo) {
    $st = new SolutionType();
    $achado = $st->find(['name' => $tipo['name'], 'entities_id' => ENTITIES_ID]);

    $campos = [
        'name'         => $tipo['name'],
        'comment'      => $tipo['comment'],
        'entities_id'  => ENTITIES_ID,
        'is_recursive' => IS_RECURSIVE,
        'is_request'   => 1,
        'is_incident'  => 1,
        'is_problem'   => 0,   // mesmo recorte das categorias de módulo
        'is_change'    => 0,
    ];

    if ($achado !== []) {
        $atual = reset($achado);
        $diff = [];
        foreach ($campos as $k => $v) {
            if ((string) ($atual[$k] ?? '') !== (string) $v) {
                $diff[$k] = $v;
            }
        }

        if ($diff === []) {
            printf("=  '%s' já está como esperado (id %d)\n", $tipo['name'], $atual['id']);
            $inalterados++;
            continue;
        }

        printf("~  '%s' (id %d): %s\n", $tipo['name'], $atual['id'], implode(', ', array_keys($diff)));
        $ajustados++;

        if (!$dry_run && !$st->update(['id' => $atual['id']] + $diff)) {
            printf("!  falhou ao atualizar '%s'\n", $tipo['name']);
            exit(1);
        }
        continue;
    }

    printf("+  '%s'\n", $tipo['name']);
    $criados++;

    if (!$dry_run && $st->add($campos) === false) {
        printf("!  falhou ao criar '%s'\n", $tipo['name']);
        exit(1);
    }
}

printf(
    "\n%s: %d criado(s), %d ajustado(s), %d sem mudança\n",
    $dry_run ? 'Simulação' : 'Concluído',
    $criados,
    $ajustados,
    $inalterados
);
