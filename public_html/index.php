<?php
/**
 * Router de entrada. Ya no hay wizard lineal: hay una fase de Configuración (Plantel + Datos)
 * que se hace una vez, y después el usuario vive en SportAnalysis. Este router decide dónde
 * entrar según cuánto haya configurado.
 */
define('PL_APP', true);
require __DIR__ . '/app/config.php';
require __DIR__ . '/app/Database.php';

$pdo = Database::get();
$hasPlayers = (int) $pdo->query('SELECT COUNT(*) FROM players')->fetchColumn() > 0;
$hasDatasets = (int) $pdo->query('SELECT COUNT(*) FROM datasets')->fetchColumn() > 0;

if (!$hasPlayers) {
    header('Location: steps/plantel.php');
} elseif (!$hasDatasets) {
    header('Location: steps/datos.php');
} else {
    header('Location: steps/analysis.php');
}
exit;
