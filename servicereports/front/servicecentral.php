<?php
/**
 * Bloco: Central de serviços (Fase 4 — em construção).
 */

include('../../../inc/includes.php');

Session::checkRight('plugin_servicereports', READ);

Html::header(
    __('Central de serviços', 'servicereports'),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginServicereportsMenu'
);

echo "<div class='alert alert-info m-3'>"
    . __('Central de serviços — em construção (Fase 4).', 'servicereports')
    . "</div>";

Html::footer();
