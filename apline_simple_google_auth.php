<?php
/**
 * APLINE Simple Google Auth module for PrestaShop 9.
 *
 * Adds a "Continue with Google" button to the login and registration
 * pages using Google Identity Services (GIS). The returned ID token
 * (JWT) is verified server-side against Google's public keys, then the
 * matching customer is logged in or a new account is created. A
 * dedicated mapping table links id_customer to the Google `sub` claim
 * — the native ps_customer table is never altered.
 *
 * @author    Arkadiusz Pielechowski
 * @copyright Arkadiusz Pielechowski
 * @license   MIT - see LICENSE.md
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

class apline_simple_google_auth extends Module
{
    /** Translation domains. Polish is the source text (APLINE module standard). */
    const L10N = 'Modules.Aplinesimplegoogleauth.Admin';
    const L10N_SHOP = 'Modules.Aplinesimplegoogleauth.Shop';

    /** Configuration keys — user-facing. */
    const CLIENT_ID = 'ASGA_CLIENT_ID';
    const ENABLE_LOGIN = 'ASGA_ENABLE_LOGIN';
    const ENABLE_REGISTER = 'ASGA_ENABLE_REGISTER';
    const BUTTON_THEME = 'ASGA_BUTTON_THEME';
    const BUTTON_SIZE = 'ASGA_BUTTON_SIZE';
    const BUTTON_TEXT = 'ASGA_BUTTON_TEXT';
    const BUTTON_SHAPE = 'ASGA_BUTTON_SHAPE';
    const AUTO_PROMPT = 'ASGA_AUTO_PROMPT';
    const AUTO_LINK_EXISTING = 'ASGA_AUTO_LINK_EXISTING';
    const NOTIFY_EMAIL_ON_LINK = 'ASGA_NOTIFY_EMAIL_ON_LINK';

    /** Configuration keys — placeholder button shown while no Client ID is set (since 1.1.0). */
    const PLACEHOLDER = 'ASGA_PLACEHOLDER';
    const PLACEHOLDER_LARGE = 'ASGA_PLACEHOLDER_LARGE';
    const PLACEHOLDER_INFO = 'ASGA_PLACEHOLDER_INFO';

    /** Configuration keys — note about the terms and privacy policy under the button (since 1.1.5). */
    const CONSENT_NOTE = 'ASGA_CONSENT_NOTE';
    const CONSENT_TERMS_CMS = 'ASGA_CONSENT_TERMS_CMS';
    const CONSENT_PRIVACY_CMS = 'ASGA_CONSENT_PRIVACY_CMS';

    /** Configuration key — Google button added to the login tab of the checkout (since 1.1.5). */
    const CHECKOUT_LOGIN = 'ASGA_CHECKOUT_LOGIN';

    /** Configuration keys — internal JWKS cache (not shown in the form). */
    const JWKS_CACHE = 'ASGA_JWKS_CACHE';
    const JWKS_EXPIRES = 'ASGA_JWKS_EXPIRES';

    /** Mapping table name (without the DB prefix). */
    const LINK_TABLE = 'asga_customer_link';

    /** Default placeholder message (multilingual key, same text for every language; copied in upgrade-1.1.0.php). */
    const DEFAULT_PLACEHOLDER_INFO = 'Logowanie przez Google jest w przygotowaniu – sklep wymaga jeszcze dokonfigurowania. Zaloguj się adresem e-mail i hasłem albo załóż konto.';

    /** Maximum length of the placeholder message (characters). */
    const PLACEHOLDER_INFO_MAX = 500;

    /** Fallback names for a new customer whose Google profile has no usable name. */
    const FALLBACK_FIRSTNAME = 'Klient';
    const FALLBACK_LASTNAME = 'Google';

    /** @var bool the GIS settings block (g_id_onload) is already on the page — Google reads only one per page */
    private $onloadRendered = false;

    /**
     * Hooks the module registers on. Admin does not pick a hook (unlike
     * other SIMPLE-family modules) — each hook has a distinct role and is
     * toggled by the ASGA_ENABLE_* switches. Listed here for transparency.
     *
     * @return array
     */
    public static function getAvailableHooks()
    {
        return [
            'displayHeader' => 'Ładuje bibliotekę Google Identity Services na stronach logowania i konta klienta',
            'displayCustomerLoginFormAfter' => 'Przycisk Google pod formularzem logowania',
            'displayCustomerAccountForm' => 'Przycisk Google pod formularzem rejestracji',
            'actionOutputHTMLBefore' => 'Przycisk Google w zakładce logowania w kroku zamówienia (motyw nie ma tam hooka)',
        ];
    }

    public function __construct()
    {
        $this->name = 'apline_simple_google_auth';
        $this->tab = 'front_office_features';
        $this->version = '1.1.5';
        $this->author = 'Arkadiusz Pielechowski';
        $this->need_instance = false;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('APLINE Simple Google Auth — logowanie przez Google', [], self::L10N);
        $this->description = $this->trans('Dodaje przycisk „Kontynuuj z Google” na stronach logowania i rejestracji. Sprawdza token Google po stronie serwera, a potem loguje klienta albo zakłada mu konto.', [], self::L10N);
        $this->confirmUninstall = $this->trans('Na pewno odinstalować? Tabela powiązań z kontami Google zostanie usunięta (konta klientów zostają, znikają tylko powiązania z Google).', [], self::L10N);

        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => _PS_VERSION_];
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        if (!$this->installDb()
            || !$this->installConfiguration()
            || !$this->installHooks()
        ) {
            // Roll back to a clean state so the shop is never left half-installed.
            $this->uninstall();
            $this->_errors[] = $this->trans('Instalacja nie powiodła się i została wycofana. Sprawdź silnik bazy danych (zalecany InnoDB) i spróbuj ponownie.', [], self::L10N);

            return false;
        }

        return true;
    }

    public function uninstall()
    {
        // Each step is idempotent; uninstall must not fail because something is already gone.
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::LINK_TABLE . '`');

        foreach ($this->getConfigurationKeys() as $key) {
            Configuration::deleteByName($key);
        }

        // Hooks are unregistered automatically by parent::uninstall().
        return parent::uninstall();
    }

    /**
     * Every Configuration key this module owns. Used by install (defaults)
     * and uninstall (cleanup) so the two lists can never drift apart.
     *
     * @return array
     */
    private function getConfigurationKeys()
    {
        return [
            self::CLIENT_ID,
            self::ENABLE_LOGIN,
            self::ENABLE_REGISTER,
            self::BUTTON_THEME,
            self::BUTTON_SIZE,
            self::BUTTON_TEXT,
            self::BUTTON_SHAPE,
            self::AUTO_PROMPT,
            self::AUTO_LINK_EXISTING,
            self::NOTIFY_EMAIL_ON_LINK,
            self::PLACEHOLDER,
            self::PLACEHOLDER_LARGE,
            self::PLACEHOLDER_INFO,
            self::CONSENT_NOTE,
            self::CONSENT_TERMS_CMS,
            self::CONSENT_PRIVACY_CMS,
            self::CHECKOUT_LOGIN,
            self::JWKS_CACHE,
            self::JWKS_EXPIRES,
        ];
    }

    /**
     * Create the id_customer <-> google_sub mapping table.
     *
     * Primary attempt uses a hard InnoDB FOREIGN KEY (ON DELETE CASCADE)
     * so deleting a customer removes their link automatically. If that
     * fails — non-InnoDB ps_customer, collation mismatch, restricted
     * grants — we fall back to the same table without the constraint.
     * Orphan link rows are harmless: the callback always re-validates the
     * Customer object before logging anyone in.
     *
     * @return bool
     */
    private function installDb()
    {
        $prefix = _DB_PREFIX_;

        $columns = '
            `id_asga_customer_link` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_customer` INT UNSIGNED NOT NULL,
            `google_sub` VARCHAR(255) NOT NULL,
            `email_at_link` VARCHAR(255) NOT NULL,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_asga_customer_link`),
            UNIQUE KEY `uq_google_sub` (`google_sub`),
            UNIQUE KEY `uq_customer` (`id_customer`),
            KEY `idx_email` (`email_at_link`)';

        $withFk = 'CREATE TABLE IF NOT EXISTS `' . $prefix . self::LINK_TABLE . '` (' . $columns . ',
            CONSTRAINT `fk_asga_link_customer`
                FOREIGN KEY (`id_customer`)
                REFERENCES `' . $prefix . 'customer` (`id_customer`)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;';

        try {
            if (Db::getInstance()->execute($withFk) && $this->tableExists(self::LINK_TABLE)) {
                return true;
            }
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('apline_simple_google_auth: FK table create failed, retrying without FK: ' . $e->getMessage(), 2);
        }

        $noFk = 'CREATE TABLE IF NOT EXISTS `' . $prefix . self::LINK_TABLE . '` (' . $columns . ',
            KEY `idx_customer` (`id_customer`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        return (bool) Db::getInstance()->execute($noFk);
    }

    /**
     * @param string $name table name without the DB prefix
     *
     * @return bool
     */
    public function tableExists($name)
    {
        // executeS() without cache: getValue() appends "LIMIT 1", which MySQL and
        // MariaDB reject after SHOW TABLES (the configuration page failed with SQL 1064).
        return (bool) Db::getInstance()->executeS(
            'SHOW TABLES LIKE \'' . _DB_PREFIX_ . pSQL($name) . '\'',
            true,
            false
        );
    }

    /**
     * @return bool
     */
    private function installConfiguration()
    {
        return Configuration::updateValue(self::CLIENT_ID, '')
            && Configuration::updateValue(self::ENABLE_LOGIN, 1)
            && Configuration::updateValue(self::ENABLE_REGISTER, 1)
            && Configuration::updateValue(self::BUTTON_THEME, 'outline')
            && Configuration::updateValue(self::BUTTON_SIZE, 'large')
            && Configuration::updateValue(self::BUTTON_TEXT, 'continue_with')
            && Configuration::updateValue(self::BUTTON_SHAPE, 'rectangular')
            && Configuration::updateValue(self::AUTO_PROMPT, 0)
            && Configuration::updateValue(self::AUTO_LINK_EXISTING, 1)
            && Configuration::updateValue(self::NOTIFY_EMAIL_ON_LINK, 1)
            // New install: no placeholder until the shop decides otherwise (neutral default).
            && Configuration::updateValue(self::PLACEHOLDER, 0)
            && Configuration::updateValue(self::PLACEHOLDER_LARGE, 0)
            && Configuration::updateValue(self::PLACEHOLDER_INFO, $this->buildLangValues(self::DEFAULT_PLACEHOLDER_INFO))
            && Configuration::updateValue(self::CONSENT_NOTE, 1)
            && Configuration::updateValue(self::CONSENT_TERMS_CMS, (int) Configuration::get('PS_CONDITIONS_CMS_ID'))
            && Configuration::updateValue(self::CONSENT_PRIVACY_CMS, $this->guessPrivacyCmsId())
            && Configuration::updateValue(self::CHECKOUT_LOGIN, 1)
            && Configuration::updateValue(self::JWKS_CACHE, '')
            && Configuration::updateValue(self::JWKS_EXPIRES, 0);
    }

    /**
     * Best guess for the privacy policy page: an active CMS page whose
     * friendly URL starts with one of the usual names. 0 when nothing
     * matches — the shop then picks the page in the configuration form.
     * (Copied in upgrade-1.1.5.php.)
     *
     * @return int
     */
    private function guessPrivacyCmsId()
    {
        return (int) Db::getInstance()->getValue(
            'SELECT cl.`id_cms` FROM `' . _DB_PREFIX_ . 'cms_lang` cl
             INNER JOIN `' . _DB_PREFIX_ . 'cms` c ON c.`id_cms` = cl.`id_cms` AND c.`active` = 1
             WHERE cl.`link_rewrite` LIKE \'polityka-prywatnosci%\' OR cl.`link_rewrite` LIKE \'privacy%\'
             ORDER BY cl.`id_cms` ASC'
        );
    }

    /**
     * Same text for every language, ready for a multilingual Configuration key.
     *
     * @param string $text
     *
     * @return array [id_lang => text]
     */
    private function buildLangValues($text)
    {
        $values = [];
        foreach (Language::getLanguages(false) as $lang) {
            $values[(int) $lang['id_lang']] = (string) $text;
        }

        return $values;
    }

    /**
     * @return bool
     */
    private function installHooks()
    {
        $ok = true;
        foreach (array_keys(self::getAvailableHooks()) as $hook) {
            $ok = $ok && $this->registerHook($hook);
        }

        return $ok;
    }

    /* ------------------------------------------------------------------ *
     *  Admin configuration page (getContent) — no hidden Tab, no list.    *
     * ------------------------------------------------------------------ */

    public function getContent()
    {
        $output = '';

        $this->context->controller->addCSS($this->_path . 'views/css/admin.css');

        if (Tools::isSubmit('submitAsgaConfig')) {
            $output .= $this->processConfigForm();
        }

        $this->context->smarty->assign([
            'asga_shop_url' => Tools::getShopDomainSsl(true),
            'asga_callback_urls' => $this->getCallbackUrlsForAllLanguages(),
            'asga_health' => $this->buildHealthChecks(),
        ]);

        $output .= $this->display(__FILE__, 'views/templates/admin/configure.tpl');

        return $output . $this->renderConfigForm() . $this->renderLikeBox() . $this->renderAplineFooter();
    }

    /**
     * Validate and persist the configuration form. Rejects bad input
     * (never silently truncates) and keeps the entered values on error.
     *
     * @return string error/confirmation HTML
     */
    private function processConfigForm()
    {
        $errors = [];

        // An empty Client ID is allowed: the Google button then stays hidden
        // (or the placeholder is shown, when enabled).
        $clientId = trim((string) Tools::getValue(self::CLIENT_ID));
        if ($clientId !== '' && !preg_match('/^[0-9]+-[a-zA-Z0-9_-]+\.apps\.googleusercontent\.com$/', $clientId)) {
            $errors[] = $this->trans('Nieprawidłowy format identyfikatora klienta. Powinien wyglądać tak: 123456789-abc.apps.googleusercontent.com', [], self::L10N);
        }

        // Whitelist-validate every enum-like button option.
        $options = $this->getButtonOptions();
        $enums = [];
        foreach (array_keys($options) as $key) {
            $value = (string) Tools::getValue($key);
            if (!array_key_exists($value, $options[$key])) {
                $errors[] = $this->trans('Wybrano niedozwoloną wartość w jednej z opcji przycisku.', [], self::L10N);
            } else {
                $enums[$key] = $value;
            }
        }

        // Multilingual placeholder message.
        $infos = [];
        $tooLong = false;
        foreach (Language::getLanguages(false) as $lang) {
            $idLang = (int) $lang['id_lang'];
            $text = trim((string) Tools::getValue(self::PLACEHOLDER_INFO . '_' . $idLang));
            if (mb_strlen($text, 'UTF-8') > self::PLACEHOLDER_INFO_MAX) {
                $tooLong = true;
            }
            $infos[$idLang] = $text;
        }
        if ($tooLong) {
            $errors[] = $this->trans('Komunikat zaślepki może mieć najwyżej %max% znaków.', ['%max%' => self::PLACEHOLDER_INFO_MAX], self::L10N);
        }

        // Pages linked from the note under the button: 0 (no link) or an existing CMS page.
        $cmsOptions = $this->getCmsOptions();
        $cmsIds = [];
        foreach ([self::CONSENT_TERMS_CMS, self::CONSENT_PRIVACY_CMS] as $key) {
            $idCms = (int) Tools::getValue($key);
            if (!array_key_exists($idCms, $cmsOptions)) {
                $errors[] = $this->trans('Wybrana strona regulaminu albo polityki prywatności nie istnieje.', [], self::L10N);
            } else {
                $cmsIds[$key] = $idCms;
            }
        }

        if (!empty($errors)) {
            $out = '';
            foreach (array_unique($errors) as $message) {
                $out .= $this->displayError($message);
            }

            return $out;
        }

        Configuration::updateValue(self::CLIENT_ID, $clientId);
        Configuration::updateValue(self::ENABLE_LOGIN, (int) (bool) Tools::getValue(self::ENABLE_LOGIN));
        Configuration::updateValue(self::ENABLE_REGISTER, (int) (bool) Tools::getValue(self::ENABLE_REGISTER));
        Configuration::updateValue(self::AUTO_PROMPT, (int) (bool) Tools::getValue(self::AUTO_PROMPT));
        Configuration::updateValue(self::AUTO_LINK_EXISTING, (int) (bool) Tools::getValue(self::AUTO_LINK_EXISTING));
        Configuration::updateValue(self::NOTIFY_EMAIL_ON_LINK, (int) (bool) Tools::getValue(self::NOTIFY_EMAIL_ON_LINK));
        Configuration::updateValue(self::PLACEHOLDER, (int) (bool) Tools::getValue(self::PLACEHOLDER));
        Configuration::updateValue(self::PLACEHOLDER_LARGE, (int) (bool) Tools::getValue(self::PLACEHOLDER_LARGE));
        Configuration::updateValue(self::PLACEHOLDER_INFO, $infos);
        Configuration::updateValue(self::CONSENT_NOTE, (int) (bool) Tools::getValue(self::CONSENT_NOTE));
        Configuration::updateValue(self::CHECKOUT_LOGIN, (int) (bool) Tools::getValue(self::CHECKOUT_LOGIN));
        foreach ($cmsIds as $key => $idCms) {
            Configuration::updateValue($key, $idCms);
        }
        foreach ($enums as $key => $value) {
            Configuration::updateValue($key, $value);
        }

        return $this->displayConfirmation($this->trans('Ustawienia zapisane.', [], self::L10N));
    }

    /**
     * Allowed values for each enum-like button setting. Single source of
     * truth shared by validation (processConfigForm) and the form's select
     * options (renderConfigForm). Values follow Google Identity Services
     * data-* attribute vocabulary.
     *
     * @return array
     */
    private function getButtonOptions()
    {
        return [
            self::BUTTON_THEME => [
                'outline' => $this->trans('Biały z obramowaniem', [], self::L10N),
                'filled_blue' => $this->trans('Niebieski', [], self::L10N),
                'filled_black' => $this->trans('Czarny', [], self::L10N),
            ],
            self::BUTTON_SIZE => [
                'small' => $this->trans('Mały', [], self::L10N),
                'medium' => $this->trans('Średni', [], self::L10N),
                'large' => $this->trans('Duży', [], self::L10N),
            ],
            self::BUTTON_TEXT => [
                'signin_with' => $this->trans('Zaloguj się przez Google', [], self::L10N),
                'signup_with' => $this->trans('Zarejestruj się przez Google', [], self::L10N),
                'continue_with' => $this->trans('Kontynuuj z Google', [], self::L10N),
                'signin' => $this->trans('Zaloguj się', [], self::L10N),
            ],
            self::BUTTON_SHAPE => [
                'rectangular' => $this->trans('Prostokątny', [], self::L10N),
                'pill' => $this->trans('Zaokrąglony', [], self::L10N),
            ],
        ];
    }

    /**
     * CMS pages that the note under the button can link to.
     *
     * @return array [id_cms => title], 0 = no link
     */
    private function getCmsOptions()
    {
        $options = [0 => $this->trans('— bez linku —', [], self::L10N)];
        foreach ((array) CMS::listCms((int) $this->context->language->id) as $page) {
            $options[(int) $page['id_cms']] = (string) $page['meta_title'];
        }

        return $options;
    }

    /**
     * Read-only diagnostics shown on the configuration page.
     *
     * @return array list of ['status' => 'ok'|'warning', 'message' => '...']
     */
    private function buildHealthChecks()
    {
        $checks = [];

        $checks[] = $this->tableExists(self::LINK_TABLE)
            ? ['status' => 'ok', 'message' => $this->trans('Tabela powiązań klientów z Google istnieje.', [], self::L10N)]
            : ['status' => 'warning', 'message' => $this->trans('Brak tabeli powiązań klientów z Google — spróbuj zainstalować moduł ponownie.', [], self::L10N)];

        $checks[] = class_exists('Firebase\\JWT\\JWT')
            ? ['status' => 'ok', 'message' => $this->trans('Biblioteka firebase/php-jwt jest załadowana.', [], self::L10N)]
            : ['status' => 'warning', 'message' => $this->trans('Nie udało się załadować biblioteki firebase/php-jwt — prawdopodobnie brakuje katalogu vendor/.', [], self::L10N)];

        if (Configuration::get(self::CLIENT_ID)) {
            $checks[] = ['status' => 'ok', 'message' => $this->trans('Identyfikator klienta Google jest ustawiony.', [], self::L10N)];
        } elseif ((int) Configuration::get(self::PLACEHOLDER)) {
            $checks[] = ['status' => 'warning', 'message' => $this->trans('Brak identyfikatora klienta Google — klienci widzą nieaktywną zaślepkę zamiast przycisku Google.', [], self::L10N)];
        } else {
            $checks[] = ['status' => 'warning', 'message' => $this->trans('Brak identyfikatora klienta Google — przycisk jest ukryty, dopóki nie zapiszesz identyfikatora poniżej.', [], self::L10N)];
        }

        $checks[] = Module::isInstalled('psgdpr')
            ? ['status' => 'ok', 'message' => $this->trans('Moduł zgód RODO (psgdpr) jest zainstalowany.', [], self::L10N)]
            : ['status' => 'warning', 'message' => $this->trans('Moduł psgdpr nie jest zainstalowany — ten moduł nie zbiera zgód RODO przy rejestracji przez Google. Przed startem zainstaluj psgdpr albo inny moduł zgód.', [], self::L10N)];

        $checks[] = (bool) Configuration::get('PS_SSL_ENABLED')
            ? ['status' => 'ok', 'message' => $this->trans('Sklep działa przez HTTPS.', [], self::L10N)]
            : ['status' => 'warning', 'message' => $this->trans('Sklep nie działa przez HTTPS — bez HTTPS logowanie przez Google nie zadziała.', [], self::L10N)];

        return $checks;
    }

    /**
     * Inactive "Continue with Google" button shown while no Client ID is
     * configured and the placeholder is enabled: customers see the feature
     * is planned, a click reveals the configured message. No Google script
     * is loaded in that state.
     *
     * @param string $context 'login' or 'register'
     *
     * @return string
     */
    private function renderPlaceholderButton($context)
    {
        $info = trim((string) Configuration::get(self::PLACEHOLDER_INFO, (int) $this->context->language->id));
        if ($info === '') {
            $info = trim((string) Configuration::get(self::PLACEHOLDER_INFO, (int) Configuration::get('PS_LANG_DEFAULT')));
        }

        $this->context->smarty->assign([
            'asga_ph_label' => $this->trans('Kontynuuj z Google', [], self::L10N_SHOP),
            'asga_ph_info' => $info,
            'asga_ph_large' => (int) Configuration::get(self::PLACEHOLDER_LARGE),
            'asga_context' => $context,
        ]);

        return $this->display(__FILE__, 'views/templates/hook/button_placeholder.tpl');
    }

    /**
     * @param int|null $idLang language of the URL (null = current context)
     *
     * @return string the public callback URL the GIS button posts to
     */
    private function getCallbackUrl($idLang = null)
    {
        return $this->context->link->getModuleLink($this->name, 'callback', [], true, $idLang);
    }

    /**
     * Callback URL for every active language. With friendly URLs and several
     * languages the URL carries a language prefix, and each variant must be
     * listed as an authorized redirect URI in the Google Cloud Console.
     *
     * @return array unique URLs
     */
    private function getCallbackUrlsForAllLanguages()
    {
        $urls = [];
        foreach (Language::getLanguages(true, (int) $this->context->shop->id) as $lang) {
            $urls[] = $this->getCallbackUrl((int) $lang['id_lang']);
        }
        if (empty($urls)) {
            $urls[] = $this->getCallbackUrl();
        }

        return array_values(array_unique($urls));
    }

    /**
     * Build the configuration form via HelperForm.
     *
     * @return string
     */
    private function renderConfigForm()
    {
        $options = $this->getButtonOptions();

        $toQuery = function ($map) {
            $query = [];
            foreach ($map as $id => $label) {
                $query[] = ['id' => $id, 'name' => $label];
            }

            return $query;
        };

        $switch = function ($name, $label, $desc = '') {
            return [
                'type' => 'switch',
                'label' => $label,
                'name' => $name,
                'is_bool' => true,
                'desc' => $desc,
                'values' => [
                    ['id' => $name . '_on', 'value' => 1, 'label' => $this->trans('Tak', [], self::L10N)],
                    ['id' => $name . '_off', 'value' => 0, 'label' => $this->trans('Nie', [], self::L10N)],
                ],
            ];
        };

        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Ustawienia logowania przez Google', [], self::L10N),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->trans('Identyfikator klienta Google (Client ID)', [], self::L10N),
                        'name' => self::CLIENT_ID,
                        'desc' => $this->trans('Wklej go z Google Cloud Console. Wygląda tak: 123456789-abc.apps.googleusercontent.com. Bez identyfikatora przycisk Google się nie wyświetla.', [], self::L10N),
                    ],
                    $switch(self::ENABLE_LOGIN, $this->trans('Pokazuj na stronie logowania', [], self::L10N)),
                    $switch(self::CHECKOUT_LOGIN, $this->trans('Pokazuj w zakładce logowania w zamówieniu', [], self::L10N), $this->trans('W kroku zamówienia motyw nie ma miejsca na przycisk w zakładce „Zaloguj się”, więc moduł dopisuje go do strony pod formularzem logowania. Działa razem z „Pokazuj na stronie logowania”. Wyłącz, jeśli Twój motyw sam pokazuje tam przycisk albo układ zakładki wygląda źle.', [], self::L10N)),
                    $switch(self::ENABLE_REGISTER, $this->trans('Pokazuj w formularzu rejestracji', [], self::L10N)),
                    [
                        'type' => 'select',
                        'label' => $this->trans('Kolor przycisku', [], self::L10N),
                        'name' => self::BUTTON_THEME,
                        'options' => ['query' => $toQuery($options[self::BUTTON_THEME]), 'id' => 'id', 'name' => 'name'],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->trans('Rozmiar przycisku', [], self::L10N),
                        'name' => self::BUTTON_SIZE,
                        'options' => ['query' => $toQuery($options[self::BUTTON_SIZE]), 'id' => 'id', 'name' => 'name'],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->trans('Tekst przycisku', [], self::L10N),
                        'name' => self::BUTTON_TEXT,
                        'desc' => $this->trans('Napis rysuje Google w języku sklepu.', [], self::L10N),
                        'options' => ['query' => $toQuery($options[self::BUTTON_TEXT]), 'id' => 'id', 'name' => 'name'],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->trans('Kształt przycisku', [], self::L10N),
                        'name' => self::BUTTON_SHAPE,
                        'options' => ['query' => $toQuery($options[self::BUTTON_SHAPE]), 'id' => 'id', 'name' => 'name'],
                    ],
                    $switch(self::AUTO_PROMPT, $this->trans('Okienko One Tap', [], self::L10N), $this->trans('Google sam podpowiada logowanie w okienku na stronach z przyciskiem Google. Może przeszkadzać klientom.', [], self::L10N)),
                    $switch(self::AUTO_LINK_EXISTING, $this->trans('Łącz istniejące konta automatycznie', [], self::L10N), $this->trans('Gdy e-mail z Google należy do istniejącego klienta, konto zostaje połączone z Google i klient się loguje. Dotyczy tylko adresów, za które ręczy Google: @gmail.com i kont Google Workspace. Dotychczasowe hasło konta przestaje wtedy działać — klient ustawia nowe przez „Nie pamiętasz hasła?”. Po wyłączeniu, a także przy każdym innym adresie, klient musi zalogować się e-mailem i hasłem.', [], self::L10N)),
                    $switch(self::NOTIFY_EMAIL_ON_LINK, $this->trans('Powiadomienie o połączeniu konta', [], self::L10N), $this->trans('Wysyłaj klientowi e-mail, gdy jego istniejące konto zostanie automatycznie połączone z Google. Wiadomość mówi też, że dotychczasowe hasło przestało działać — bez niej klient się o tym nie dowie.', [], self::L10N)),
                    $switch(self::CONSENT_NOTE, $this->trans('Informacja o regulaminie pod przyciskiem', [], self::L10N), $this->trans('Pod przyciskiem Google pokazuje zdanie „Kontynuując z Google, akceptujesz Regulamin i Politykę prywatności sklepu.”. Konto zakładane przez Google omija formularz rejestracji ze zgodami, dlatego to zdanie powinno zostać włączone.', [], self::L10N)),
                    [
                        'type' => 'select',
                        'label' => $this->trans('Strona regulaminu', [], self::L10N),
                        'name' => self::CONSENT_TERMS_CMS,
                        'desc' => $this->trans('Do tej strony prowadzi słowo „Regulamin” w zdaniu pod przyciskiem.', [], self::L10N),
                        'options' => ['query' => $toQuery($this->getCmsOptions()), 'id' => 'id', 'name' => 'name'],
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->trans('Strona polityki prywatności', [], self::L10N),
                        'name' => self::CONSENT_PRIVACY_CMS,
                        'desc' => $this->trans('Do tej strony prowadzą słowa „Politykę prywatności” w zdaniu pod przyciskiem.', [], self::L10N),
                        'options' => ['query' => $toQuery($this->getCmsOptions()), 'id' => 'id', 'name' => 'name'],
                    ],
                    $switch(self::PLACEHOLDER, $this->trans('Zaślepka bez identyfikatora', [], self::L10N), $this->trans('Dopóki nie ma identyfikatora klienta, klienci widzą nieaktywny przycisk „Kontynuuj z Google”, a po kliknięciu komunikat poniżej. Skrypt Google nie jest wtedy ładowany.', [], self::L10N)),
                    $switch(self::PLACEHOLDER_LARGE, $this->trans('Duża zaślepka', [], self::L10N), $this->trans('Przycisk wysokości 72 px z napisem 22 px — czytelniejszy dla starszych klientów. Po wyłączeniu zaślepka ma rozmiar zbliżony do przycisku Google.', [], self::L10N)),
                    [
                        'type' => 'textarea',
                        'label' => $this->trans('Komunikat zaślepki', [], self::L10N),
                        'name' => self::PLACEHOLDER_INFO,
                        'lang' => true,
                        'rows' => 3,
                        'desc' => $this->trans('Pokazuje się po kliknięciu nieaktywnego przycisku. Bez znaczników HTML, najwyżej 500 znaków.', [], self::L10N),
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Zapisz ustawienia', [], self::L10N),
                    'class' => 'btn btn-primary btn-lg apline-btn-duzy pull-right',
                    'icon' => 'icon-save',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->identifier = $this->identifier;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitAsgaConfig';
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->languages = $this->context->controller->getLanguages();
        $helper->fields_value = [
            self::CLIENT_ID => $this->formValue(self::CLIENT_ID),
            self::ENABLE_LOGIN => $this->formValue(self::ENABLE_LOGIN),
            self::ENABLE_REGISTER => $this->formValue(self::ENABLE_REGISTER),
            self::BUTTON_THEME => $this->formValue(self::BUTTON_THEME),
            self::BUTTON_SIZE => $this->formValue(self::BUTTON_SIZE),
            self::BUTTON_TEXT => $this->formValue(self::BUTTON_TEXT),
            self::BUTTON_SHAPE => $this->formValue(self::BUTTON_SHAPE),
            self::AUTO_PROMPT => $this->formValue(self::AUTO_PROMPT),
            self::AUTO_LINK_EXISTING => $this->formValue(self::AUTO_LINK_EXISTING),
            self::NOTIFY_EMAIL_ON_LINK => $this->formValue(self::NOTIFY_EMAIL_ON_LINK),
            self::PLACEHOLDER => $this->formValue(self::PLACEHOLDER),
            self::PLACEHOLDER_LARGE => $this->formValue(self::PLACEHOLDER_LARGE),
            self::PLACEHOLDER_INFO => $this->langFormValue(self::PLACEHOLDER_INFO),
            self::CONSENT_NOTE => $this->formValue(self::CONSENT_NOTE),
            self::CHECKOUT_LOGIN => $this->formValue(self::CHECKOUT_LOGIN),
            self::CONSENT_TERMS_CMS => (int) $this->formValue(self::CONSENT_TERMS_CMS),
            self::CONSENT_PRIVACY_CMS => (int) $this->formValue(self::CONSENT_PRIVACY_CMS),
        ];

        return $helper->generateForm([$fields_form]);
    }

    /**
     * Current value for a form field: the just-submitted value when the
     * form posted (so a validation error keeps what the user typed),
     * otherwise the stored Configuration value.
     *
     * @param string $key
     *
     * @return mixed
     */
    private function formValue($key)
    {
        if (Tools::isSubmit('submitAsgaConfig')) {
            return Tools::getValue($key);
        }

        return Configuration::get($key);
    }

    /**
     * Multilingual variant of formValue().
     *
     * @param string $key
     *
     * @return array [id_lang => value]
     */
    private function langFormValue($key)
    {
        $submitted = Tools::isSubmit('submitAsgaConfig');
        $values = [];
        foreach (Language::getLanguages(false) as $lang) {
            $idLang = (int) $lang['id_lang'];
            $values[$idLang] = $submitted
                ? (string) Tools::getValue($key . '_' . $idLang)
                : (string) Configuration::get($key, $idLang);
        }

        return $values;
    }

    /**
     * Author credit with a link to https://pielechowski.pl, shown on the
     * configuration page. The module is MIT-licensed: the credit is kept by
     * default, it is not a license requirement.
     *
     * @return string
     */
    public function renderAplineFooter()
    {
        return '
        <style>
            .apline-credit { margin-top: 24px; font-size: 12px; opacity: 0.9; }
            .apline-credit a { font-weight: 600; }
        </style>
        <div class="apline-credit">
            ' . $this->trans('Moduł stworzony przez', [], self::L10N) . '
            <a href="https://pielechowski.pl" target="_blank" rel="noopener noreferrer">PIELECHOWSKI.PL</a>
        </div>';
    }

    /**
     * Subtle "need custom development?" box shown on the configuration page.
     *
     * @return string
     */
    public function renderLikeBox()
    {
        return '
        <div class="panel">
            <h3>&#9749; ' . $this->trans('Podoba Ci się ten moduł?', [], self::L10N) . '</h3>
            <p>' . $this->trans('Potrzebujesz modułu na zamówienie, przyspieszenia sklepu albo integracji z PrestaShop?', [], self::L10N) . '</p>
            <a class="btn btn-default" href="https://pielechowski.pl" target="_blank" rel="noopener noreferrer">&#8594; PIELECHOWSKI.PL</a>
        </div>';
    }

    /* ------------------------------------------------------------------ *
     *  Front-end rendering — load GIS and show the official button.       *
     * ------------------------------------------------------------------ */

    /**
     * Load the Google Identity Services library and the front stylesheet,
     * but only on the customer authentication pages — the GIS script has
     * no business loading on every shop page. Without a Client ID only the
     * stylesheet is loaded, and only when the placeholder is enabled.
     */
    public function hookDisplayHeader($params)
    {
        try {
            $allowed = ['authentication', 'registration', 'order', 'identity'];
            $self = isset($this->context->controller->php_self) ? $this->context->controller->php_self : '';
            if (!in_array($self, $allowed, true)) {
                return '';
            }

            $hasClientId = (bool) Configuration::get(self::CLIENT_ID);
            if (!$hasClientId && !(int) Configuration::get(self::PLACEHOLDER)) {
                return '';
            }

            $this->context->controller->registerStylesheet(
                'apline-simple-google-auth',
                'modules/' . $this->name . '/views/css/front.css'
            );

            // Without a Client ID the page shows an inactive placeholder button
            // (renderPlaceholderButton), so the GIS library is not needed.
            if (!$hasClientId) {
                return '';
            }

            $this->context->controller->registerJavascript(
                'apline-simple-google-auth-gis',
                'https://accounts.google.com/gsi/client',
                ['server' => 'remote', 'attributes' => ['async' => true, 'defer' => true], 'priority' => 150]
            );

            return '';
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('apline_simple_google_auth: ' . $e->getMessage(), 3);

            return '';
        }
    }

    public function hookDisplayCustomerLoginFormAfter($params)
    {
        try {
            if ($this->isCustomerLogged()) {
                return '';
            }
            // Show any sign-in error first (the callback redirects here on failure),
            // then the button itself (when enabled).
            $out = $this->renderAuthError();
            if ((int) Configuration::get(self::ENABLE_LOGIN)) {
                $out .= $this->renderGoogleButton('login');
            }

            return $out;
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('apline_simple_google_auth: ' . $e->getMessage(), 3);

            return '';
        }
    }

    public function hookDisplayCustomerAccountForm($params)
    {
        try {
            if (!(int) Configuration::get(self::ENABLE_REGISTER) || $this->isCustomerLogged()) {
                return '';
            }

            return $this->renderGoogleButton('register');
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('apline_simple_google_auth: ' . $e->getMessage(), 3);

            return '';
        }
    }

    /**
     * The checkout shows the login form in a tab of its own and themes have
     * no hook there (displayCustomerLoginFormAfter exists only on the login
     * page), so a returning customer would not see the Google button at all.
     * The button is added right after the login form of that tab. When the
     * tab is missing (customer signed in, different theme markup) or the
     * theme already shows the button there, the page is left untouched.
     */
    public function hookActionOutputHTMLBefore($params)
    {
        try {
            if (!isset($params['html']) || !is_string($params['html'])) {
                return;
            }
            $self = isset($this->context->controller->php_self) ? (string) $this->context->controller->php_self : '';
            if ($self !== 'order' || !(int) Configuration::get(self::ENABLE_LOGIN) || $this->isCustomerLogged()) {
                return;
            }
            // A missing key means the files are newer than the database (upgrade not run yet): enabled.
            $checkoutLogin = Configuration::get(self::CHECKOUT_LOGIN);
            if ($checkoutLogin !== false && !(int) $checkoutLogin) {
                return;
            }

            $html = &$params['html'];
            $tab = strpos($html, 'id="checkout-login-form"');
            $end = $tab === false ? false : strpos($html, '</form>', $tab);
            if ($end === false || strpos(substr($html, $tab, $end - $tab), 'asga-wrapper') !== false) {
                return;
            }

            $button = $this->renderGoogleButton('login');
            if ($button === '') {
                return;
            }
            $end += strlen('</form>');
            $html = substr($html, 0, $end) . $button . substr($html, $end);
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('apline_simple_google_auth: ' . get_class($e), 3);
        }
    }

    /**
     * @return bool true when a customer is already signed in (the button must not be shown then)
     */
    private function isCustomerLogged()
    {
        return isset($this->context->customer)
            && $this->context->customer instanceof Customer
            && $this->context->customer->isLogged();
    }

    /**
     * Where the customer should land after signing in: the checkout when the
     * button was clicked there, otherwise the `back` target of the login page.
     * The value travels through Google as the `state` of the button and is
     * validated again in the callback - it is customer-controlled data.
     *
     * @return string
     */
    private function getReturnTarget()
    {
        $self = isset($this->context->controller->php_self) ? (string) $this->context->controller->php_self : '';
        if ($self === 'order') {
            return 'order';
        }
        $back = (string) Tools::getValue('back');

        return Tools::strlen($back) <= 500 ? $back : '';
    }

    /**
     * Render the official Google button. GIS draws the button itself from
     * these data-* attributes (Google brand guidelines forbid a custom
     * design), so we only provide configuration, never styling.
     *
     * @param string $context 'login' or 'register'
     *
     * @return string
     */
    private function renderGoogleButton($context)
    {
        $clientId = Configuration::get(self::CLIENT_ID);
        if (!$clientId) {
            return (int) Configuration::get(self::PLACEHOLDER) ? $this->renderPlaceholderButton($context) : '';
        }

        $this->context->smarty->assign([
            'asga_client_id' => $clientId,
            'asga_callback_url' => $this->getCallbackUrl(),
            'asga_theme' => Configuration::get(self::BUTTON_THEME) ?: 'outline',
            'asga_size' => Configuration::get(self::BUTTON_SIZE) ?: 'large',
            'asga_text' => Configuration::get(self::BUTTON_TEXT) ?: 'continue_with',
            'asga_shape' => Configuration::get(self::BUTTON_SHAPE) ?: 'rectangular',
            'asga_auto_prompt' => (int) Configuration::get(self::AUTO_PROMPT),
            'asga_context' => $context,
            'asga_state' => $this->getReturnTarget(),
            // Google reads a single g_id_onload per page; further buttons reuse it.
            'asga_render_onload' => !$this->onloadRendered,
            'asga_consent' => $this->buildConsentNote(),
            // GIS data-context only accepts signin/signup/use, not our login/register labels.
            'asga_gis_context' => ($context === 'register' ? 'signup' : 'signin'),
            // Button text in the shop language instead of the visitor's browser language.
            'asga_locale' => isset($this->context->language->iso_code) ? (string) $this->context->language->iso_code : '',
        ]);

        $this->onloadRendered = true;

        return $this->display(__FILE__, 'views/templates/hook/button.tpl');
    }

    /**
     * Sentence shown under the button: an account created with Google skips
     * the registration form and its consent checkboxes, so the customer is
     * told here what continuing means. Returns ready HTML (texts escaped,
     * links built from the CMS pages chosen in the configuration) or '' when
     * the note is switched off.
     *
     * A missing key means the files are newer than the database (the upgrade
     * was not run yet) — the note is then shown with the shop's own terms page.
     *
     * @return string
     */
    private function buildConsentNote()
    {
        $enabled = Configuration::get(self::CONSENT_NOTE);
        if ($enabled !== false && !(int) $enabled) {
            return '';
        }

        $termsId = Configuration::get(self::CONSENT_TERMS_CMS);
        if ($termsId === false) {
            $termsId = Configuration::get('PS_CONDITIONS_CMS_ID');
        }

        $text = htmlspecialchars(
            $this->trans('Kontynuując z Google, akceptujesz %terms% i %privacy% sklepu.', [], self::L10N_SHOP),
            ENT_QUOTES,
            'UTF-8'
        );

        return strtr($text, [
            '%terms%' => $this->buildCmsAnchor((int) $termsId, $this->trans('Regulamin', [], self::L10N_SHOP)),
            '%privacy%' => $this->buildCmsAnchor(
                (int) Configuration::get(self::CONSENT_PRIVACY_CMS),
                $this->trans('Politykę prywatności', [], self::L10N_SHOP)
            ),
        ]);
    }

    /**
     * @param int    $idCms CMS page, 0 = none
     * @param string $label
     *
     * @return string link to an active CMS page (new tab, so the customer does not lose the form), or the bare label
     */
    private function buildCmsAnchor($idCms, $label)
    {
        $label = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        if ($idCms <= 0) {
            return $label;
        }
        $idLang = (int) $this->context->language->id;
        $cms = new CMS($idCms, $idLang);
        if (!Validate::isLoadedObject($cms) || !$cms->active) {
            return $label;
        }
        $url = (string) $this->context->link->getCMSLink($cms, null, true, $idLang);

        return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">' . $label . '</a>';
    }

    /* ------------------------------------------------------------------ *
     *  JWT verification — the security core of the module.                *
     * ------------------------------------------------------------------ */

    /**
     * Verify a Google ID token (JWT) server-side and return its payload.
     *
     * Signature (RS256), expiry, nbf and iat are verified by the bundled
     * firebase/php-jwt library against Google's public keys (JWKS). On top
     * of that we validate the issuer, the audience (must equal our Client
     * ID) and that the email is verified by Google. Any failure — bad
     * format, bad signature, expired, wrong audience, unverified email,
     * unreachable JWKS — returns null without leaking which check failed.
     *
     * @param string $credential the raw JWT from the GIS button
     *
     * @return \stdClass|null validated claims, or null on any failure
     */
    public function verifyJwt($credential)
    {
        try {
            if (!is_string($credential) || $credential === '' || substr_count($credential, '.') !== 2) {
                return null;
            }

            $jwks = $this->getGoogleJwks();
            if (!$jwks) {
                PrestaShopLogger::addLog('apline_simple_google_auth: could not load Google JWKS', 3);

                return null;
            }

            $keys = \Firebase\JWT\JWK::parseKeySet($jwks, 'RS256');

            // 30s leeway absorbs clock skew on exp/iat/nbf checks.
            \Firebase\JWT\JWT::$leeway = 30;
            $payload = \Firebase\JWT\JWT::decode($credential, $keys);

            $clientId = (string) Configuration::get(self::CLIENT_ID);
            if ($clientId === '') {
                return null;
            }

            // Issuer must be Google.
            $validIssuers = ['accounts.google.com', 'https://accounts.google.com'];
            if (!isset($payload->iss) || !in_array($payload->iss, $validIssuers, true)) {
                return null;
            }

            // Audience must be exactly our Client ID (constant-time compare).
            if (!isset($payload->aud) || !hash_equals($clientId, (string) $payload->aud)) {
                return null;
            }

            // exp must be present (decode only enforces it when set).
            if (!isset($payload->exp)) {
                return null;
            }

            // A stable subject identifier is required — it is the link key.
            if (empty($payload->sub)) {
                return null;
            }

            // Email must be present and verified by Google.
            if (empty($payload->email) || !$this->isClaimTruthy($payload, 'email_verified')) {
                return null;
            }

            return $payload;
        } catch (\Throwable $e) {
            // Includes SignatureInvalidException, ExpiredException, BeforeValidException, etc.
            PrestaShopLogger::addLog('apline_simple_google_auth: JWT verification failed: ' . $e->getMessage(), 2);

            return null;
        }
    }

    /**
     * Google encodes booleans inconsistently (true vs "true"). Treat any of
     * true / "true" / 1 / "1" as truthy, everything else as false.
     *
     * @param \stdClass $payload
     * @param string    $claim
     *
     * @return bool
     */
    private function isClaimTruthy($payload, $claim)
    {
        if (!isset($payload->$claim)) {
            return false;
        }
        $value = $payload->$claim;

        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    /**
     * Fetch Google's JWKS (public signing keys), cached for 24h in
     * Configuration. On a network failure we deliberately fall back to the
     * previous cache (even if expired) — better to keep working than to
     * lock everyone out over a transient outage. Uses Tools::file_get_contents
     * so it works whether the host has allow_url_fopen or only cURL.
     *
     * @return array|null the decoded JWKS (with a non-empty 'keys'), or null
     */
    private function getGoogleJwks()
    {
        $cache = Configuration::get(self::JWKS_CACHE);
        $expires = (int) Configuration::get(self::JWKS_EXPIRES);

        if ($cache && $expires > time()) {
            $fresh = json_decode($cache, true);
            if (is_array($fresh) && !empty($fresh['keys'])) {
                return $fresh;
            }
        }

        $body = Tools::file_get_contents(
            'https://www.googleapis.com/oauth2/v3/certs',
            false,
            null,
            5
        );

        $jwks = is_string($body) && $body !== '' ? json_decode($body, true) : null;
        if (!is_array($jwks) || empty($jwks['keys'])) {
            // Network/parse failure: reuse the old cache rather than crash.
            $stale = json_decode((string) $cache, true);

            return (is_array($stale) && !empty($stale['keys'])) ? $stale : null;
        }

        Configuration::updateValue(self::JWKS_CACHE, $body);
        Configuration::updateValue(self::JWKS_EXPIRES, time() + 86400);

        return $jwks;
    }

    /* ------------------------------------------------------------------ *
     *  Customer matching / creation — called by the callback controller. *
     * ------------------------------------------------------------------ */

    /**
     * Resolve a verified Google payload to a logged-in customer, creating
     * one if needed. Never echoes anything — returns a result the
     * controller turns into a redirect.
     *
     * @param \stdClass $payload validated claims from verifyJwt()
     *
     * @return array ['success' => bool, 'error' => string|null]
     */
    public function loginOrRegister($payload)
    {
        $googleSub = (string) $payload->sub;
        $email = (string) $payload->email;
        $firstname = isset($payload->given_name) ? (string) $payload->given_name : '';
        $lastname = isset($payload->family_name) ? (string) $payload->family_name : '';

        // Step 1 — already linked to a customer?
        $idCustomer = (int) Db::getInstance()->getValue(
            'SELECT `id_customer` FROM `' . _DB_PREFIX_ . self::LINK_TABLE . '`
             WHERE `google_sub` = \'' . pSQL($googleSub) . '\''
        );
        if ($idCustomer) {
            $customer = new Customer($idCustomer);
            if ($this->canSignIn($customer)) {
                $this->loginCustomer($customer);

                return ['success' => true, 'error' => null];
            }

            return ['success' => false, 'error' => 'account_disabled'];
        }

        // Step 2 — an existing customer already uses this email?
        $existing = $this->findCustomerByEmail($email);
        if ($existing) {
            if (!$this->canSignIn($existing)) {
                return ['success' => false, 'error' => 'account_disabled'];
            }
            if (!(int) Configuration::get(self::AUTO_LINK_EXISTING) || !$this->isGoogleAuthoritative($payload)) {
                return ['success' => false, 'error' => 'email_taken'];
            }

            // Link first: when the link cannot be created (for example the account is
            // already linked to another Google account) nothing may change in the account.
            if (!$this->linkCustomer((int) $existing->id, $googleSub, $email)) {
                return ['success' => false, 'error' => 'internal_error'];
            }
            // A linked account must never keep its old password: undo the link when
            // the password cannot be replaced.
            try {
                $replaced = $this->replacePassword($existing);
            } catch (\Throwable $e) {
                $replaced = false;
            }
            if (!$replaced) {
                $this->unlinkCustomer((int) $existing->id, $googleSub);

                return ['success' => false, 'error' => 'internal_error'];
            }
            if ((int) Configuration::get(self::NOTIFY_EMAIL_ON_LINK)) {
                $this->sendLinkNotification($existing);
            }
            $this->loginCustomer($existing);

            return ['success' => true, 'error' => null];
        }

        // Step 3 — brand new customer.
        $customer = new Customer();
        $customer->firstname = $this->sanitizeName($firstname, self::FALLBACK_FIRSTNAME);
        $customer->lastname = $this->sanitizeName($lastname, self::FALLBACK_LASTNAME);
        $customer->email = $email;
        $customer->passwd = Tools::hash(Tools::passwdGen(32));
        $customer->active = 1;
        $customer->newsletter = 0; // default opt-out, GDPR-safe
        $customer->optin = 0;
        $customer->id_default_group = (int) Configuration::get('PS_CUSTOMER_GROUP');
        $customer->id_lang = (int) $this->context->language->id;
        $customer->id_shop = (int) $this->context->shop->id;
        $customer->id_shop_group = (int) $this->context->shop->id_shop_group;

        if (!Validate::isEmail($customer->email) || !$customer->add()) {
            // A double click sends two requests: the first one creates the account, the
            // second one fails on the duplicate e-mail. Sign in to the account that the
            // first request has just created and linked instead of reporting an error.
            $created = $this->findCustomerByEmail($email);
            $linkedId = (int) Db::getInstance()->getValue(
                'SELECT `id_customer` FROM `' . _DB_PREFIX_ . self::LINK_TABLE . '`
                 WHERE `google_sub` = \'' . pSQL($googleSub) . '\''
            );
            if ($created && $linkedId && (int) $created->id === $linkedId && $this->canSignIn($created)) {
                $this->loginCustomer($created);

                return ['success' => true, 'error' => null];
            }

            return ['success' => false, 'error' => 'cannot_create'];
        }

        if (!$this->linkCustomer((int) $customer->id, $googleSub, $email)) {
            return ['success' => false, 'error' => 'internal_error'];
        }

        // Same order as the native registration (CustomerPersister): sign in, welcome
        // e-mail, then the hook - modules listening to it expect a logged-in customer.
        $this->loginCustomer($customer);

        if (Configuration::get('PS_CUSTOMER_CREATION_EMAIL')) {
            try {
                $customer->sendWelcomeEmail((int) $customer->id_lang);
            } catch (\Throwable $e) {
                PrestaShopLogger::addLog('apline_simple_google_auth: welcome e-mail failed (' . get_class($e) . ')', 2);
            }
        }

        // Let passive modules (psgdpr, newsletter, ...) react to the new account.
        Hook::exec('actionCustomerAccountAdd', ['newCustomer' => $customer]);

        return ['success' => true, 'error' => null];
    }

    /**
     * Find an active (non-guest) customer by email in the current shop.
     *
     * @param string $email
     *
     * @return Customer|null
     */
    private function findCustomerByEmail($email)
    {
        if (!Validate::isEmail($email)) {
            return null;
        }

        $customer = new Customer();
        $found = $customer->getByEmail($email);
        if ($found && Validate::isLoadedObject($found)) {
            return $found;
        }

        return null;
    }

    /**
     * Google vouches for an address only when it runs the mailbox itself:
     * @gmail.com (with its @googlemail.com alias) and Google Workspace
     * accounts (`hd` claim). For any other address `email_verified` says no
     * more than that somebody once confirmed it, and Google's own guidance
     * is not to merge such a login into an existing account automatically.
     *
     * @param \stdClass $payload validated claims
     *
     * @return bool
     */
    private function isGoogleAuthoritative($payload)
    {
        if (isset($payload->hd) && is_string($payload->hd) && $payload->hd !== '') {
            return true;
        }

        return isset($payload->email) && preg_match('/@(gmail|googlemail)\.com$/i', (string) $payload->email) === 1;
    }

    /**
     * Give an existing account a random password before it is linked. The
     * shop does not verify e-mail addresses on registration, so the account
     * may have been created by someone else on this address — after linking,
     * that person's password, pending reset link and open sessions stop
     * working (Customer::isLogged() compares the cookie with the stored hash).
     * The owner sets a new password with the "forgot password" link.
     *
     * Written directly: Customer::update() validates every field and fails on
     * imported accounts holding data the current validators reject.
     *
     * @param Customer $customer
     *
     * @return bool
     */
    private function replacePassword(Customer $customer)
    {
        $hash = Tools::hash(Tools::passwdGen(32));
        $ok = (bool) Db::getInstance()->execute(
            'UPDATE `' . _DB_PREFIX_ . 'customer`
             SET `passwd` = \'' . pSQL($hash) . '\', `reset_password_token` = NULL, `reset_password_validity` = NULL
             WHERE `id_customer` = ' . (int) $customer->id
        );
        if ($ok) {
            $customer->passwd = $hash;
            $customer->removeResetPasswordToken();
        }

        return $ok;
    }

    /**
     * Insert a row into the id_customer <-> google_sub mapping table.
     *
     * @param int    $idCustomer
     * @param string $googleSub
     * @param string $email
     *
     * @return bool
     */
    private function linkCustomer($idCustomer, $googleSub, $email)
    {
        $now = date('Y-m-d H:i:s');

        return (bool) Db::getInstance()->insert(self::LINK_TABLE, [
            'id_customer' => (int) $idCustomer,
            'google_sub' => pSQL($googleSub),
            'email_at_link' => pSQL($email),
            'date_add' => $now,
            'date_upd' => $now,
        ]);
    }

    /**
     * Remove a link created a moment ago (rollback of an unfinished auto-link).
     *
     * @param int    $idCustomer
     * @param string $googleSub
     *
     * @return bool
     */
    private function unlinkCustomer($idCustomer, $googleSub)
    {
        return (bool) Db::getInstance()->delete(
            self::LINK_TABLE,
            '`id_customer` = ' . (int) $idCustomer . ' AND `google_sub` = \'' . pSQL($googleSub) . '\''
        );
    }

    /**
     * Coerce a Google name part into something PrestaShop's name validator
     * accepts, falling back to a safe default when it cannot.
     *
     * @param string $value
     * @param string $fallback
     *
     * @return string
     */
    private function sanitizeName($value, $fallback)
    {
        $value = trim((string) $value);
        if ($value !== '' && Validate::isCustomerName($value)) {
            return $value;
        }

        // Strip characters PrestaShop's isCustomerName() rejects, then retry.
        $cleaned = trim((string) preg_replace('/[0-9!<>,;?=+()@#"°{}_$%:\/\\\\*^\[\]|]/u', '', $value));
        if ($cleaned !== '' && Validate::isCustomerName($cleaned)) {
            return $cleaned;
        }

        return $fallback;
    }

    /**
     * Log a customer in exactly the way the native login form does
     * (CustomerLoginFormCore::submit()): Context::updateCustomer() fills the
     * cookie, attaches the cart and — crucially — registers the customer
     * session. Since PrestaShop 1.7.8 Customer::isLogged() requires a live
     * session (Cookie::isSessionAlive()); a cookie filled by hand without it
     * is treated as "not logged in" and the customer bounces back to the
     * login page.
     *
     * @param Customer $customer
     */
    private function loginCustomer(Customer $customer)
    {
        $this->context->updateCustomer($customer);

        Hook::exec('actionAuthentication', ['customer' => $this->context->customer]);

        // Login information has changed, so check that the cart rules still apply.
        CartRule::autoRemoveFromCart($this->context);
        CartRule::autoAddToCart($this->context);

        // updateCustomer() registers the session after its own write(); persist it explicitly.
        $this->context->cookie->write();
    }

    /**
     * Only a regular, active, non-deleted account may sign in with Google.
     * Customer::getByEmail() and new Customer($id) do not filter on `active`,
     * so a disabled account has to be rejected here.
     *
     * @param Customer $customer
     *
     * @return bool
     */
    private function canSignIn(Customer $customer)
    {
        return Validate::isLoadedObject($customer)
            && (int) $customer->active === 1
            && (int) $customer->deleted === 0
            && !$customer->isGuest();
    }

    /**
     * Send the "your account is now linked with Google" email. Wrapped in
     * its own try/catch so a mail failure can never break the login flow.
     *
     * Templates live in mails/pl/ (Polish source text). mails/en/ holds the
     * same Polish text on purpose: Mail::send() looks for the customer's
     * language, then the shop default language, then always 'en' — without
     * an 'en' folder a shop with no Polish language would not send the
     * email at all (and in debug mode Mail::send() calls die()).
     *
     * @param Customer $customer
     */
    private function sendLinkNotification(Customer $customer)
    {
        try {
            $langId = (int) $customer->id_lang;
            if (!$langId) {
                $langId = (int) $this->context->language->id;
            }

            Mail::Send(
                $langId,
                'account_linked',
                $this->trans('Twoje konto zostało połączone z kontem Google', [], self::L10N_SHOP),
                [
                    '{firstname}' => $customer->firstname,
                    '{lastname}' => $customer->lastname,
                    '{email}' => $customer->email,
                    '{shop_name}' => Configuration::get('PS_SHOP_NAME'),
                ],
                $customer->email,
                $customer->firstname . ' ' . $customer->lastname,
                null,
                null,
                null,
                null,
                _PS_MODULE_DIR_ . $this->name . '/mails/'
            );
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('apline_simple_google_auth: link notification email failed (' . get_class($e) . ')', 2);
        }
    }

    /**
     * Render a friendly alert for the ?asga_error=... code the callback may
     * append when redirecting back to the login page.
     *
     * @return string
     */
    private function renderAuthError()
    {
        $code = Tools::getValue('asga_error');
        if (!$code) {
            return '';
        }

        $failed = $this->trans('Nie udało się zalogować przez Google. Spróbuj ponownie albo zaloguj się adresem e-mail i hasłem.', [], self::L10N_SHOP);
        $messages = [
            'email_taken' => $this->trans('Konto z tym adresem e-mail już istnieje. Zaloguj się adresem e-mail i hasłem — jeśli go nie pamiętasz, użyj opcji „Nie pamiętasz hasła?”.', [], self::L10N_SHOP),
            'account_disabled' => $this->trans('To konto jest nieaktywne. Skontaktuj się ze sklepem.', [], self::L10N_SHOP),
            'invalid_token' => $failed,
            'csrf' => $this->trans('Nie udało się potwierdzić logowania przez Google. Spróbuj ponownie.', [], self::L10N_SHOP),
            'cannot_create' => $this->trans('Nie udało się automatycznie założyć konta. Spróbuj ponownie albo skontaktuj się ze sklepem.', [], self::L10N_SHOP),
            'internal_error' => $failed,
        ];
        $text = isset($messages[$code]) ? $messages[$code] : $messages['internal_error'];

        return '<div class="alert alert-warning apline-simple-google-auth asga-error">'
            . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
            . '</div>';
    }
}
