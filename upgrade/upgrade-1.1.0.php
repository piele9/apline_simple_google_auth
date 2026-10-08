<?php
/**
 * Upgrade to 1.1.0 — Polish interface, large admin buttons and placeholder settings.
 *
 * Version 1.0.1 always showed a large inactive "Kontynuuj z Google" placeholder
 * (72 px, 22 px text, fixed message) while no Client ID was set. 1.1.0 moves
 * that behaviour into configuration with a neutral default for new installs
 * (placeholder off). For an existing installation this script keeps the 1.0.1
 * behaviour: placeholder on, large size, the same Polish message — but only
 * for keys that do not exist yet, so a second run never overwrites the shop's
 * settings.
 *
 * The script is self-contained on purpose (no calls to new methods or
 * constants of the module class): during an upload-upgrade the old class may
 * already be loaded in the same request.
 *
 * Nothing else changes in the database: no new tables, hooks or tabs.
 *
 * @author    Arkadiusz Pielechowski
 * @copyright Arkadiusz Pielechowski
 * @license   MIT - see LICENSE.md
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param string $key
 *
 * @return bool true when the key exists in any shop context
 */
function asga_upgrade_1_1_0_key_exists($key)
{
    return (bool) Db::getInstance()->getValue(
        'SELECT `id_configuration` FROM `' . _DB_PREFIX_ . 'configuration`
         WHERE `name` = \'' . pSQL($key) . '\''
    );
}

/**
 * @param apline_simple_google_auth $module
 *
 * @return bool
 */
function upgrade_module_1_1_0($module)
{
    $ok = true;

    // 1.0.1 showed the placeholder whenever the Client ID was empty.
    if (!asga_upgrade_1_1_0_key_exists('ASGA_PLACEHOLDER')) {
        $ok = Configuration::updateValue('ASGA_PLACEHOLDER', 1) && $ok;
    }

    // 1.0.1 placeholder was the large one (72 px, 22 px text).
    if (!asga_upgrade_1_1_0_key_exists('ASGA_PLACEHOLDER_LARGE')) {
        $ok = Configuration::updateValue('ASGA_PLACEHOLDER_LARGE', 1) && $ok;
    }

    // The 1.0.1 Polish message (keep in sync with DEFAULT_PLACEHOLDER_INFO), for every language.
    if (!asga_upgrade_1_1_0_key_exists('ASGA_PLACEHOLDER_INFO')) {
        $text = 'Logowanie przez Google jest w przygotowaniu – sklep wymaga jeszcze dokonfigurowania. Zaloguj się adresem e-mail i hasłem albo załóż konto.';
        $values = [];
        foreach (Language::getLanguages(false) as $lang) {
            $values[(int) $lang['id_lang']] = $text;
        }
        $ok = Configuration::updateValue('ASGA_PLACEHOLDER_INFO', $values) && $ok;
    }

    return (bool) $ok;
}
