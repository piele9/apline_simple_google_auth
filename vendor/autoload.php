<?php
/**
 * Minimal PSR-4 autoloader for the bundled firebase/php-jwt library.
 *
 * This module ships its single Composer dependency (firebase/php-jwt)
 * pre-vendored, because production PrestaShop shops rarely have Composer
 * available. This file replaces Composer's generated autoloader with a
 * tiny, dependency-free SPL autoloader scoped to the Firebase\JWT
 * namespace only. If you ever run `composer install` in this module
 * root, Composer will overwrite this file with its own autoloader,
 * which is fully compatible.
 *
 * @author    Arkadiusz Pielechowski
 * @copyright Arkadiusz Pielechowski
 * @license   MIT - see LICENSE.md
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

spl_autoload_register(function ($class) {
    $prefix = 'Firebase\\JWT\\';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative = substr($class, $len);
    $file = __DIR__ . '/firebase/php-jwt/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
