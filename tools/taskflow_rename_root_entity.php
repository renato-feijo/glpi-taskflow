<?php

/**
 * TaskFlow — renomeia a entidade raiz para "DER/PE".
 *
 * A entidade raiz (id 0) ainda estava com o nome padrão de instalação do
 * GLPI ("Entidade raiz"). A organização já foi modelada via localizações
 * (organograma do DER/PE), mas o nome da entidade em si nunca foi ajustado.
 *
 * Idempotente: só atualiza se o nome/completename divergirem do esperado.
 *
 * Uso (dentro do container da aplicacao):
 *   php tools/taskflow_rename_root_entity.php            # aplica
 *   php tools/taskflow_rename_root_entity.php --dry-run  # so mostra
 */

use Glpi\Kernel\Kernel;

if (PHP_SAPI !== 'cli') {
    echo "Este script roda só pela linha de comando\n";
    exit(1);
}

const ENTITY_ID  = 0;
const NOVO_NOME  = 'DER/PE';

require dirname(__DIR__) . '/vendor/autoload.php';

$kernel = new Kernel();
$kernel->boot();

$dry_run = in_array('--dry-run', $argv, true);

$entity = new Entity();
if (!$entity->getFromDB(ENTITY_ID)) {
    printf("!  entidade id %d não encontrada\n", ENTITY_ID);
    exit(1);
}

$campos = [
    'name'         => NOVO_NOME,
    'completename' => NOVO_NOME,
];

$diff = [];
foreach ($campos as $k => $v) {
    if ((string) ($entity->fields[$k] ?? '') !== (string) $v) {
        $diff[$k] = $v;
    }
}

if ($diff === []) {
    printf("=  entidade id %d já está como '%s'\n", ENTITY_ID, NOVO_NOME);
    exit(0);
}

printf(
    "~  entidade id %d: '%s' -> '%s' (%s)\n",
    ENTITY_ID,
    $entity->fields['name'],
    NOVO_NOME,
    implode(', ', array_keys($diff))
);

if (!$dry_run && !$entity->update(['id' => ENTITY_ID] + $diff)) {
    printf("!  falhou ao atualizar a entidade id %d\n", ENTITY_ID);
    exit(1);
}

printf("\n%s\n", $dry_run ? 'Simulação concluída' : 'Concluído');
