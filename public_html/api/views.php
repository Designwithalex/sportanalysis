<?php

define('PL_APP', true);
require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/Database.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$pdo = Database::get();

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

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
exit;

function handleList(PDO $pdo): void
{
    $views = $pdo->query('SELECT id, nombre, description, created_at FROM views ORDER BY created_at DESC')->fetchAll();

    $datasetStmt = $pdo->prepare(
        'SELECT d.id, d.nombre FROM datasets d
         INNER JOIN view_datasets vd ON vd.dataset_id = d.id
         WHERE vd.view_id = :view_id'
    );

    foreach ($views as &$view) {
        $datasetStmt->execute(['view_id' => $view['id']]);
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

    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE views SET nombre = :nombre, description = :description WHERE id = :id');
            $stmt->execute(['nombre' => $nombre, 'description' => $description, 'id' => $id]);
        } else {
            if ($nombre === '') {
                $count = (int) $pdo->query('SELECT COUNT(*) FROM views')->fetchColumn();
                $nombre = 'Vista ' . ($count + 1);
            }
            $pos = (int) $pdo->query('SELECT COALESCE(MAX(position), -1) + 1 FROM views')->fetchColumn();
            $stmt = $pdo->prepare('INSERT INTO views (nombre, description, position) VALUES (:nombre, :description, :position)');
            $stmt->execute(['nombre' => $nombre, 'description' => $description, 'position' => $pos]);
            $id = (int) $pdo->lastInsertId();
        }

        $pdo->prepare('DELETE FROM view_datasets WHERE view_id = :view_id')->execute(['view_id' => $id]);
        $linkStmt = $pdo->prepare('INSERT INTO view_datasets (view_id, dataset_id) VALUES (:view_id, :dataset_id)');
        foreach (array_unique($datasetIds) as $datasetId) {
            $linkStmt->execute(['view_id' => $id, 'dataset_id' => $datasetId]);
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
    $stmt = $pdo->prepare('UPDATE views SET nombre = :nombre WHERE id = :id');
    $stmt->execute(['nombre' => $nombre, 'id' => $id]);
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
    $stmt = $pdo->prepare('UPDATE views SET position = :position WHERE id = :id');
    $pdo->beginTransaction();
    try {
        foreach ($ids as $i => $id) {
            $stmt->execute(['position' => $i, 'id' => (int) $id]);
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
    $stmt = $pdo->prepare('DELETE FROM views WHERE id = :id');
    $stmt->execute(['id' => $id]);
    echo json_encode(['ok' => true]);
}

function respondError(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}
