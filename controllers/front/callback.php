<?php
/**
 * APLINE Simple Google Auth module for PrestaShop 9.
 *
 * Front controller that receives the Google Identity Services POST. GIS
 * submits the signed ID token as `credential` together with a
 * double-submit CSRF token (`g_csrf_token` cookie + body field). This
 * controller is a thin routing layer: it checks CSRF, asks the module to
 * verify the token and log the customer in or create an account, then
 * redirects. All business logic lives in the module class.
 *
 * @author    Arkadiusz Pielechowski
 * @copyright Arkadiusz Pielechowski
 * @license   MIT - see LICENSE.md
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'apline_simple_google_auth/vendor/autoload.php';

class Apline_Simple_Google_AuthCallbackModuleFrontController extends ModuleFrontController
{
    /** @var bool this endpoint must run over HTTPS (Google requires it) */
    public $ssl = true;

    /** @var bool no authentication required to reach the callback */
    public $auth = false;

    public function postProcess()
    {
        try {
            $credential = Tools::getValue('credential');
            if (!$credential) {
                // Direct hit or no token — bounce to login without error noise.
                Tools::redirect('index.php?controller=authentication');

                return;
            }

            // Google double-submit CSRF: the g_csrf_token cookie must match the body field.
            $csrfCookie = isset($_COOKIE['g_csrf_token']) ? (string) $_COOKIE['g_csrf_token'] : '';
            $csrfBody = (string) Tools::getValue('g_csrf_token');
            if ($csrfCookie === '' || $csrfBody === '' || !hash_equals($csrfCookie, $csrfBody)) {
                return $this->failRedirect('csrf');
            }

            $payload = $this->module->verifyJwt($credential);
            if (!$payload) {
                return $this->failRedirect('invalid_token');
            }

            $result = $this->module->loginOrRegister($payload);
            if (!empty($result['success'])) {
                $this->success[] = $this->trans('Zalogowano przez Google.', [], 'Modules.Aplinesimplegoogleauth.Shop');
                $this->redirectWithNotifications($this->resolveReturnUrl((string) Tools::getValue('state')));

                return;
            }

            return $this->failRedirect(!empty($result['error']) ? $result['error'] : 'internal_error');
        } catch (\Throwable $e) {
            // Class name only: database exceptions carry the SQL, i.e. the customer's e-mail.
            PrestaShopLogger::addLog('apline_simple_google_auth callback: ' . get_class($e), 3);

            return $this->failRedirect('internal_error');
        }
    }

    public function initContent()
    {
        // Reached only if postProcess did not redirect (should not happen).
        parent::initContent();
        Tools::redirect('index.php?controller=authentication');
    }

    /**
     * Turn the `state` sent back by Google into a safe address inside this
     * shop. `state` is customer-controlled, so anything that is not a known
     * page name or an https address on the shop's own host falls back to
     * "My account". Login, registration, password and callback pages are
     * never a target (they would bounce the customer around).
     *
     * @param string $state
     *
     * @return string absolute URL
     */
    private function resolveReturnUrl($state)
    {
        $default = $this->context->link->getPageLink('my-account', true);
        $state = trim($state);
        if ($state === '' || strlen($state) > 500 || preg_match('/[\x00-\x20\\\\<>"\']/', $state)) {
            return $default;
        }

        $blocked = ['authentication', 'registration', 'password', 'my-account'];

        // Page name, e.g. "order" or "history" - the same form the native login uses.
        if (preg_match('/^[a-z][a-z0-9-]{0,40}$/', $state)) {
            if (in_array($state, $blocked, true)) {
                return $default;
            }
            $url = (string) $this->context->link->getPageLink($state, true);

            return $url !== '' ? $url : $default;
        }

        // Full address: https, exactly this shop's host, no credentials, no login-like page.
        $parts = parse_url($state);
        $host = Tools::getShopDomainSsl();
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || strtolower($parts['scheme']) !== 'https'
            || strtolower($parts['host']) !== strtolower($host)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
        ) {
            return $default;
        }
        foreach (['authentication', 'registration', 'password'] as $page) {
            $pageUrl = parse_url((string) $this->context->link->getPageLink($page, true), PHP_URL_PATH);
            if ($pageUrl && isset($parts['path']) && rtrim($parts['path'], '/') === rtrim($pageUrl, '/')) {
                return $default;
            }
        }
        if (isset($parts['path']) && strpos($parts['path'], '/module/' . $this->module->name . '/') !== false) {
            return $default;
        }
        if (stripos($state, 'controller=authentication') !== false || stripos($state, 'controller=registration') !== false) {
            return $default;
        }

        return $state;
    }

    /**
     * Redirect back to the login page with a generic error code. The code
     * is intentionally coarse — we never reveal which validation step
     * failed.
     *
     * @param string $code
     */
    private function failRedirect($code)
    {
        Tools::redirect('index.php?controller=authentication&asga_error=' . urlencode($code));
    }
}
