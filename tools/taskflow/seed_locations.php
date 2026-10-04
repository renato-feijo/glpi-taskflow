<?php

/**
 * TaskFlow — cria/atualiza a árvore de localizações (Órgão > Diretoria > Setor).
 *
 * Idempotente: cada nó é identificado por (entidade, pai, nome), que é a chave
 * única `unicity` da tabela glpi_locations. Rodar de novo só preenche o que
 * falta; nunca duplica.
 *
 * Uso (dentro do container da aplicação):
 *   php tools/taskflow_seed_locations.php            # aplica
 *   php tools/taskflow_seed_locations.php --dry-run  # só mostra o que faria
 *
 * Usa Location::add() em vez de SQL direto para que o GLPI mantenha
 * `completename`, `level` e os caches da árvore consistentes.
 */

use Glpi\Kernel\Kernel;

if (PHP_SAPI !== 'cli') {
    echo "Este script roda só pela linha de comando\n";
    exit(1);
}

/**
 * A árvore de localizações, aninhada livremente.
 *
 * Cada chave é o nome do nó; o valor é o array de filhos (`[]` numa folha).
 * A profundidade não é fixa — basta aninhar mais um nível.
 *
 * Transcrita do organograma oficial do DER/PE (export draw.io de 09/2025).
 * Fora da árvore, de propósito: cargos (DAS/FGS/FDA/CAA) e nomes de pessoas,
 * que não são lugares — a atribuição de técnico sai por regra de negócio.
 * Distritos 1 e 6 aparecem no organograma com duas caixas idênticas de
 * "Unidade de Apoio Técnico" (duas vagas, não dois lugares): viram uma só,
 * já que o índice `unicity` da tabela é (entidade, pai, nome). O Distrito 3
 * não tem nenhuma, como no original.
 */
const TREE = [
    'Presidência - DPR' => [
        'Gerência de Planejamento' => [],
        'Núcleo de Comunicação' => [
            'Unidade de Comunicação' => [],
        ],
        'Secretaria da Presidência - SPR' => [],
        'Gabinete - GAB' => [],
        'Núcleo de Orçamento' => [],
        'Superintendência de Integridade' => [],
        'Ouvidoria - OUV' => [],
        'Assessoria Especial de Controle Interno' => [],
        'Assessoria Técnica de Transporte' => [],
    ],
    'Diretoria Adjunta - DAJ' => [
        'Apoio Técnico da Diretoria Adjunta' => [],
        'Núcleo de TI' => [
            'Coordenação de Suporte' => [],
            'Coordenação de Contratos e Infra de TI' => [],
            'Unidade de Infraestrutura' => [],
            'Unidade de Administração de Sistemas' => [],
        ],
    ],
    'Diretoria de Estudos e Projetos' => [
        'Unidade de Apoio' => [],
        'Consultoria Técnica de Planejamento de Projetos' => [],
        'Superintendência de Projetos' => [
            'Unidade de Apoio' => [],
            'Gerência de Projetos' => [
                'Unidade de Estudos e Projetos' => [],
                'Unidade de Orçamento' => [],
            ],
        ],
    ],
    'Diretoria Jurídica - DJU' => [
        'Unidade de Apoio Legal' => [],
        'Unidade de Contratos' => [],
    ],
    'Diretoria de Engenharia - DEG' => [
        'Unidade de Apoio Administrativo' => [],
        'Núcleo Técnico de Engenharia' => [],
        'Diretoria Executiva de Obras' => [
            'Consultoria de Obras Rod - Agreste' => [],
            'Consultoria de Obras Rod - Norte' => [],
            'Superintendência de Obras Rod - Sul' => [],
            'Consultoria de Obras Rod - Sertão' => [],
            'Superintendência de Meio Ambiente e Desapropriação' => [
                'Unidade de Meio Ambiente' => [],
                'Unidade de Desapropriação' => [],
            ],
        ],
        'Diretoria Executiva de Conservação' => [],
        'Diretoria Executiva de Contratos e Medição' => [],
        'Superintendência de Distritos Rodoviários' => [
            'Gestão Técnica de Distritos Rodoviários' => [],
            'Núcleo Técnico do Distrito 1' => [
                'Unidade de Apoio Técnico' => [],
            ],
            'Núcleo Técnico do Distrito 2' => [
                'Unidade de Apoio Técnico' => [],
            ],
            'Núcleo Técnico do Distrito 3' => [],
            'Núcleo Técnico do Distrito 4' => [
                'Unidade de Apoio Técnico' => [],
            ],
            'Núcleo Técnico do Distrito 5' => [
                'Unidade de Apoio Técnico' => [],
            ],
            'Núcleo Técnico do Distrito 6' => [
                'Unidade de Apoio Técnico' => [],
            ],
            'Núcleo Técnico do Distrito 7' => [
                'Unidade de Apoio Técnico' => [],
            ],
            'Núcleo Técnico do Distrito 8' => [
                'Unidade de Apoio Técnico' => [],
            ],
        ],
    ],
    'Diretoria Administrativa Financeira - DAF' => [
        'Gerência Financeira - GFIN' => [
            'Unidade de Finanças' => [],
            'Unidade de Contabilidade' => [],
        ],
        'Gerência de Gestão de Pessoas' => [
            'Unidade de Legislação e Cadastro' => [],
            'Unidade de Pagamento' => [],
            'Unidade de Desenvolvimento e Assistência Social' => [],
        ],
        'Gerência Técnica ADM' => [
            'Unidade de Materiais' => [],
            'Unidade de Patrimônio e Serviços' => [],
            'Unidade de Documentação' => [],
            'Unidade de Frota' => [],
        ],
    ],
    'Diretoria de Trânsito e Faixa de Domínio' => [
        'Gerência de Contratos' => [
            'Assessoria Técnica de Contratos' => [],
        ],
        'Núcleo de Operações e Fiscalização de Trânsito' => [
            'Unidade de Fiscalização e Vistoria' => [],
            'Unidade de Educação para o Trânsito' => [],
        ],
        'Gerência de Processamento e Recursos de Trânsito' => [
            'Unidade de Implantação' => [],
            'Unidade de Coordenação JARI' => [],
            'Unidade de Julgamento de Defesa da Autuação - UJDA' => [],
            'Unidade de Instrução de Processo - UIPR' => [],
            'Unidade de Atendimento' => [],
        ],
        'Gerência de Faixa de Domínio' => [],
        'Gerência de Videomonitoramento e Fiscalização de Trânsito' => [],
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
        $location = new Location();
        $full     = $path === '' ? $name : $path . ' > ' . $name;

        $existing = $location->find([
            'name'         => $name,
            'locations_id' => $parent_id,
            'entities_id'  => ENTITIES_ID,
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

            $id = $location->add([
                'name'         => $name,
                'locations_id' => $parent_id,
                'entities_id'  => ENTITIES_ID,
                'is_recursive' => IS_RECURSIVE,
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
