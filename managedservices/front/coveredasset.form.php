<?php
/**
 * Handler da aba "Ativos cobertos pelo serviço".
 */

include('../../../inc/includes.php');

$obj = new PluginManagedservicesCoveredasset();
$fk  = PluginManagedservicesCoveredasset::FK;

// O direito global do plugin não separa entidade: cada operação é validada
// contra o SERVIÇO, e nas exclusões o serviço vem do banco, não do formulário.
if (isset($_POST['add'])) {
    PluginManagedservicesManagedservice::checkService($_POST[$fk] ?? 0, UPDATE);
    $itemtype = $_POST['itemtype'] ?? '';
    $items_id = (int) ($_POST['items_id'] ?? 0);
    if ($itemtype === '' || $itemtype === '0' || $items_id <= 0) {
        Session::addMessageAfterRedirect(__('Selecione a classe e o ativo.', 'managedservices'), false, ERROR);
    } else {
        $obj->add([
            'plugin_managedservices_managedservices_id' => (int) $_POST['plugin_managedservices_managedservices_id'],
            'itemtype'            => $itemtype,
            'items_id'            => $items_id,
            'contract_entry_date' => $_POST['contract_entry_date'] ?: null,
            'is_deleted'          => 0,
        ]);
    }
} elseif (isset($_POST['softdelete'])) {
    PluginManagedservicesManagedservice::checkChild($obj, $_POST['id'] ?? 0, UPDATE, $fk);
    $obj->update(['id' => (int) $_POST['id'], 'is_deleted' => 1]);
} elseif (isset($_POST['restore'])) {
    PluginManagedservicesManagedservice::checkChild($obj, $_POST['id'] ?? 0, UPDATE, $fk);
    $obj->update(['id' => (int) $_POST['id'], 'is_deleted' => 0]);
} elseif (isset($_POST['purge'])) {
    PluginManagedservicesManagedservice::checkChild($obj, $_POST['id'] ?? 0, PURGE, $fk);
    $obj->delete(['id' => (int) $_POST['id']], 1);
}

Html::back();
