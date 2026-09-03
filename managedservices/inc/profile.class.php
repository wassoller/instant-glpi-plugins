<?php
/**
 * Integração de direitos (perfis) do plugin Serviços Gerenciados.
 * Registra o direito `plugin_managedservices` e exibe a aba de direitos no Perfil.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginManagedservicesProfile extends Profile
{
    public static $rightname = 'profile';

    /** Nome do direito registrado em glpi_profilerights. */
    public const RIGHT = 'plugin_managedservices';

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile
            && $item->getField('id')
            && $item->getField('interface') !== 'helpdesk') {
            return self::createTabEntry(PluginManagedservicesManagedservice::getTypeName(2));
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Profile) {
            $prof = new self();
            $prof->showRightsForm((int) $item->getID());
        }
        return true;
    }

    /**
     * Matriz de direitos do plugin dentro do formulário de Perfil.
     */
    public function showRightsForm($profiles_id = 0, $openform = true, $closeform = true)
    {
        $canedit = Session::haveRightsOr('profile', [CREATE, UPDATE, PURGE]);

        echo "<div class='spaced'>";
        if ($openform && $canedit) {
            echo "<form method='post' action='" . Profile::getFormURL() . "'>";
        }

        $profile = new Profile();
        $profile->getFromDB($profiles_id);

        $rights = [[
            'itemtype' => 'PluginManagedservicesManagedservice',
            'label'    => PluginManagedservicesManagedservice::getTypeName(2),
            'field'    => self::RIGHT,
        ]];

        $profile->displayRightsChoiceMatrix($rights, [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => PluginManagedservicesManagedservice::getTypeName(2),
        ]);

        if ($canedit && $closeform) {
            echo "<div class='center'>";
            echo Html::hidden('id', ['value' => $profiles_id]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
            echo "</div>";
            Html::closeForm();
        }
        echo "</div>";
    }

    /**
     * Registra o direito e concede acesso total aos perfis administrativos.
     */
    public static function install(Migration $migration)
    {
        global $DB;

        ProfileRight::addProfileRights([self::RIGHT]);

        // Perfil Super-Admin padrão (id 4) recebe acesso total.
        $DB->update(
            'glpi_profilerights',
            ['rights' => ALLSTANDARDRIGHT],
            ['name' => self::RIGHT, 'profiles_id' => 4]
        );

        // Concede também ao perfil ativo (para uso imediato após instalar).
        if (isset($_SESSION['glpiactiveprofile']['id'])) {
            $pid = (int) $_SESSION['glpiactiveprofile']['id'];
            $DB->update(
                'glpi_profilerights',
                ['rights' => ALLSTANDARDRIGHT],
                ['name' => self::RIGHT, 'profiles_id' => $pid]
            );
            $_SESSION['glpiactiveprofile'][self::RIGHT] = ALLSTANDARDRIGHT;
        }
    }

    public static function uninstall(Migration $migration)
    {
        ProfileRight::deleteProfileRights([self::RIGHT]);
    }

    /**
     * Hook change_profile: garante o direito carregado na sessão.
     */
    public static function changeProfile()
    {
        // GLPI carrega glpi_profilerights automaticamente ao ativar o perfil.
        // Mantido para extensões futuras.
    }
}
