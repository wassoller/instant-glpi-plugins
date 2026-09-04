<?php
/**
 * Handler da aba Gerência (gerentes do serviço).
 */

include('../../../inc/includes.php');

if (isset($_POST['update_managers'])) {
    // Valida contra o serviço (entidade inclusa), não só o direito do plugin.
    PluginManagedservicesManagedservice::checkService(
        $_POST[PluginManagedservicesManager::FK] ?? 0,
        UPDATE
    );
    PluginManagedservicesManager::updateForService($_POST);
    Session::addMessageAfterRedirect(__('Item successfully updated'));
}

Html::back();
