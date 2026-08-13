<?php
/**
 * Handler da aba "Financeiro".
 */

include('../../../inc/includes.php');

$obj = new PluginManagedservicesFinancialvalue();

if (isset($_POST['add_value'])) {
    Session::checkRight('plugin_managedservices', UPDATE);
    $raw = str_replace(',', '.', (string) ($_POST['value'] ?? ''));
    if (!is_numeric($raw)) {
        Session::addMessageAfterRedirect(__('Informe um valor válido.', 'managedservices'), false, ERROR);
    } else {
        PluginManagedservicesFinancialvalue::addValue($_POST);
    }
} elseif (isset($_POST['delete_value'])) {
    Session::checkRight('plugin_managedservices', PURGE);
    $obj->delete(['id' => (int) $_POST['id']], 1);
} elseif (isset($_POST['save_config'])) {
    Session::checkRight('plugin_managedservices', UPDATE);
    PluginManagedservicesFinancialvalue::saveConfig($_POST);
    Session::addMessageAfterRedirect(__('Item successfully updated'));
}

Html::back();
