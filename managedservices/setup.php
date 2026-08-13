<?php
/**
 * -------------------------------------------------------------------------
 * Serviços Gerenciados (managedservices) — GLPI plugin
 * -------------------------------------------------------------------------
 * Reimplementação funcional livre de um plugin de serviços gerenciados para
 * GLPI 10.0.x. Distribuído sob GPLv2+ (obra derivada do GLPI, GPL).
 * -------------------------------------------------------------------------
 */

define('PLUGIN_MANAGEDSERVICES_VERSION', '0.1.0');

// GLPI mínimo suportado (inclusivo)
define('PLUGIN_MANAGEDSERVICES_MIN_GLPI', '10.0.0');
// GLPI máximo suportado (exclusivo)
define('PLUGIN_MANAGEDSERVICES_MAX_GLPI', '10.0.99');

/**
 * Inicialização dos hooks do plugin (OBRIGATÓRIA).
 */
function plugin_init_managedservices()
{
    global $PLUGIN_HOOKS;

    // Plugin em conformidade com o CSRF do GLPI
    $PLUGIN_HOOKS['csrf_compliant']['managedservices'] = true;

    // Registro das classes
    Plugin::registerClass('PluginManagedservicesProfile', ['addtabon' => ['Profile']]);
    Plugin::registerClass('PluginManagedservicesManagedservice');

    // Entrada de menu em "Ativos" (assets)
    if (Session::getLoginUserID()
        && class_exists('PluginManagedservicesManagedservice')
        && PluginManagedservicesManagedservice::canView()) {
        $PLUGIN_HOOKS['menu_toadd']['managedservices'] = [
            'assets' => 'PluginManagedservicesManagedservice',
        ];
    }

    // Reagir a troca de perfil (recarrega direitos do plugin na sessão)
    $PLUGIN_HOOKS['change_profile']['managedservices'] = ['PluginManagedservicesProfile', 'changeProfile'];
}

/**
 * Metadados do plugin (OBRIGATÓRIA).
 *
 * @return array
 */
function plugin_version_managedservices()
{
    return [
        'name'         => 'Serviços Gerenciados',
        'version'      => PLUGIN_MANAGEDSERVICES_VERSION,
        'author'       => 'Instant Tecnologia',
        'license'      => 'GPLv2+',
        'homepage'     => 'https://github.com/wassoller/instant-glpi-plugins',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_MANAGEDSERVICES_MIN_GLPI,
                'max' => PLUGIN_MANAGEDSERVICES_MAX_GLPI,
            ],
        ],
    ];
}

/**
 * Verificação de pré-requisitos (versão do GLPI).
 *
 * @return bool
 */
function plugin_managedservices_check_prerequisites()
{
    if (version_compare(GLPI_VERSION, PLUGIN_MANAGEDSERVICES_MIN_GLPI, 'lt')
        || version_compare(GLPI_VERSION, PLUGIN_MANAGEDSERVICES_MAX_GLPI, 'ge')) {
        echo sprintf(
            'Este plugin requer GLPI >= %s e < %s.',
            PLUGIN_MANAGEDSERVICES_MIN_GLPI,
            PLUGIN_MANAGEDSERVICES_MAX_GLPI
        );
        return false;
    }
    return true;
}

/**
 * Verificação de configuração.
 *
 * @param bool $verbose
 * @return bool
 */
function plugin_managedservices_check_config($verbose = false)
{
    return true;
}
