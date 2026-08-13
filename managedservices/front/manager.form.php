<?php
/**
 * Handler da aba Gerência (gerentes do serviço).
 */

include('../../../inc/includes.php');

Session::checkRight('plugin_managedservices', UPDATE);

if (isset($_POST['update_managers'])) {
    PluginManagedservicesManager::updateForService($_POST);
    Session::addMessageAfterRedirect(__('Item successfully updated'));
}

Html::back();
