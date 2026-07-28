<?php

require __DIR__ . '/../app/bootstrap_api.php';
require __DIR__ . '/../app/BaseViewGenerator.php';

// Guard de sesión. Va antes de session_write_close() (lee $_SESSION) y antes de tocar la base.
// Además valida el token anti-CSRF en todo método que no sea GET/HEAD.
requireAuth();

// Generar vistas base implica llamadas largas a la IA (~30-60s cada una) más reintentos con backoff.
// Subimos el límite de ejecución para que no corte a mitad de camino (Hostinger suele permitirlo).
@set_time_limit(180);
@ignore_user_abort(true);

requireMethod('POST');

// PHP mantiene un lock exclusivo sobre el archivo de sesión mientras está abierta, lo que serializa
// todas las requests del mismo usuario. Con una llamada a la IA de ~60s por delante, eso congelaría
// la app entera. Cerramos la sesión para escritura acá; a partir de este punto no se toca $_SESSION.
session_write_close();

$pdo = Database::get();
$action = $_POST['action'] ?? '';
$generator = new BaseViewGenerator($pdo);

try {
    switch ($action) {
        case 'suggest':
            // Checklist sugerida por cluster (modo guiado).
            echo json_encode(['ok' => true, 'clusters' => $generator->suggestChecklists()]);
            break;

        case 'generate_cluster':
            // Genera (o regenera) la vista base de UNA categoría. El cliente itera de a una.
            $categoria = trim($_POST['categoria'] ?? '');
            $intent = trim($_POST['intent'] ?? '');
            if ($categoria === '') {
                respondError(400, 'Falta la categoría.');
            }
            echo json_encode(['ok' => true] + $generator->generateCluster($categoria, $intent));
            break;

        case 'generate_players':
            // 1 llamada IA (plantilla) + N clones (una vista por jugador).
            $template = $generator->generatePlayerTemplate();
            $views = $generator->instantiatePlayerViews($template);
            echo json_encode(['ok' => true, 'created' => count($views), 'views' => $views]);
            break;

        default:
            respondError(400, 'Acción desconocida.');
    }
} catch (RuntimeException $e) {
    respondError(422, $e->getMessage());
}
