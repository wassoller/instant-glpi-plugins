<?php
/**
 * Handler da aba "Configuração NMS".
 */

include('../../../inc/includes.php');

if (isset($_POST['save_nms'])) {
    // Valida contra o serviço (entidade inclusa), não só o direito do plugin.
    PluginManagedservicesManagedservice::checkService(
        $_POST[PluginManagedservicesNmsconfig::FK] ?? 0,
        UPDATE
    );
    PluginManagedservicesNmsconfig::saveForService($_POST);
    Session::addMessageAfterRedirect(__('Item successfully updated'));
}

Html::back();
