<?php
/**
 * Entrada de menu "Relatórios" em Gerência e roteamento dos blocos.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginServicereportsMenu extends CommonGLPI
{
    public static $rightname = 'plugin_servicereports';

    public static function getTypeName($nb = 0)
    {
        return __('Relatórios', 'servicereports');
    }

    public static function getMenuName()
    {
        return self::getTypeName();
    }

    public static function getIcon()
    {
        return 'ti ti-chart-histogram';
    }

    public static function canView()
    {
        return Session::haveRight(self::$rightname, READ);
    }

    /**
     * Blocos disponíveis (só os 3 solicitados).
     *
     * @return array<string,array{title:string,desc:string,page:string,icon:string}>
     */
    public static function getBlocks()
    {
        return [
            'servicecentral' => [
                'title' => __('Central de serviços', 'servicereports'),
                'desc'  => __('Visualização rápida e fácil dos dados da central de serviços', 'servicereports'),
                'page'  => 'front/servicecentral.php',
                'icon'  => 'ti ti-headset',
            ],
            'financial' => [
                'title' => __('Gestão financeira', 'servicereports'),
                'desc'  => __('Dashboards e relatórios', 'servicereports'),
                'page'  => 'front/financial.php',
                'icon'  => 'ti ti-currency-dollar',
            ],
            'analysts' => [
                'title' => __('Analistas', 'servicereports'),
                'desc'  => __('Dashboards e relatórios', 'servicereports'),
                'page'  => 'front/analysts.php',
                'icon'  => 'ti ti-users',
            ],
        ];
    }

    public static function getMenuContent()
    {
        $menu = [];
        $menu['title'] = self::getMenuName();
        $menu['page']  = '/plugins/servicereports/front/central.php';
        $menu['icon']  = self::getIcon();

        foreach (self::getBlocks() as $key => $block) {
            $menu['options'][$key] = [
                'title' => $block['title'],
                'page'  => '/plugins/servicereports/' . $block['page'],
                'icon'  => $block['icon'],
            ];
        }

        return $menu;
    }
}
