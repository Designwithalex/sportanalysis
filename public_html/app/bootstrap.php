<?php
/**
 * Bootstrap compartido de la aplicación.
 *
 * Único punto donde se cargan config + Database y donde se arranca la sesión PHP.
 * No lo requieras directamente desde un endpoint: usá bootstrap_api.php (endpoints JSON)
 * o bootstrap_page.php (pantallas server-rendered).
 */

// Guard histórico: se conserva por compatibilidad con cualquier archivo que lo espere.
// La protección real de app/ es public_html/app/.htaccess (Require all denied).
if (!defined('PL_APP')) {
    define('PL_APP', true);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Database.php';

/**
 * Sesión PHP con cookie endurecida.
 *
 * `secure` se decide en runtime: en producción (Hostinger, detrás de proxy/CDN) la request llega
 * como HTTPS y la cookie viaja solo por TLS; en localhost sin HTTPS forzar `secure` haría que el
 * navegador descarte la cookie y la sesión nunca persista. Por eso se detecta, no se hardcodea.
 */
if (session_status() === PHP_SESSION_NONE) {
    $plIsHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_SSL']) === 'on')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

    // Rechaza ids de sesión no generados por el servidor (session fixation).
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $plIsHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    unset($plIsHttps);
}
