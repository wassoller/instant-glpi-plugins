<?php
/**
 * Bloco: Central de serviços — dashboard de KPIs do mês corrente.
 */

include('../../../inc/includes.php');

Session::checkRight('plugin_servicereports', READ);

Html::header(
    __('Central de serviços', 'servicereports'),
    $_SERVER['PHP_SELF'],
    'management',
    'PluginServicereportsMenu'
);

$kpis = PluginServicereportsServicecentral::getKpis();

echo "<div class='container-fluid mt-3'>";
echo "<div class='text-center mb-3'>";
echo "<h2 class='mb-0'>" . __('Central de Serviços', 'servicereports') . "</h2>";
echo "<div class='text-muted'>" . __('Dados do mês corrente', 'servicereports') . "</div>";
echo "</div>";

echo "<div class='row row-cols-1 row-cols-md-2 row-cols-xl-4 g-3'>";
foreach ($kpis as $kpi) {
    echo "<div class='col'>";
    echo "<div class='card h-100 shadow-sm'>";
    echo "<div class='card-body d-flex flex-column'>";
    echo "<div class='d-flex align-items-center justify-content-between mb-2'>";
    echo "<div class='text-muted text-uppercase' style='font-size:.75rem'>" . $kpi['title'] . "</div>";
    echo "<i class='" . $kpi['icon'] . "' style='font-size:1.5rem;opacity:.6'></i>";
    echo "</div>";
    echo "<div class='h1 mb-2'>" . $kpi['value'] . "</div>";
    echo "<div class='text-muted mb-3' style='font-size:.85rem;flex-grow:1'>" . $kpi['desc'] . "</div>";
    echo "<a href='" . Html::cleanInputText($kpi['url']) . "' class='btn btn-outline-primary btn-sm'>" . $kpi['btn'] . "</a>";
    echo "</div></div></div>";
}
echo "</div>";
echo "</div>";

Html::footer();
