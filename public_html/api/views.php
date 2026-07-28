<?php

require __DIR__ . '/../app/bootstrap_api.php';

// Guard de sesión. Va antes de session_write_close() (lee $_SESSION) y antes de tocar la base.
// Además valida el token anti-CSRF en todo método que no sea GET/HEAD.
requireAuth();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = Database::get();

requireMethod(['GET', 'POST', 'DELETE']);

if ($method === 'GET') {
    handleList($pdo);
    exit;
}

if ($method === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'rename') {
        handleRename($pdo);
    } elseif ($action === 'reorder') {
        handleReorder($pdo);
    } else {
        handleSave($pdo);
    }
    exit;
}

if ($method === 'DELETE') {
    handleDelete($pdo);
    exit;
}

function handleList(PDO $pdo): void
{
    $stmt = $pdo->prepare('SELECT id, nombre, description, created_at FROM views WHERE club_id = :club ORDER BY created_at DESC');
    $stmt->execute(['club' => Auth::clubId()]);
    $views = $stmt->fetchAll();

    // club_id se repite en las tres tablas del JOIN a propósito: alcanza con que una sola quede sin
    // filtrar para que se filtre el nombre de un dataset ajeno.
    $datasetStmt = $pdo->prepare(
        'SELECT d.id, d.nombre FROM datasets d
         INNER JOIN view_datasets vd ON vd.dataset_id = d.id AND vd.club_id = d.club_id
         WHERE vd.view_id = :view_id AND vd.club_id = :club AND d.club_id = :club2'
    );

    foreach ($views as &$view) {
        $datasetStmt->execute(['view_id' => $view['id'], 'club' => Auth::clubId(), 'club2' => Auth::clubId()]);
        $view['datasets'] = $datasetStmt->fetchAll();
    }

    echo json_encode(['ok' => true, 'views' => $views]);
}

function handleSave(PDO $pdo): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    $description = trim($_POST['description'] ?? '');
    // Una vista es un tab de SportAnalysis que se llena con widgets. No requiere descripción ni
    // datasets pre-asignados: cada widget elige sus propios datasets al crearse.
    $datasetIds = array_map('intval', $_POST['dataset_ids'] ?? []);

    // Ambos vienen del cliente. La vista, porque el UPDATE de abajo apuntaría a una ajena; los
    // datasets, porque viajan a un INSERT en view_datasets (donde no hay WHERE que los filtre).
    if ($id > 0) {
        Scope::require($pdo, 'views', $id);
    }
    if (!empty($datasetIds)) {
        Scope::requireAll($pdo, 'datasets', $datasetIds);
    }

    $clubId = Auth::clubId();

    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE views SET nombre = :nombre, description = :description WHERE id = :id AND club_id = :club');
            $stmt->execute(['nombre' => $nombre, 'description' => $description, 'id' => $id, 'club' => $clubId]);
        } else {
            if ($nombre === '') {
                $cStmt = $pdo->prepare('SELECT COUNT(*) FROM views WHERE club_id = :club');
                $cStmt->execute(['club' => $clubId]);
                $count = (int) $cStmt->fetchColumn();
                $nombre = 'Vista ' . ($count + 1);
            }
            $pStmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM views WHERE club_id = :club');
            $pStmt->execute(['club' => $clubId]);
            $pos = (int) $pStmt->fetchColumn();
            $stmt = $pdo->prepare('INSERT INTO views (club_id, nombre, description, position) VALUES (:club, :nombre, :description, :position)');
            $stmt->execute(['club' => $clubId, 'nombre' => $nombre, 'description' => $description, 'position' => $pos]);
            $id = (int) $pdo->lastInsertId();
        }

        $pdo->prepare('DELETE FROM view_datasets WHERE view_id = :view_id AND club_id = :club')
            ->execute(['view_id' => $id, 'club' => $clubId]);
        $linkStmt = $pdo->prepare('INSERT INTO view_datasets (club_id, view_id, dataset_id) VALUES (:club, :view_id, :dataset_id)');
        foreach (array_unique($datasetIds) as $datasetId) {
            $linkStmt->execute(['club' => $clubId, 'view_id' => $id, 'dataset_id' => $datasetId]);
        }

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        respondError(500, 'Error al guardar la vista: ' . $e->getMessage());
    }

    echo json_encode(['ok' => true, 'id' => $id, 'nombre' => $nombre]);
}

/**
 * Renombra una vista sin tocar nada más (ni description ni sus datasets). Sirve para cualquier
 * tipo de vista, incluidas las base (cluster/player), donde handleSave borraría metadata.
 */
function handleRename(PDO $pdo): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    if ($id <= 0 || $nombre === '') {
        respondError(400, 'Falta el id o el nombre.');
    }
    Scope::require($pdo, 'views', $id);
    $stmt = $pdo->prepare('UPDATE views SET nombre = :nombre WHERE id = :id AND club_id = :club');
    $stmt->execute(['nombre' => $nombre, 'id' => $id, 'club' => Auth::clubId()]);
    echo json_encode(['ok' => true, 'id' => $id, 'nombre' => $nombre]);
}

/**
 * Persiste el orden manual de las vistas (tabs). Recibe ids[] en el orden nuevo y les asigna
 * posiciones 0..n. Solo toca las vistas enviadas; el resto conserva su posición.
 */
function handleReorder(PDO $pdo): void
{
    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids) || empty($ids)) {
        respondError(400, 'Faltan los ids.');
    }
    // Lista de ids arbitraria del cliente: falla en bloque si alguno no es de este club.
    Scope::requireAll($pdo, 'views', $ids);

    $stmt = $pdo->prepare('UPDATE views SET position = :position WHERE id = :id AND club_id = :club');
    $pdo->beginTransaction();
    try {
        foreach ($ids as $i => $id) {
            $stmt->execute(['position' => $i, 'id' => (int) $id, 'club' => Auth::clubId()]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        respondError(500, 'Error al guardar el orden: ' . $e->getMessage());
    }
    echo json_encode(['ok' => true]);
}

function handleDelete(PDO $pdo): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        respondError(400, 'Falta el id de la vista a eliminar.');
    }
    Scope::require($pdo, 'views', $id);
    $stmt = $pdo->prepare('DELETE FROM views WHERE id = :id AND club_id = :club');
    $stmt->execute(['id' => $id, 'club' => Auth::clubId()]);
    echo json_encode(['ok' => true]);
}
