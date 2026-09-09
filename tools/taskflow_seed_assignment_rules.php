<?php

/**
 * TaskFlow — atribuicao automatica de chamado por modulo.
 *
 * Cria uma regra de negocio por modulo: "se a categoria esta sob <MODULO>,
 * atribuir a <grupo ou tecnico>". Cria tambem os grupos que faltarem.
 *
 * Idempotente: identifica a regra pelo nome e o grupo pelo nome; reexecutar
 * corrige criterio e acao divergentes em vez de duplicar.
 *
 * Uso (dentro do container da aplicacao):
 *   php tools/taskflow_seed_assignment_rules.php            # aplica
 *   php tools/taskflow_seed_assignment_rules.php --dry-run  # so mostra
 */

use Glpi\Kernel\Kernel;

if (PHP_SAPI !== 'cli') {
    echo "Este script roda só pela linha de comando\n";
    exit(1);
}

/**
 * Quem atende cada modulo, por sigla (o campo `code` da categoria).
 *
 * Duas formas por entrada:
 *   'SCO' => ['grupo'   => 'Equipe SCO']    grupo tecnico, criado se faltar
 *   'SCO' => ['usuario' => 'joao.silva']    tecnico nominal, login do GLPI
 *
 * Grupo e preferivel a pessoa: sobrevive a ferias, troca de equipe e
 * desligamento sem reescrever regra. Com um tecnico nominal, o modulo dele
 * para de ser roteado no dia em que a conta sai.
 *
 * Modulos ausentes desta lista nao ganham regra — o chamado fica sem
 * atribuicao, para triagem manual.
 *
 * Hoje os dez apontam para o mesmo grupo: os tres analistas N1 atendem todos
 * os modulos. Dez regras identicas parecem redundantes, e sao — a alternativa
 * seria uma regra unica com os dez modulos em OR. Ficaram separadas porque a
 * divergencia e o futuro esperado: no dia em que um modulo ganhar equipe
 * propria, muda-se uma linha aqui e reexecuta. Com a regra unica em OR, o
 * mesmo passo exigiria reestruturar criterio e acao.
 */
const ATRIBUICAO = [
    'SCO' => ['grupo' => 'Suporte N1'],
    'SMO' => ['grupo' => 'Suporte N1'],
    'CQM' => ['grupo' => 'Suporte N1'],
    'SGF' => ['grupo' => 'Suporte N1'],
    'AET' => ['grupo' => 'Suporte N1'],
    'FXD' => ['grupo' => 'Suporte N1'],
    'OAE' => ['grupo' => 'Suporte N1'],
    'REC' => ['grupo' => 'Suporte N1'],
    'SAM' => ['grupo' => 'Suporte N1'],
    'SAD' => ['grupo' => 'Suporte N1'],
];

/** Entidade raiz, com herança para as filhas. */
const ENTITIES_ID  = 0;
const IS_RECURSIVE = 1;

require dirname(__DIR__) . '/vendor/autoload.php';

$kernel = new Kernel();
$kernel->boot();

/**
 * Condicao do critério de categoria. Declarada aqui, e nao no topo: uma
 * constante de topo e avaliada na ordem do arquivo, antes do autoload, e
 * `Rule::` ainda nao existiria.
 *
 * PATTERN_UNDER, e nao PATTERN_IS: `getSonsOf` inicia a lista com o proprio
 * id, entao "sob o modulo" casa o modulo E qualquer subcategoria criada
 * depois. Com PATTERN_IS, o dia em que "SCO > Erro de calculo" existir, o
 * chamado nessa subcategoria deixaria de casar e sairia sem atribuicao.
 */
const CONDICAO = Rule::PATTERN_UNDER;

$dry_run = in_array('--dry-run', $argv, true);

if (ATRIBUICAO === []) {
    echo "A constante ATRIBUICAO está vazia — nada a fazer.\n";
    echo "Preencha o mapa sigla => ['grupo' => '...'] ou ['usuario' => '...'].\n";
    exit(1);
}

$criados_grupo = 0;
$criadas_regra = 0;
$ajustadas     = 0;
$inalteradas   = 0;

/** Resolve (ou cria) o grupo técnico. Devolve o id. */
function resolve_grupo(string $nome, bool $dry_run, int &$criados): int
{
    $grupo = new Group();
    $achado = $grupo->find(['name' => $nome, 'entities_id' => ENTITIES_ID]);

    if ($achado !== []) {
        $row = reset($achado);
        if (!$row['is_assign']) {
            printf("!  grupo '%s' existe mas não é atribuível (is_assign=0)\n", $nome);
        }
        return (int) $row['id'];
    }

    printf("+  grupo '%s'\n", $nome);
    $criados++;

    if ($dry_run) {
        return -1;
    }

    $id = $grupo->add([
        'name'         => $nome,
        'entities_id'  => ENTITIES_ID,
        'is_recursive' => IS_RECURSIVE,
        'is_assign'    => 1,   // pode receber chamado
        'is_requester' => 1,   // pode abrir chamado
    ]);

    if ($id === false) {
        printf("!  falhou ao criar o grupo '%s'\n", $nome);
        exit(1);
    }

    return (int) $id;
}

/** Resolve o técnico nominal e avisa se ele não pode receber chamado. */
function resolve_usuario(string $login): int
{
    $user = new User();
    if (!$user->getFromDBbyName($login)) {
        printf("!  usuário '%s' não existe\n", $login);
        exit(1);
    }

    $id = (int) $user->fields['id'];

    // Um usuário sem nenhum perfil com direito de "ser atribuído" pode ser
    // gravado na regra, mas o chamado cai num técnico que não abre a interface
    // central. Silencioso e chato de descobrir depois, então avisa aqui.
    $pu = new Profile_User();
    $pode = false;
    foreach ($pu->find(['users_id' => $id]) as $link) {
        $pr = new ProfileRight();
        $achado = $pr->find(['profiles_id' => $link['profiles_id'], 'name' => 'ticket']);
        $row = $achado ? reset($achado) : [];
        if (((int) ($row['rights'] ?? 0)) & Ticket::OWN) {
            $pode = true;
            break;
        }
    }

    if (!$pode) {
        printf("!  '%s' não tem perfil que permita receber chamado (falta o direito Ticket::OWN)\n", $login);
    }

    return $id;
}

foreach (ATRIBUICAO as $sigla => $destino) {
    $cat = new ITILCategory();
    if (!$cat->getFromDBByCrit(['code' => $sigla, 'entities_id' => ENTITIES_ID])) {
        printf("!  módulo '%s' não encontrado (rode taskflow_seed_modules.php antes)\n", $sigla);
        exit(1);
    }
    $cat_id = (int) $cat->fields['id'];

    if (isset($destino['grupo'])) {
        $campo = '_groups_id_assign';
        $valor = resolve_grupo($destino['grupo'], $dry_run, $criados_grupo);
        $rotulo = "grupo '{$destino['grupo']}'";
    } elseif (isset($destino['usuario'])) {
        $campo = '_users_id_assign';
        $valor = resolve_usuario($destino['usuario']);
        $rotulo = "técnico '{$destino['usuario']}'";
    } else {
        printf("!  entrada '%s' precisa de 'grupo' ou 'usuario'\n", $sigla);
        exit(1);
    }

    $nome_regra = "Atribuição por módulo — $sigla";

    $rule = new RuleTicket();
    $achada = $rule->find(['name' => $nome_regra, 'sub_type' => 'RuleTicket']);

    if ($achada === []) {
        printf("+  regra '%s' -> %s\n", $nome_regra, $rotulo);
        $criadas_regra++;

        if ($dry_run) {
            continue;
        }

        $rules_id = $rule->add([
            'name'         => $nome_regra,
            'sub_type'     => 'RuleTicket',
            'match'        => 'AND',
            'is_active'    => 1,
            'entities_id'  => ENTITIES_ID,
            'is_recursive' => IS_RECURSIVE,
            'condition'    => RuleTicket::ONADD | RuleTicket::ONUPDATE,
            'description'  => "Chamado sob o módulo $sigla vai para $rotulo.",
        ]);

        if ($rules_id === false) {
            printf("!  falhou ao criar a regra de %s\n", $sigla);
            exit(1);
        }

        $crit = new RuleCriteria();
        $crit->add([
            'rules_id'  => $rules_id,
            'criteria'  => 'itilcategories_id',
            'condition' => CONDICAO,
            'pattern'   => $cat_id,
        ]);

        $act = new RuleAction();
        $act->add([
            'rules_id'    => $rules_id,
            'action_type' => 'assign',
            'field'       => $campo,
            'value'       => $valor,
        ]);

        continue;
    }

    // Já existe: confere critério e ação, corrige o que divergir.
    $row = reset($achada);
    $rules_id = (int) $row['id'];

    $crit = new RuleCriteria();
    $crits = $crit->find(['rules_id' => $rules_id]);
    $c = $crits ? reset($crits) : [];

    $act = new RuleAction();
    $acts = $act->find(['rules_id' => $rules_id]);
    $a = $acts ? reset($acts) : [];

    $diff = [];
    if (count($crits) !== 1 || (int) ($c['pattern'] ?? 0) !== $cat_id
        || ($c['criteria'] ?? '') !== 'itilcategories_id'
        || (int) ($c['condition'] ?? -1) !== CONDICAO) {
        $diff[] = 'critério';
    }
    if (count($acts) !== 1 || ($a['field'] ?? '') !== $campo
        || (int) ($a['value'] ?? 0) !== $valor) {
        $diff[] = 'ação';
    }
    if (!$row['is_active']) {
        $diff[] = 'inativa';
    }

    if ($diff === []) {
        printf("=  regra de %s já está como esperado (id %d) -> %s\n", $sigla, $rules_id, $rotulo);
        $inalteradas++;
        continue;
    }

    printf("~  regra de %s (id %d): corrigir %s\n", $sigla, $rules_id, implode(', ', $diff));
    $ajustadas++;

    if ($dry_run) {
        continue;
    }

    foreach ($crits as $velho) { $crit->delete(['id' => $velho['id']], true); }
    foreach ($acts as $velho)  { $act->delete(['id' => $velho['id']], true); }

    $crit->add([
        'rules_id'  => $rules_id,
        'criteria'  => 'itilcategories_id',
        'condition' => CONDICAO,
        'pattern'   => $cat_id,
    ]);
    $act->add([
        'rules_id'    => $rules_id,
        'action_type' => 'assign',
        'field'       => $campo,
        'value'       => $valor,
    ]);
    if (!$row['is_active']) {
        $rule->update(['id' => $rules_id, 'is_active' => 1]);
    }
}

printf(
    "\n%s: %d grupo(s) criado(s), %d regra(s) criada(s), %d ajustada(s), %d sem mudança\n",
    $dry_run ? 'Simulação' : 'Concluído',
    $criados_grupo,
    $criadas_regra,
    $ajustadas,
    $inalteradas
);
