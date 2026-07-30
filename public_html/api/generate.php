<?php

require __DIR__ . '/../app/bootstrap_api.php';
require __DIR__ . '/../app/ViewPermission.php';
require __DIR__ . '/../app/ViewGenerator.php';

// Guard de sesión. Va antes de session_write_close() (lee $_SESSION) y antes de tocar la base.
// Además valida el token anti-CSRF en todo método que no sea GET/HEAD.
requireAuth();

requireMethod('POST');

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
    $result = $generator->generate($viewId);
    respondOk($result);
} catch (RuntimeException $e) {
    respondError(422, $e->getMessage());
}
