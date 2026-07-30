<?php
/**
 * Bootstrap de las pantallas server-rendered (páginas de la raíz y public_html/steps/*.php).
 *
 * Igual que bootstrap_api.php pero sin header JSON: estos archivos escupen HTML.
 * Uso, como PRIMERA línea de la pantalla:
 *     require __DIR__ . '/../app/bootstrap_page.php';   // desde steps/
 *     require __DIR__ . '/app/bootstrap_page.php';      // desde public_html/
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/Scope.php';

/**
 * Token CSRF disponible para head.php en TODA pantalla, sin que cada página tenga que acordarse.
 *
 * Se define acá arriba (no dentro de una función) porque el require se hace en el scope global
 * de la página: la variable queda visible para el `require .../head.php` posterior, que emite
 * <meta name="csrf"> y de ahí lo lee js/api.js para mandarlo en cada POST/DELETE.
 */
$csrfToken = Csrf::token();

/**
 * Prefijo relativo desde la pantalla que se está ejecutando hasta la raíz pública.
 *
 * '' para las páginas de public_html/, '../' para las de steps/. Se calcula en vez de
 * hardcodear '/login.php' porque en producción la raíz pública ES el dominio, pero en local
 * la app puede estar colgando de un subdirectorio y un redirect absoluto se iría afuera.
 */
function pageRootPrefix(): string
{
    $root   = str_replace('\\', '/', dirname(__DIR__)); // public_html/
    $script = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
    $real   = $script !== '' ? (string) realpath($script) : '';
    if ($real === '') {
        return '';
    }

    $dir = str_replace('\\', '/', dirname($real));
    if ($dir === $root) {
        return '';
    }
    if (!str_starts_with($dir, $root . '/')) {
        return '';
    }

    $rel = trim(substr($dir, strlen($root)), '/');
    return str_repeat('../', substr_count($rel, '/') + 1);
}

/**
 * Ruta de la pantalla actual relativa a la raíz pública, con query string.
 * Ej: 'steps/plantel.php' o 'steps/analysis.php?view_id=3'. Null si no se pudo determinar.
 *
 * Es lo que se manda como `?next=` a login.php; Auth::safeNext() lo vuelve a validar del otro
 * lado antes de usarlo (nunca se confía en un next que llegue por query string).
 */
function pageCurrentPath(): ?string
{
    $root   = str_replace('\\', '/', dirname(__DIR__));
    $script = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
    $real   = $script !== '' ? (string) realpath($script) : '';
    if ($real === '') {
        return null;
    }

    $full = str_replace('\\', '/', $real);
    if (!str_starts_with($full, $root . '/')) {
        return null;
    }

    $rel = substr($full, strlen($root) + 1);
    $qs  = (string) ($_SERVER['QUERY_STRING'] ?? '');
    return $qs !== '' ? $rel . '?' . $qs : $rel;
}

/**
 * Nombre de archivo de la pantalla actual, relativo a la raíz pública y sin query string.
 * Ej: 'verificacion.php', 'steps/analysis.php'. '' si no se pudo determinar.
 */
function pageCurrentFile(): string
{
    $path = pageCurrentPath();
    if ($path === null) {
        return '';
    }

    $q = strpos($path, '?');
    return $q === false ? $path : substr($path, 0, $q);
}

/**
 * Guard de autenticación + status para pantallas HTML.
 *
 * 1. Sin sesión válida: redirect a login.php con `?next=` para volver a donde el usuario quería
 *    ir. Redirect y no 401 porque acá el que consume es el navegador directamente.
 *
 * 2. Con sesión pero cuenta no aprobada ('pending' / 'rechazado'): a verificacion.php, que es la
 *    única pantalla que un usuario no aprobado puede ver. Ahí lee en qué estado quedó su
 *    solicitud — por eso el login NO lo bloquea (ver Auth::attempt()).
 *
 *    La excepción es obligatoria: si la pantalla actual YA es verificacion.php, el redirect
 *    apuntaría a sí mismo y el navegador entraría en un loop infinito. Se compara contra
 *    pageCurrentFile() (ruta real del script), no contra la URL pedida.
 *
 *    logout.php no llama a requireAuth(), así que un 'pending' siempre puede cerrar sesión.
 */
function requireAuth(): void
{
    if (!Auth::check()) {
        $url  = pageRootPrefix() . 'login.php';
        $next = Auth::safeNext(pageCurrentPath());
        if ($next !== null) {
            $url .= '?next=' . urlencode($next);
        }

        header('Location: ' . $url);
        exit;
    }

    if (Auth::status() !== 'active' && pageCurrentFile() !== 'verificacion.php') {
        header('Location: ' . pageRootPrefix() . 'verificacion.php');
        exit;
    }
}
