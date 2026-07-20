<?php

define('PL_APP', true);
require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/BaseViewGenerator.php';

// Generar vistas base implica llamadas largas a la IA (~30-60s cada una) más reintentos con backoff.
// Subimos el límite de ejecución para que no corte a mitad de camino (Hostinger suele permitirlo).
@set_time_limit(180);
@ignore_user_abort(true);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}

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

function respondError(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}
