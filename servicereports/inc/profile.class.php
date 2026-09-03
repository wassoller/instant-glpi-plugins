<?php
/**
 * Integração de direitos (perfis) do plugin Relatórios.
 * Registra o direito `plugin_servicereports`.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginServicereportsProfile extends Profile
{
    public static $rightname = 'profile';

    public const RIGHT = 'plugin_servicereports';

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile
            && $item->getField('id')
            && $item->getField('interface') !== 'helpdesk') {
            return self::createTabEntry(PluginServicereportsMenu::getTypeName());
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

    public function showRightsForm($profiles_id = 0, $openform = true, $closeform = true)
    {
        $canedit = Session::haveRightsOr('profile', [CREATE, UPDATE, PURGE]);

        echo "<div class='spaced'>";
        if ($openform && $canedit) {
            echo "<form method='post' action='" . Profile::getFormURL() . "'>";
        }

        $profile = new Profile();
        $profile->getFromDB($profiles_id);

        // PluginServicereportsMenu estende CommonGLPI e não tem getRights(),
        // então a matriz recebe os direitos explicitamente (o plugin só lê dados).
        $rights = [[
            'rights' => [READ => __('Read')],
            'label'  => PluginServicereportsMenu::getTypeName(),
            'field'  => self::RIGHT,
        ]];

        $profile->displayRightsChoiceMatrix($rights, [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => PluginServicereportsMenu::getTypeName(),
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

    public static function install(Migration $migration)
    {
        global $DB;

        ProfileRight::addProfileRights([self::RIGHT]);

        $DB->update(
            'glpi_profilerights',
            ['rights' => READ],
            ['name' => self::RIGHT, 'profiles_id' => 4]
        );

        if (isset($_SESSION['glpiactiveprofile']['id'])) {
            $pid = (int) $_SESSION['glpiactiveprofile']['id'];
            $DB->update(
                'glpi_profilerights',
                ['rights' => READ],
                ['name' => self::RIGHT, 'profiles_id' => $pid]
            );
            $_SESSION['glpiactiveprofile'][self::RIGHT] = READ;
        }
    }

    public static function uninstall(Migration $migration)
    {
        ProfileRight::deleteProfileRights([self::RIGHT]);
    }

    public static function changeProfile()
    {
        // GLPI carrega glpi_profilerights automaticamente.
    }
}
