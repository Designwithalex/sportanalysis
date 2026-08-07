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
    $stmt = $pdo->prepare('SELECT id, dataset_id, column_name, filter_type, config FROM view_filters WHERE view_id = :view_id AND club_id = :club');
    $stmt->execute(['view_id' => $viewId, 'club' => Auth::clubId()]);
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
    //
    // `__split` (parte de la sesión) entra acá porque el renderer la sintetiza para TODOS los
    // datasets —vale 'all' en los que no tienen tramos—, así que cumple la misma condición. Es
    // además el filtro que evita el conteo múltiple de los tramos anidados del GPS.
    $universalColumns = ['__familia', '__sub_familia', '__player_nombre', '__split'];
    if ($viewId <= 0 || !in_array($columnName, $universalColumns, true)) {
        respondError(400, 'Dimensión de filtro inválida.');
    }
    if (!in_array($operator, ['eq', 'neq'], true)) {
        respondError(422, 'Operador inválido para un filtro de segmento.');
    }
    if (trim((string) $value) === '') {
        respondError(422, 'Falta el valor del filtro.');
    }

    // view_id viene del cliente y viaja a un INSERT: sin esto se podía colgar un filtro de la vista
    // de otro club (y con eso alterar lo que ese club ve en su dashboard). El filtro global recorta
    // lo que TODOS los widgets de la vista muestran: sobre una vista del club, solo un admin.
    requireEditarVistaId($pdo, $viewId);

    $config = json_encode(['operator' => $operator, 'value' => $value], JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare(
        'INSERT INTO view_filters (club_id, view_id, dataset_id, column_name, filter_type, config) VALUES (:club, :view_id, NULL, :column_name, :filter_type, :config)'
    );
    $stmt->execute([
        'club' => Auth::clubId(),
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
    // Cadena view_filters → views. El id llega del cliente: 404 si el filtro es de otro club.
    $filter = Scope::require($pdo, 'view_filters', $id);
    // Sacar el filtro cambia lo que ve todo el club si la vista es del club: solo un admin.
    requireEditarVistaId($pdo, (int) $filter['view_id']);
    $pdo->prepare('DELETE FROM view_filters WHERE id = :id AND club_id = :club')
        ->execute(['id' => $id, 'club' => Auth::clubId()]);
    echo json_encode(['ok' => true]);
    exit;
}
