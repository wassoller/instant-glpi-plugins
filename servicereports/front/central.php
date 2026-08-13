<?php
/**
 * Landing "Relatórios" — grade com os 3 blocos.
 */

include('../../../inc/includes.php');

global $CFG_GLPI;

Session::checkRight('plugin_servicereports', READ);

Html::header(
    PluginServicereportsMenu::getTypeName(),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginServicereportsMenu'
);

echo "<div class='container-fluid mt-3'>";
echo "<div class='row row-cols-1 row-cols-md-3 g-3'>";

foreach (PluginServicereportsMenu::getBlocks() as $key => $block) {
    $url = $CFG_GLPI['root_doc'] . '/plugins/servicereports/' . $block['page'];
    echo "<div class='col'>";
    echo "<a class='text-decoration-none text-reset' href='" . Html::cleanInputText($url) . "'>";
    echo "<div class='card h-100 shadow-sm'>";
    echo "<div class='card-body d-flex align-items-center gap-3'>";
    echo "<i class='" . $block['icon'] . " flex-shrink-0' style='font-size:2rem'></i>";
    echo "<div class='flex-grow-1' style='min-width:0'>";
    echo "<div class='h5 mb-1'>" . $block['title'] . "</div>";
    echo "<div class='text-muted' style='font-size:.85rem'>" . $block['desc'] . "</div>";
    echo "</div>";
    echo "</div></div></a></div>";
}

echo "</div></div>";

Html::footer();
