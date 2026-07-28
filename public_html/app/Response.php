<?php
/**
 * Helpers de respuesta JSON. Única definición en todo el proyecto: antes cada endpoint de api/
 * llevaba su propia copia byte a byte de respondError().
 *
 * Todos los helpers terminan el script (exit): están pensados para cortar el flujo en el acto.
 */

/**
 * Corta con un error JSON: {"ok":false,"error":"..."} y el status HTTP indicado.
 */
function respondError(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

/**
 * Corta con una respuesta exitosa: {"ok":true, ...payload}.
 * "ok" siempre va primero; el payload no puede pisarlo (unión de arrays, no array_merge).
 */
function respondOk(array $payload = []): void
{
    echo json_encode(['ok' => true] + $payload);
    exit;
}

/**
 * Exige que la request use uno de los métodos permitidos. Si no, corta con 405.
 *
 * @param string|string[] $methods Ej: 'POST' o ['GET', 'POST', 'DELETE'].
 */
function requireMethod(string|array $methods): void
{
    $allowed = is_array($methods) ? $methods : [$methods];
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', $allowed, true)) {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
        exit;
    }
}
