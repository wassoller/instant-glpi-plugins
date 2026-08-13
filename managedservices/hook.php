<?php
/**
 * Instalação / desinstalação do plugin Serviços Gerenciados.
 */

/**
 * @return bool
 */
function plugin_managedservices_install()
{
    $migration = new Migration(PLUGIN_MANAGEDSERVICES_VERSION);

    PluginManagedservicesManagedservice::install($migration);
    PluginManagedservicesProfile::install($migration);

    $migration->executeMigration();

    return true;
}

/**
 * @return bool
 */
function plugin_managedservices_uninstall()
{
    $migration = new Migration(PLUGIN_MANAGEDSERVICES_VERSION);

    PluginManagedservicesProfile::uninstall($migration);
    PluginManagedservicesManagedservice::uninstall($migration);

    $migration->executeMigration();

    return true;
}
