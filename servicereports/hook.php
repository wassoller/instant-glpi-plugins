<?php
/**
 * Instalação / desinstalação do plugin Relatórios.
 * (Sem tabelas próprias por enquanto — os relatórios leem dados do
 *  plugin managedservices e do core do GLPI.)
 */

function plugin_servicereports_install()
{
    $migration = new Migration(PLUGIN_SERVICEREPORTS_VERSION);

    PluginServicereportsProfile::install($migration);

    $migration->executeMigration();

    return true;
}

function plugin_servicereports_uninstall()
{
    $migration = new Migration(PLUGIN_SERVICEREPORTS_VERSION);

    PluginServicereportsProfile::uninstall($migration);

    $migration->executeMigration();

    return true;
}
