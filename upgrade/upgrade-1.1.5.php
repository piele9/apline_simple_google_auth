<?php
/**
 * Upgrade to 1.1.5 — Google button in the login tab of the checkout and the
 * note about the terms and the privacy policy under the button.
 *
 * - registers the module on actionOutputHTMLBefore (the checkout login tab has
 *   no hook of its own, the button is added to the page there) and adds its
 *   switch ASGA_CHECKOUT_LOGIN (on);
 * - adds the settings of the note, only for keys that do not exist yet, so a
 *   second run never overwrites the shop's choice: note on, terms page taken
 *   from the shop's own "conditions" setting, privacy policy page guessed
 *   from the friendly URL (0 = no link when nothing matches).
 *
 * The script is self-contained on purpose (no calls to new methods or
 * constants of the module class): during an upload-upgrade the old class may
 * already be loaded in the same request.
 *
 * No new tables or tabs.
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
function asga_upgrade_1_1_5_key_exists($key)
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
function upgrade_module_1_1_5($module)
{
    $ok = (bool) $module->registerHook('actionOutputHTMLBefore');

    if (!asga_upgrade_1_1_5_key_exists('ASGA_CHECKOUT_LOGIN')) {
        $ok = Configuration::updateValue('ASGA_CHECKOUT_LOGIN', 1) && $ok;
    }

    if (!asga_upgrade_1_1_5_key_exists('ASGA_CONSENT_NOTE')) {
        $ok = Configuration::updateValue('ASGA_CONSENT_NOTE', 1) && $ok;
    }

    if (!asga_upgrade_1_1_5_key_exists('ASGA_CONSENT_TERMS_CMS')) {
        $ok = Configuration::updateValue('ASGA_CONSENT_TERMS_CMS', (int) Configuration::get('PS_CONDITIONS_CMS_ID')) && $ok;
    }

    if (!asga_upgrade_1_1_5_key_exists('ASGA_CONSENT_PRIVACY_CMS')) {
        // Keep in sync with guessPrivacyCmsId() of the module class.
        $idPrivacy = (int) Db::getInstance()->getValue(
            'SELECT cl.`id_cms` FROM `' . _DB_PREFIX_ . 'cms_lang` cl
             INNER JOIN `' . _DB_PREFIX_ . 'cms` c ON c.`id_cms` = cl.`id_cms` AND c.`active` = 1
             WHERE cl.`link_rewrite` LIKE \'polityka-prywatnosci%\' OR cl.`link_rewrite` LIKE \'privacy%\'
             ORDER BY cl.`id_cms` ASC'
        );
        $ok = Configuration::updateValue('ASGA_CONSENT_PRIVACY_CMS', $idPrivacy) && $ok;
    }

    return $ok;
}
