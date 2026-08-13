<?php
/**
 * Bloco: Analistas (Fase 6 — em construção).
 */

include('../../../inc/includes.php');

Session::checkRight('plugin_servicereports', READ);

Html::header(
    __('Analistas', 'servicereports'),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginServicereportsMenu'
);

echo "<div class='alert alert-info m-3'>"
    . __('Analistas — em construção (Fase 6).', 'servicereports')
    . "</div>";

Html::footer();
