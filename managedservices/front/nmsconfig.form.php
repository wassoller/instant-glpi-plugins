<?php
/**
 * Handler da aba "Configuração NMS".
 */

include('../../../inc/includes.php');

Session::checkRight('plugin_managedservices', UPDATE);

if (isset($_POST['save_nms'])) {
    PluginManagedservicesNmsconfig::saveForService($_POST);
    Session::addMessageAfterRedirect(__('Item successfully updated'));
}

Html::back();
