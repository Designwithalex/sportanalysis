<?php

require __DIR__ . '/../app/bootstrap_api.php';
require __DIR__ . '/../app/ViewPermission.php';

// Guard de sesión. Va antes de session_write_close() (lee $_SESSION) y antes de tocar la base.
// Además valida el token anti-CSRF en todo método que no sea GET/HEAD.
requireAuth();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = Database::get();

requireMethod(['GET', 'POST', 'DELETE']);

if ($method === 'GET') {
    $viewId = (int) ($_GET['view_id'] ?? 0);
    // Sin view_id devolvía lista vacía; se mantiene tal cual para no cambiar el comportamiento.
    if ($viewId > 0) {
        Scope::require($pdo, 'views', $viewId);
    }
    $stmt = $pdo->prepare('SELECT id, dataset_id, nombre, formula FROM custom_metrics WHERE view_id = :view_id AND club_id = :club');
    $stmt->execute(['view_id' => $viewId, 'club' => Auth::clubId()]);
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

    // view_id y dataset_id vienen del cliente y viajan al INSERT de abajo (sin WHERE que los filtre):
    // sin validar, se podía crear una métrica dentro de la vista de otro club, o sobre su dataset.
    // La métrica es contenido de la vista: si es del club, solo un admin la agrega.
    requireEditarVistaId($pdo, $viewId);
    $dataset = Scope::require($pdo, 'datasets', $datasetId);

    $schema = json_decode($dataset['column_schema'], true);
    foreach ($columns as $col) {
        if (($schema[$col] ?? null) !== 'numerica') {
            respondError(422, "La columna \"$col\" no es numérica.");
        }
    }

    $formula = json_encode(['operation' => $operation, 'columns' => array_values($columns)], JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare(
        'INSERT INTO custom_metrics (club_id, view_id, dataset_id, nombre, formula) VALUES (:club, :view_id, :dataset_id, :nombre, :formula)'
    );
    $stmt->execute(['club' => Auth::clubId(), 'view_id' => $viewId, 'dataset_id' => $datasetId, 'nombre' => $nombre, 'formula' => $formula]);

    echo json_encode(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
    exit;
}

if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        respondError(400, 'Falta id.');
    }
    // Cadena custom_metrics → views. El id llega del cliente: 404 si la métrica es de otro club.
    $metric = Scope::require($pdo, 'custom_metrics', $id);
    // Borrarla se la borra a todos los widgets de la vista: si la vista es del club, solo un admin.
    requireEditarVistaId($pdo, (int) $metric['view_id']);
    $pdo->prepare('DELETE FROM custom_metrics WHERE id = :id AND club_id = :club')
        ->execute(['id' => $id, 'club' => Auth::clubId()]);
    echo json_encode(['ok' => true]);
    exit;
}
