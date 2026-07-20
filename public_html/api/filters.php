<?php

define('PL_APP', true);
require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/Database.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$pdo = Database::get();

if ($method === 'GET') {
    $viewId = (int) ($_GET['view_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT id, dataset_id, column_name, filter_type, config FROM view_filters WHERE view_id = :view_id');
    $stmt->execute(['view_id' => $viewId]);
    $filters = $stmt->fetchAll();
    foreach ($filters as &$f) {
        $f['config'] = json_decode($f['config'], true);
    }
    echo json_encode(['ok' => true, 'filters' => $filters]);
    exit;
}

if ($method === 'POST') {
    // Filtro de vista GLOBAL: opera sobre una dimensión universal (familia/sub_familia/jugador),
    // presente en todos los datasets vía columnas sintéticas. Aplica a TODOS los widgets de la
    // vista sin importar qué dataset use cada uno. Los filtros por columna propia van en el widget.
    $viewId = (int) ($_POST['view_id'] ?? 0);
    $columnName = trim($_POST['column_name'] ?? '');
    $operator = $_POST['operator'] ?? 'eq';
    $value = $_POST['value'] ?? '';

    // Solo columnas universales: son las que existen en cualquier dataset, así el filtro global
    // no depende de a qué dataset pertenece cada widget.
    $universalColumns = ['__familia', '__sub_familia', '__player_nombre'];
    if ($viewId <= 0 || !in_array($columnName, $universalColumns, true)) {
        respondError(400, 'Dimensión de filtro inválida.');
    }
    if (!in_array($operator, ['eq', 'neq'], true)) {
        respondError(422, 'Operador inválido para un filtro de segmento.');
    }
    if (trim((string) $value) === '') {
        respondError(422, 'Falta el valor del filtro.');
    }

    $config = json_encode(['operator' => $operator, 'value' => $value], JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare(
        'INSERT INTO view_filters (view_id, dataset_id, column_name, filter_type, config) VALUES (:view_id, NULL, :column_name, :filter_type, :config)'
    );
    $stmt->execute([
        'view_id' => $viewId,
        'column_name' => $columnName,
        'filter_type' => 'segment',
        'config' => $config,
    ]);

    echo json_encode(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
    exit;
}

if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        respondError(400, 'Falta id.');
    }
    $pdo->prepare('DELETE FROM view_filters WHERE id = :id')->execute(['id' => $id]);
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
