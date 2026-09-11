<?php

function plugin_sccd_uninstall_run(): bool
{
    global $DB;

    $DB->doQuery('DROP TABLE IF EXISTS `glpi_plugin_sccd_queues`');
    $DB->delete('glpi_profilerights', ['name' => 'plugin_sccd']);
    $DB->delete('glpi_displaypreferences', ['itemtype' => 'GlpiPlugin\\Sccd\\Queue']);

    return true;
}
