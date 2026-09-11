<?php

use Glpi\Plugin\Hooks;
use GlpiPlugin\Sccd\Queue;

define('PLUGIN_SCCD_VERSION', '1.0.0');
define('PLUGIN_SCCD_MIN_GLPI', '11.0.0');
define('PLUGIN_SCCD_MAX_GLPI', '11.9.99');

function plugin_init_sccd()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['sccd'] = true;

    Plugin::registerClass(Queue::class);

    // Array value (not a bare string) so a brand-new top-level menu category
    // "sccd" is created instead of appended to an existing one — see
    // Html::generateMenuSession().
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['sccd'] = ['sccd' => [Queue::class]];

    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['sccd'] = ['Ticket' => 'plugin_sccd_ticket_add'];
}

function plugin_version_sccd()
{
    return [
        'name'         => 'SCCD',
        'version'      => PLUGIN_SCCD_VERSION,
        'author'       => 'Renato Feijó',
        'license'      => 'GPLv3+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_SCCD_MIN_GLPI,
                'max' => PLUGIN_SCCD_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_sccd_check_prerequisites(): bool
{
    return true;
}

function plugin_sccd_check_config($verbose = false): bool
{
    return true;
}

function plugin_sccd_install()
{
    include_once __DIR__ . '/install/install.php';
    return plugin_sccd_install_run();
}

function plugin_sccd_uninstall()
{
    include_once __DIR__ . '/install/uninstall.php';
    return plugin_sccd_uninstall_run();
}

/**
 * Copia todo chamado novo para a fila do SCCD, para revisão manual antes do
 * encaminhamento. Sem filtro por módulo/categoria/status: entra tudo.
 */
function plugin_sccd_ticket_add(\Ticket $ticket)
{
    $queue = new Queue();
    $queue->add([
        'tickets_id'  => $ticket->fields['id'],
        'entities_id' => $ticket->fields['entities_id'],
        'name'        => $ticket->fields['name'],
        'content'     => $ticket->fields['content'],
        'status'      => $ticket->fields['status'],
    ]);
}
