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
    PluginManagedservicesManager::install($migration);
    PluginManagedservicesCoveredasset::install($migration);
    PluginManagedservicesComposition::install($migration);
    PluginManagedservicesNmsconfig::install($migration);
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
    PluginManagedservicesNmsconfig::uninstall($migration);
    PluginManagedservicesComposition::uninstall($migration);
    PluginManagedservicesCoveredasset::uninstall($migration);
    PluginManagedservicesManager::uninstall($migration);
    PluginManagedservicesManagedservice::uninstall($migration);

    $migration->executeMigration();

    return true;
}
