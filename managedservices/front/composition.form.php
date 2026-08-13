<?php
/**
 * Handler da aba "Composição do Serviço".
 */

include('../../../inc/includes.php');

$obj = new PluginManagedservicesComposition();

if (isset($_POST['add'])) {
    Session::checkRight('plugin_managedservices', UPDATE);
    $itemtype = $_POST['itemtype'] ?? '';
    $items_id = (int) ($_POST['items_id'] ?? 0);
    if ($itemtype === '' || $itemtype === '0' || $items_id <= 0) {
        Session::addMessageAfterRedirect(__('Selecione a classe e o ativo.', 'managedservices'), false, ERROR);
    } else {
        $obj->add([
            'plugin_managedservices_managedservices_id' => (int) $_POST['plugin_managedservices_managedservices_id'],
            'itemtype' => $itemtype,
            'items_id' => $items_id,
            'impact'   => (int) ($_POST['impact'] ?? PluginManagedservicesComposition::IMPACT_TOTAL),
        ]);
    }
} elseif (isset($_POST['purge'])) {
    Session::checkRight('plugin_managedservices', PURGE);
    $obj->delete(['id' => (int) $_POST['id']], 1);
}

Html::back();
