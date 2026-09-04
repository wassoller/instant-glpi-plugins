<?php
/**
 * Handler da aba "Financeiro".
 */

include('../../../inc/includes.php');

$obj = new PluginManagedservicesFinancialvalue();
$fk  = PluginManagedservicesFinancialvalue::FK;

// O direito global do plugin não separa entidade: cada operação é validada
// contra o SERVIÇO, e nas exclusões o serviço vem do banco, não do formulário.
if (isset($_POST['add_value'])) {
    PluginManagedservicesManagedservice::checkService($_POST[$fk] ?? 0, UPDATE);
    $raw = str_replace(',', '.', (string) ($_POST['value'] ?? ''));
    if (!is_numeric($raw)) {
        Session::addMessageAfterRedirect(__('Informe um valor válido.', 'managedservices'), false, ERROR);
    } else {
        PluginManagedservicesFinancialvalue::addValue($_POST);
    }
} elseif (isset($_POST['delete_value'])) {
    PluginManagedservicesManagedservice::checkChild($obj, $_POST['id'] ?? 0, PURGE, $fk);
    $obj->delete(['id' => (int) $_POST['id']], 1);
} elseif (isset($_POST['save_config'])) {
    PluginManagedservicesManagedservice::checkService($_POST[$fk] ?? 0, UPDATE);
    PluginManagedservicesFinancialvalue::saveConfig($_POST);
    Session::addMessageAfterRedirect(__('Item successfully updated'));
}

Html::back();
