<?php
/**
 * Integração de direitos (perfis) do plugin Relatórios.
 *
 * São **três** direitos, um por bloco (`plugin_servicereports_central`,
 * `..._financial`, `..._analysts`). Até a 0.5.5 havia um só, `plugin_servicereports`,
 * que dava acesso a tudo; `install()` migra quem o tinha para os três.
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

class PluginServicereportsProfile extends Profile
{
    public static $rightname = 'profile';

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

        // Uma linha por bloco. Os direitos vão explícitos porque
        // PluginServicereportsMenu estende CommonGLPI e não tem getRights()
        // (método de CommonDBTM); e "Ler" é o único que faz sentido — o plugin
        // não grava nada.
        $rights = [];
        foreach (PluginServicereportsMenu::getBlocks() as $block) {
            $rights[] = [
                'rights' => [READ => __('Read')],
                'label'  => $block['title'],
                'field'  => $block['right'],
            ];
        }

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

    /**
     * Registra os três direitos.
     *
     * O GLPI chama esta mesma função na **atualização** do plugin, então ela é
     * idempotente e, quando encontra o direito único antigo, migra os perfis que o
     * tinham (qualquer valor > 0) para os três novos — ninguém perde acesso ao
     * atualizar.
     */
    public static function install(Migration $migration)
    {
        global $DB;

        $rights    = PluginServicereportsMenu::rights();
        $legacy    = PluginServicereportsMenu::RIGHT_LEGACY;
        $available = ProfileRight::getAllPossibleRights();
        $upgrade   = isset($available[$legacy]);

        // Quem tinha o direito antigo — lido ANTES de mexer em qualquer coisa.
        $granted = [];
        if ($upgrade) {
            $rows = $DB->request([
                'SELECT' => ['profiles_id'],
                'FROM'   => 'glpi_profilerights',
                'WHERE'  => ['name' => $legacy, 'rights' => ['>', 0]],
            ]);
            foreach ($rows as $row) {
                $granted[] = (int) $row['profiles_id'];
            }
        }

        // addProfileRights() não protege contra duplicata (a tabela tem UNIQUE
        // (profiles_id, name)), então só passa o que ainda não existe.
        $missing = array_values(array_diff($rights, array_keys($available)));
        if (count($missing)) {
            ProfileRight::addProfileRights($missing);
        }

        if ($upgrade) {
            if (count($granted)) {
                $DB->update(
                    'glpi_profilerights',
                    ['rights' => READ],
                    ['name' => $rights, 'profiles_id' => $granted]
                );
            }
            ProfileRight::deleteProfileRights([$legacy]);
            $migration->displayMessage(
                sprintf(
                    __('Direito "Relatórios" migrado para três (por bloco) em %d perfil(s).', 'servicereports'),
                    count($granted)
                )
            );
        } else {
            // Instalação nova: Super-Admin (id 4) e o perfil ativo veem os três.
            $DB->update(
                'glpi_profilerights',
                ['rights' => READ],
                ['name' => $rights, 'profiles_id' => 4]
            );
            if (isset($_SESSION['glpiactiveprofile']['id'])) {
                $DB->update(
                    'glpi_profilerights',
                    ['rights' => READ],
                    ['name' => $rights, 'profiles_id' => (int) $_SESSION['glpiactiveprofile']['id']]
                );
            }
        }

        // A sessão em curso carregou os direitos antigos; recarrega os do plugin
        // para o menu já aparecer certo sem precisar sair e entrar.
        if (isset($_SESSION['glpiactiveprofile']['id'])) {
            unset($_SESSION['glpiactiveprofile'][$legacy]);
            $_SESSION['glpiactiveprofile'] = array_merge(
                $_SESSION['glpiactiveprofile'],
                ProfileRight::getProfileRights((int) $_SESSION['glpiactiveprofile']['id'], $rights)
            );
        }
    }

    public static function uninstall(Migration $migration)
    {
        ProfileRight::deleteProfileRights(
            array_merge(PluginServicereportsMenu::rights(), [PluginServicereportsMenu::RIGHT_LEGACY])
        );
    }

    public static function changeProfile()
    {
        // GLPI carrega glpi_profilerights automaticamente.
    }
}
