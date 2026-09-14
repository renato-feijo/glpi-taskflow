<?php

/**
 * TaskFlow — importa a árvore de categorias do sistema SIDER.
 *
 * Idempotente: cada nó é identificado por (entidade, pai, nome), a chave
 * `unicity` de `glpi_itilcategories` (CommonTreeDropdown). Rodar de novo só
 * preenche o que falta; nunca duplica.
 *
 * Uso (dentro do container da aplicação):
 *   php tools/taskflow_import_sider_categories.php            # aplica
 *   php tools/taskflow_import_sider_categories.php --dry-run  # só mostra
 *
 * Origem: export de `glpi_itilcategories` do GLPI legado do DER/PE
 * (2026-09-14). Só o ramo "SIDER" — o restante da árvore exportada (GTI,
 * N1/N2/N3, módulos) já existe nesta instalação por outro caminho (10
 * módulos semeados como ITILCategories, ver TASKFLOW.md).
 *
 * Usa ITILCategory::add() em vez de SQL direto para que o GLPI mantenha
 * `completename`, `level` e os caches da árvore consistentes.
 */

use Glpi\Kernel\Kernel;

if (PHP_SAPI !== 'cli') {
    echo "Este script roda só pela linha de comando\n";
    exit(1);
}

/**
 * A árvore de categorias do SIDER, aninhada livremente.
 *
 * Transcrita do export de produção do GLPI legado do DER/PE (79 categorias
 * no total; aqui só o ramo cuja raiz é "SIDER", 25 nós). Sem comentários nem
 * código no export original para este ramo.
 */
const TREE = [
    'SIDER' => [
        'Acesso' => [
            'Bloqueio de Acesso' => [],
            'Cadastrar Usuário | Externos (RT)' => [],
            'Cadastrar Usuário | Internos' => [],
            'Login Falhando' => [],
            'Outros' => [],
        ],
        'Cadastros e Dados' => [
            'Campo Bloqueado ou Não Exibido' => [],
            'Erro ao Salvar ou Validar Dados' => [],
            'Exclusão de Medição/Pagamento' => [],
            'Outros' => [],
        ],
        'Melhorias' => [
            'Nova Funcionalidade / Recurso' => [],
        ],
        'Operacional / Regras de Negócio' => [
            'Ajuste à Legislação/Norma' => [],
            'Erro em Cálculos ou Valores' => [],
        ],
        'Relatórios e BI' => [
            'Ajuste e Extração de Relatórios' => [],
            'Criação de Relatórios' => [],
        ],
        'Sistema' => [
            'Sistema Indisponível/Inacessível' => [],
        ],
        'Suporte e Uso' => [
            'Dúvidas e Orientação' => [],
        ],
        'Triagem' => [],
    ],
];

/** Entidade raiz, com herança para as filhas. */
const ENTITIES_ID  = 0;
const IS_RECURSIVE = 1;

require dirname(__DIR__) . '/vendor/autoload.php';

$kernel = new Kernel();
$kernel->boot();

$dry_run = in_array('--dry-run', $argv, true);

$created = 0;
$skipped = 0;

/** Ids sintéticos para o dry-run poder descer a árvore sem gravar nada. */
$fake_id = 0;

/**
 * Percorre a árvore em profundidade, criando cada nó sob o pai já resolvido.
 *
 * @param array<string, array> $nodes
 */
$walk = function (array $nodes, int $parent_id, string $path) use (&$walk, $dry_run, &$created, &$skipped, &$fake_id): void {
    foreach ($nodes as $name => $children) {
        $category = new ITILCategory();
        $full     = $path === '' ? $name : $path . ' > ' . $name;

        $existing = $category->find([
            'name'              => $name,
            'itilcategories_id' => $parent_id,
            'entities_id'       => ENTITIES_ID,
        ]);

        if (count($existing) > 0) {
            $id = (int) reset($existing)['id'];
            printf("=  %s (id %d)\n", $full, $id);
            $skipped++;
        } else {
            printf("+  %s\n", $full);
            $created++;

            if ($dry_run) {
                // O nó não existe, então não há id real para pendurar os filhos.
                // Um id sintético deixa a simulação mostrar a árvore inteira.
                $id = --$fake_id;
                $walk($children, $id, $full);
                continue;
            }

            $id = $category->add([
                'name'                        => $name,
                'itilcategories_id'           => $parent_id,
                'entities_id'                 => ENTITIES_ID,
                'is_recursive'                => IS_RECURSIVE,
                'is_helpdeskvisible'          => 1,
                'is_incident'                 => 1,
                'is_request'                  => 1,
                'is_problem'                  => 1,
                'is_change'                   => 1,
            ]);

            if ($id === false) {
                printf("!  falhou ao criar %s\n", $full);
                exit(1);
            }
            $id = (int) $id;
        }

        if ($children !== []) {
            $walk($children, $id, $full);
        }
    }
};

$walk(TREE, 0, '');

printf(
    "\n%s: %d criados, %d já existiam\n",
    $dry_run ? 'Simulação' : 'Concluído',
    $created,
    $skipped
);
