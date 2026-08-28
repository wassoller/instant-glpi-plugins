<?php
/**
 * -------------------------------------------------------------------------
 * Relatórios (servicereports) — GLPI plugin
 * -------------------------------------------------------------------------
 * Reimplementação funcional de 3 blocos de relatórios (Central de serviços,
 * Gestão financeira, Analistas) para GLPI 10.0.x. GPLv2+.
 * Depende do plugin `managedservices` para os dados financeiros dos serviços.
 * -------------------------------------------------------------------------
 */

define('PLUGIN_SERVICEREPORTS_VERSION', '0.1.0');
define('PLUGIN_SERVICEREPORTS_MIN_GLPI', '10.0.0');
define('PLUGIN_SERVICEREPORTS_MAX_GLPI', '10.0.99');

function plugin_init_servicereports()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['servicereports'] = true;

    Plugin::registerClass('PluginServicereportsProfile', ['addtabon' => ['Profile']]);
    Plugin::registerClass('PluginServicereportsMenu');

    // Entrada de menu em "Gerência" (management)
    if (Session::getLoginUserID()
        && class_exists('PluginServicereportsMenu')
        && PluginServicereportsMenu::canView()) {
        $PLUGIN_HOOKS['menu_toadd']['servicereports'] = [
            'management' => 'PluginServicereportsMenu',
        ];
    }

    $PLUGIN_HOOKS['change_profile']['servicereports'] = ['PluginServicereportsProfile', 'changeProfile'];
}

function plugin_version_servicereports()
{
    return [
        'name'         => 'Relatórios',
        'version'      => PLUGIN_SERVICEREPORTS_VERSION,
        'author'       => 'Lyon Wassoller',
        'license'      => 'GPLv2+',
        'homepage'     => 'https://github.com/wassoller/instant-glpi-plugins',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_SERVICEREPORTS_MIN_GLPI,
                'max' => PLUGIN_SERVICEREPORTS_MAX_GLPI,
            ],
        ],
    ];
}

function plugin_servicereports_check_prerequisites()
{
    if (version_compare(GLPI_VERSION, PLUGIN_SERVICEREPORTS_MIN_GLPI, 'lt')
        || version_compare(GLPI_VERSION, PLUGIN_SERVICEREPORTS_MAX_GLPI, 'ge')) {
        echo sprintf(
            'Este plugin requer GLPI >= %s e < %s.',
            PLUGIN_SERVICEREPORTS_MIN_GLPI,
            PLUGIN_SERVICEREPORTS_MAX_GLPI
        );
        return false;
    }
    return true;
}

function plugin_servicereports_check_config($verbose = false)
{
    return true;
}
