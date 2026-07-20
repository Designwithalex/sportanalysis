<?php
/**
 * Helper de cache-busting para assets estáticos (CSS/JS).
 *
 * Devuelve la URL del asset con ?v=<mtime>. Como el HTML se sirve dinámico
 * (no cacheado), cada vez que cambia un archivo su mtime cambia → URL nueva →
 * el CDN de Hostinger y el navegador la tratan como recurso nuevo y no sirven
 * una versión vieja cacheada. Sin esto, el CDN puede quedarse pegado hasta 7
 * días (Cache-Control max-age=604800) sirviendo un JS/CSS obsoleto tras deploy.
 */

if (!defined('PUBLIC_ROOT')) {
    // assets.php vive en public_html/app/ → la raíz pública es el directorio padre.
    define('PUBLIC_ROOT', dirname(__DIR__));
}

/**
 * @param string $webPath Ruta tal cual la usa el HTML, ej. "../css/base.css".
 * @return string Misma ruta con ?v=<mtime> agregado.
 */
function asset(string $webPath): string
{
    // "../css/base.css" → "css/base.css" (relativa a public_html)
    $rel  = preg_replace('#^(?:\.\./)+#', '', $webPath);
    $full = PUBLIC_ROOT . '/' . $rel;
    $ver  = @filemtime($full) ?: '1';
    return $webPath . '?v=' . $ver;
}
