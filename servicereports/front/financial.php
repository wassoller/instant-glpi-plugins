<?php
/**
 * Bloco: Gestão financeira (Fase 5 — em construção).
 * Lerá os dados financeiros do plugin managedservices.
 */

include('../../../inc/includes.php');

Session::checkRight('plugin_servicereports', READ);

Html::header(
    __('Gestão financeira', 'servicereports'),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginServicereportsMenu'
);

echo "<div class='alert alert-info m-3'>"
    . __('Gestão financeira — em construção (Fase 5).', 'servicereports')
    . "</div>";

Html::footer();
