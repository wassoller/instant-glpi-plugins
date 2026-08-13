<?php
/**
 * Listagem/busca de Serviços Gerenciados.
 */

include('../../../inc/includes.php');

Session::checkRight('plugin_managedservices', READ);

Html::header(
    PluginManagedservicesManagedservice::getTypeName(Session::getPluralNumber()),
    $_SERVER['PHP_SELF'],
    'assets',
    'PluginManagedservicesManagedservice'
);

Search::show('PluginManagedservicesManagedservice');

Html::footer();
