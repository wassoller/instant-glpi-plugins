<?php
/**
 * Entrada de menu "Relatórios" em Gerência e roteamento dos blocos.
 *
 * Cada bloco tem o **seu** direito (ver PluginServicereportsProfile): um perfil pode
 * ver só "Analistas", só "Gestão financeira", etc. O menu e a grade de `central.php`
 * mostram apenas os blocos permitidos.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginServicereportsMenu extends CommonGLPI
{
    /** Um direito por bloco. */
    public const RIGHT_CENTRAL   = 'plugin_servicereports_central';
    public const RIGHT_FINANCIAL = 'plugin_servicereports_financial';
    public const RIGHT_ANALYSTS  = 'plugin_servicereports_analysts';

    /**
     * Direito único usado até a 0.5.5, quando "Relatórios" era tudo ou nada.
     * Continua nomeado aqui porque a instalação o migra para os três acima.
     */
    public const RIGHT_LEGACY = 'plugin_servicereports';

    /**
     * `$rightname` fica vazio de propósito: não há um direito único que responda por
     * este menu. O acesso é decidido bloco a bloco por `canView()`.
     */
    public static $rightname = '';

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

    /** @return string[] Os três direitos, na ordem dos blocos. */
    public static function rights()
    {
        return [self::RIGHT_CENTRAL, self::RIGHT_FINANCIAL, self::RIGHT_ANALYSTS];
    }

    /** O menu aparece para quem puder ver **pelo menos um** bloco. */
    public static function canView()
    {
        foreach (self::rights() as $right) {
            if (Session::haveRight($right, READ)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Blocos disponíveis (só os 3 solicitados).
     *
     * @return array<string,array{title:string,desc:string,page:string,icon:string,right:string}>
     */
    public static function getBlocks()
    {
        return [
            'servicecentral' => [
                'title' => __('Central de serviços', 'servicereports'),
                'desc'  => __('Visualização rápida e fácil dos dados da central de serviços', 'servicereports'),
                'page'  => 'front/servicecentral.php',
                'icon'  => 'ti ti-headset',
                'right' => self::RIGHT_CENTRAL,
            ],
            'financial' => [
                'title' => __('Gestão financeira', 'servicereports'),
                'desc'  => __('Dashboards e relatórios', 'servicereports'),
                'page'  => 'front/financial.php',
                'icon'  => 'ti ti-currency-dollar',
                'right' => self::RIGHT_FINANCIAL,
            ],
            'analysts' => [
                'title' => __('Analistas', 'servicereports'),
                'desc'  => __('Dashboards e relatórios', 'servicereports'),
                'page'  => 'front/analysts.php',
                'icon'  => 'ti ti-users',
                'right' => self::RIGHT_ANALYSTS,
            ],
        ];
    }

    /** Os blocos que o perfil ativo pode abrir. */
    public static function getVisibleBlocks()
    {
        $blocks = [];
        foreach (self::getBlocks() as $key => $block) {
            if (Session::haveRight($block['right'], READ)) {
                $blocks[$key] = $block;
            }
        }
        return $blocks;
    }

    public static function getMenuContent()
    {
        $menu = [];
        $menu['title'] = self::getMenuName();
        $menu['page']  = '/plugins/servicereports/front/central.php';
        $menu['icon']  = self::getIcon();

        foreach (self::getVisibleBlocks() as $key => $block) {
            $menu['options'][$key] = [
                'title' => $block['title'],
                'page'  => '/plugins/servicereports/' . $block['page'],
                'icon'  => $block['icon'],
            ];
        }

        return $menu;
    }
}
