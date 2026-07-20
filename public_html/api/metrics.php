<?php

define('PL_APP', true);
require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/Database.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$pdo = Database::get();

if ($method === 'GET') {
    $viewId = (int) ($_GET['view_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT id, dataset_id, nombre, formula FROM custom_metrics WHERE view_id = :view_id');
    $stmt->execute(['view_id' => $viewId]);
    $metrics = $stmt->fetchAll();
    foreach ($metrics as &$m) {
        $m['formula'] = json_decode($m['formula'], true);
    }
    echo json_encode(['ok' => true, 'metrics' => $metrics]);
    exit;
}

if ($method === 'POST') {
    $viewId = (int) ($_POST['view_id'] ?? 0);
    $datasetId = (int) ($_POST['dataset_id'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    $operation = $_POST['operation'] ?? '';
    $columns = $_POST['columns'] ?? [];

    if ($viewId <= 0 || $datasetId <= 0 || $nombre === '') {
        respondError(400, 'Faltan datos.');
    }
    if (!in_array($operation, ['sum', 'subtract', 'multiply', 'divide', 'ratio'], true)) {
        respondError(422, 'Operación inválida.');
    }
    if (count($columns) < 1) {
        respondError(422, 'Elegí al menos una columna.');
    }
    if (in_array($operation, ['subtract', 'divide', 'ratio'], true) && count($columns) !== 2) {
        respondError(422, 'Esa operación necesita exactamente 2 columnas.');
    }

    $datasetStmt = $pdo->prepare('SELECT column_schema FROM datasets WHERE id = :id');
    $datasetStmt->execute(['id' => $datasetId]);
    $dataset = $datasetStmt->fetch();
    if (!$dataset) {
        respondError(404, 'Dataset no encontrado.');
    }
    $schema = json_decode($dataset['column_schema'], true);
    foreach ($columns as $col) {
        if (($schema[$col] ?? null) !== 'numerica') {
            respondError(422, "La columna \"$col\" no es numérica.");
        }
    }

    $formula = json_encode(['operation' => $operation, 'columns' => array_values($columns)], JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare(
        'INSERT INTO custom_metrics (view_id, dataset_id, nombre, formula) VALUES (:view_id, :dataset_id, :nombre, :formula)'
    );
    $stmt->execute(['view_id' => $viewId, 'dataset_id' => $datasetId, 'nombre' => $nombre, 'formula' => $formula]);

    echo json_encode(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
    exit;
}

if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        respondError(400, 'Falta id.');
    }
    $pdo->prepare('DELETE FROM custom_metrics WHERE id = :id')->execute(['id' => $id]);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);

function respondError(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}
