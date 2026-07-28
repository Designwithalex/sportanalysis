<?php
/**
 * Token anti-CSRF por sesión.
 *
 * Un único token por sesión (no uno por formulario): el flujo de la app es de una sola pestaña
 * y con varios tokens vivos habría que expirarlos, lo que rompe la navegación con "atrás".
 * El token se rota al iniciar sesión (junto con session_regenerate_id) y al cerrarla.
 *
 * Cómo viaja:
 *   - fetch()      → header `X-CSRF-Token` (lo pone js/api.js leyendo <meta name="csrf">).
 *   - form POST    → campo oculto `csrf_token` (las pantallas de auth postean a sí mismas).
 *
 * La validación de los endpoints JSON es automática: bootstrap_api.php la corre dentro de
 * requireAuth() para todo método que no sea GET/HEAD.
 */

final class Csrf
{
    /** Nombre del header que manda el JS. */
    public const HEADER = 'X-CSRF-Token';

    /** Nombre del input oculto de los formularios server-rendered. */
    public const FIELD = 'csrf_token';

    private const SESSION_KEY = 'csrf_token';

    /**
     * Token de la sesión actual. Lo genera la primera vez que se lo pide.
     * 32 bytes de random_bytes() → 64 hex. random_bytes() es CSPRNG, no mt_rand().
     */
    public static function token(): string
    {
        $stored = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($stored) || $stored === '') {
            $stored = bin2hex(random_bytes(32));
            $_SESSION[self::SESSION_KEY] = $stored;
        }
        return $stored;
    }

    /**
     * Descarta el token actual y devuelve uno nuevo. Se llama al hacer login y al hacer logout:
     * un token emitido para una sesión anónima no debe seguir siendo válido para la autenticada.
     */
    public static function rotate(): string
    {
        unset($_SESSION[self::SESSION_KEY]);
        return self::token();
    }

    /**
     * Compara en tiempo constante (hash_equals) contra el token de la sesión.
     * Sin token en sesión → false: no hay nada contra qué validar.
     */
    public static function check(?string $t): bool
    {
        $stored = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($stored) || $stored === '') {
            return false;
        }
        if (!is_string($t) || $t === '') {
            return false;
        }
        return hash_equals($stored, $t);
    }

    /**
     * Extrae el token de la request: primero el header (fetch), después el campo del form.
     */
    public static function fromRequest(): ?string
    {
        if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            return (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
        }
        if (isset($_POST[self::FIELD])) {
            return (string) $_POST[self::FIELD];
        }
        return null;
    }

    /**
     * Valida la request y corta con 403 si el token no coincide.
     *
     * Usa respondError() si está cargado (contexto de api/*.php, respuesta JSON); si no,
     * escupe un 403 de texto plano para no depender de Response.php.
     */
    public static function validateRequest(): void
    {
        if (self::check(self::fromRequest())) {
            return;
        }

        if (function_exists('respondError')) {
            respondError(403, 'Token de seguridad inválido. Recargá la página e intentá de nuevo.');
        }

        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Token de seguridad inválido. Recargá la página e intentá de nuevo.';
        exit;
    }

    /**
     * Input oculto listo para pegar dentro de un <form method="post">.
     */
    public static function field(): string
    {
        return '<input type="hidden" name="' . self::FIELD . '" value="'
            . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }
}
