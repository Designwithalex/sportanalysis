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
 * Guard de autenticación + status + CSRF para endpoints JSON.
 *
 * 1. Sin sesión válida → 401 JSON (nunca un redirect: el que consume esto es fetch()).
 *    js/api.js interpreta el 401 y manda al usuario a login.php.
 *
 * 2. Con sesión pero cuenta no aprobada → 403, NO 401. La diferencia importa: el 401 significa
 *    "volvé a loguearte" y js/api.js lo trata mandando a login.php; mandar ahí a un 'pending'
 *    sería un loop (se loguea bien, vuelve, otro 403). El 403 sube el mensaje tal cual a la
 *    pantalla. Además viaja `code` para que el frontend pueda ramificar sin parsear el texto.
 *
 * 3. Todo método que no sea GET/HEAD valida el token anti-CSRF automáticamente. Se hace acá
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

    $status = Auth::status();
    if ($status !== 'active') {
        respondAccountBlocked($status);
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method !== 'GET' && $method !== 'HEAD') {
        Csrf::validateRequest();
    }
}

/**
 * Corta con 403 porque la cuenta no está aprobada.
 *
 * No usa respondError() porque agrega `code`: el frontend necesita distinguir "no aprobada" de
 * cualquier otro 403 (permisos, CSRF) sin leer el mensaje en castellano.
 *
 *   {"ok":false,"error":"…","code":"account_pending"|"account_rechazado"|"account_inactive"}
 */
function respondAccountBlocked(string $status): void
{
    $mensajes = [
        'pending'   => 'Tu cuenta todavía no está aprobada. Un administrador de tu club tiene que habilitarla.',
        'rechazado' => 'Tu solicitud de acceso fue rechazada. Escribile a un administrador de tu club.',
    ];

    $code = isset($mensajes[$status]) ? 'account_' . $status : 'account_inactive';

    http_response_code(403);
    echo json_encode([
        'ok'    => false,
        'error' => $mensajes[$status] ?? 'Tu cuenta no está habilitada para usar la app.',
        'code'  => $code,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
