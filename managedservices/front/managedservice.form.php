<?php
/**
 * Formulário (criar/editar/excluir) de Serviço Gerenciado.
 */

include('../../../inc/includes.php');

$ms = new PluginManagedservicesManagedservice();

if (isset($_POST['add'])) {
    $ms->check(-1, CREATE, $_POST);
    $ms->add($_POST);
    Html::back();
} elseif (isset($_POST['update'])) {
    $ms->check((int) $_POST['id'], UPDATE);
    $ms->update($_POST);
    Html::back();
} elseif (isset($_POST['delete'])) {
    $ms->check((int) $_POST['id'], DELETE);
    $ms->delete($_POST);
    $ms->redirectToList();
} elseif (isset($_POST['purge'])) {
    $ms->check((int) $_POST['id'], PURGE);
    $ms->delete($_POST, 1);
    $ms->redirectToList();
} elseif (isset($_POST['restore'])) {
    $ms->check((int) $_POST['id'], DELETE);
    $ms->restore($_POST);
    $ms->redirectToList();
} else {
    $id = (int) ($_GET['id'] ?? 0);

    Html::header(
        PluginManagedservicesManagedservice::getTypeName(1),
        $_SERVER['PHP_SELF'],
        'assets',
        'PluginManagedservicesManagedservice'
    );

    $ms->display(['id' => $id]);

    Html::footer();
}
