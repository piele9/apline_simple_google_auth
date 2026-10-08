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
 * @author    APLINE Arkadiusz Pielechowski
 * @copyright APLINE Arkadiusz Pielechowski
 * @license   Custom Attribution License v1.0 - see LICENSE.md
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'apline_simple_google_auth/vendor/autoload.php';

class AplineSimpleGoogleAuthCallbackModuleFrontController extends ModuleFrontController
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
                Tools::redirect($this->context->link->getPageLink('my-account', true));

                return;
            }

            return $this->failRedirect(!empty($result['error']) ? $result['error'] : 'internal_error');
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('apline_simple_google_auth callback: ' . $e->getMessage(), 3);

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
