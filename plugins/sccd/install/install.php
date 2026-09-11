<?php

function plugin_sccd_install_run(): bool
{
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    $table = 'glpi_plugin_sccd_queues';
    if (!$DB->tableExists($table)) {
        $query = "CREATE TABLE `$table` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `tickets_id` int {$default_key_sign} NOT NULL DEFAULT '0',
            `entities_id` int {$default_key_sign} NOT NULL DEFAULT '0',
            `name` varchar(255) DEFAULT NULL,
            `content` longtext,
            `status` int NOT NULL DEFAULT '1',
            `sccd_sent` tinyint NOT NULL DEFAULT '0',
            `sccd_sent_date` timestamp NULL DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `tickets_id` (`tickets_id`),
            KEY `entities_id` (`entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=$default_charset COLLATE=$default_collation";
        $DB->doQuery($query);
    }

    // Direito próprio — não reaproveitar 'config' do core, que por padrão só
    // o Super-Admin tem (Admin fica de fora). Concedido explicitamente por
    // nome de perfil, não por id fixo.
    if (!countElementsInTable('glpi_profilerights', ['name' => 'plugin_sccd'])) {
        foreach ($DB->request(['FROM' => 'glpi_profiles']) as $profile) {
            $rights = in_array($profile['name'], ['Admin', 'Super-Admin'], true)
                ? (READ | CREATE | UPDATE)
                : 0;
            $DB->insert('glpi_profilerights', [
                'profiles_id' => $profile['id'],
                'name'        => 'plugin_sccd',
                'rights'      => $rights,
            ]);
        }
    }

    return true;
}
