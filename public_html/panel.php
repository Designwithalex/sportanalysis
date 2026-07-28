<?php
/**
 * Router de entrada de la app (lo que hasta la Etapa 1 hacía index.php, que ahora es la landing).
 *
 * No hay wizard lineal: hay una fase de Configuración (Plantel + Datos) que se hace una vez, y
 * después el usuario vive en SportAnalysis. Este router decide dónde entrar según cuánto haya
 * configurado.
 *
 * Va detrás del guard: es la primera pantalla privada, el destino natural del login.
 */
require __DIR__ . '/app/bootstrap_page.php';
requireAuth();

$pdo = Database::get();

// Los dos conteos son POR CLUB: si miraran la tabla entera, un club recién creado vería que "ya
// hay plantel y datos" (los de otro club) y entraría directo a SportAnalysis en vez de a la
// pantalla de carga de plantel. Scope::has() hace el LIMIT 1 con el WHERE club_id puesto.
$hasPlayers = Scope::has($pdo, 'players');
$hasDatasets = Scope::has($pdo, 'datasets');

if (!$hasPlayers) {
    header('Location: steps/plantel.php');
} elseif (!$hasDatasets) {
    header('Location: steps/datos.php');
} else {
    header('Location: steps/analysis.php');
}
exit;
