<?php
/**
 * Bootstrap de los endpoints JSON (public_html/api/*.php).
 *
 * Uso, como PRIMERA línea del endpoint:
 *     require __DIR__ . '/../app/bootstrap_api.php';
 * y después los require de las clases que ese endpoint específico necesite.
 *
 * Inmediatamente después de los require va requireAuth(): antes de cualquier session_write_close()
 * (el guard lee $_SESSION) y antes de tocar la base.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/Scope.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Guard de autenticación + CSRF para endpoints JSON.
 *
 * 1. Sin sesión válida → 401 JSON (nunca un redirect: el que consume esto es fetch()).
 *    js/api.js interpreta el 401 y manda al usuario a login.php.
 *
 * 2. Todo método que no sea GET/HEAD valida el token anti-CSRF automáticamente. Se hace acá
 *    y no endpoint por endpoint para que ningún endpoint nuevo pueda "olvidarse": con solo
 *    llamar a requireAuth() ya queda cubierto.
 *
 *    GET/HEAD quedan afuera porque son (y deben seguir siendo) operaciones sin efecto de lado;
 *    si algún GET pasara a mutar, hay que convertirlo en POST, no relajar esta regla.
 */
function requireAuth(): void
{
    if (!Auth::check()) {
        respondError(401, 'Tu sesión expiró. Volvé a ingresar.');
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') {
        Csrf::validateRequest();
    }
}
