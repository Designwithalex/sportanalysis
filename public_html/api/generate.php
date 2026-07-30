<?php

require __DIR__ . '/../app/bootstrap_api.php';
require __DIR__ . '/../app/ViewPermission.php';
require __DIR__ . '/../app/ViewGenerator.php';

// Guard de sesión. Va antes de session_write_close() (lee $_SESSION) y antes de tocar la base.
// Además valida el token anti-CSRF en todo método que no sea GET/HEAD.
requireAuth();

requireMethod('POST');

// Una generación es una llamada a la IA de ~60s (más reintentos con backoff si la API devuelve 429
// o 529), y recién después vienen los INSERT. Con el max_execution_time por defecto del hosting
// cortaría a mitad de camino: subimos el límite igual que en api/base_views.php. Hostinger suele
// permitirlo; si no, el @ evita el warning y el peor caso es el de siempre.
@set_time_limit(180);
@ignore_user_abort(true);

// La generación de una vista es una llamada larga a la IA. Cerramos la sesión para escritura ya:
// PHP mantiene un lock exclusivo sobre el archivo de sesión mientras está abierta, y eso serializa
// todas las requests del mismo usuario (la app se congelaría mientras la IA piensa).
// A partir de acá no se escribe $_SESSION.
session_write_close();

$viewId = (int) ($_POST['view_id'] ?? 0);
if ($viewId <= 0) {
    respondError(400, 'Falta view_id.');
}

$pdo = Database::get();

// view_id viene del cliente. ViewGenerator lee la vista y sus datasets para armar el prompt de la
// IA y después inserta widgets colgados de ella: si la vista es de otro club, todo eso pasa contra
// datos ajenos. 404 acá, antes de instanciar el generador. Y 403 si la vista es del club y quien
// pide no es admin: generar le llena el tablero a todos los miembros.
requireEditarVistaId($pdo, $viewId);

try {
    $generator = new ViewGenerator($pdo);
    // generate() REEMPLAZA el tablero: devuelve cuántos widgets borró y cuántos creó, para que la UI
    // pueda decir "reemplazamos 5 widgets por 7" en vez de dejar al usuario adivinando qué pasó con
    // los que tenía. `skipped` son los widgets que la IA propuso y no pasaron la validación.
    $result = $generator->generate($viewId);
    respondOk($result);
} catch (RuntimeException $e) {
    // 422 y no 500: son todos errores de contenido (vista sin descripción, sin datasets, la IA no
    // devolvió nada válido). En ninguno de esos casos se tocó la vista, y el mensaje sube tal cual
    // a la pantalla.
    respondError(422, $e->getMessage());
}
