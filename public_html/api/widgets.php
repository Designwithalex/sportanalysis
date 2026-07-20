<?php

define('PL_APP', true);
require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/WidgetSchema.php';
require __DIR__ . '/../app/WidgetRenderer.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$pdo = Database::get();

if ($method === 'GET') {
    handleList($pdo);
    exit;
}

if ($method === 'POST') {
    $action = $_POST['action'] ?? 'save';
    if ($action === 'duplicate') {
        handleDuplicate($pdo);
    } elseif ($action === 'undo') {
        handleUndo($pdo);
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
    $viewId = (int) ($_GET['view_id'] ?? 0);
    if ($viewId <= 0) {
        respondError(400, 'Falta view_id.');
    }

    $stmt = $pdo->prepare('SELECT id, type, config, position FROM widgets WHERE view_id = :view_id ORDER BY position, id');
    $stmt->execute(['view_id' => $viewId]);
    $widgets = $stmt->fetchAll();

    $globalFilters = loadActiveFilters($pdo, $viewId);

    $renderer = new WidgetRenderer($pdo);
    $out = [];
    foreach ($widgets as $w) {
        $config = json_decode($w['config'], true);

        // Los filtros de vista son globales (sobre dimensiones universales) y aplican a todos los
        // widgets. El filtro propio del widget (config.filter) lo aplica el renderer aparte.
        $rendered = $renderer->render(['id' => $w['id'], 'type' => $w['type'], 'config' => $config], $globalFilters);

        $out[] = [
            'id' => (int) $w['id'],
            'type' => $w['type'],
            'title' => $config['title'] ?? '',
            'config' => $config,
            'position' => (int) $w['position'],
            'html' => $rendered['html'],
            'chart_type' => $rendered['chart_type'],
            'chart_data' => $rendered['chart_data'],
            'excluded_count' => $rendered['excluded_count'],
        ];
    }

    $versionCounts = [];
    if (!empty($widgets)) {
        $ids = array_column($widgets, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $vStmt = $pdo->prepare("SELECT widget_id, COUNT(*) AS c FROM widget_versions WHERE widget_id IN ($in) GROUP BY widget_id");
        $vStmt->execute($ids);
        foreach ($vStmt->fetchAll() as $row) {
            $versionCounts[$row['widget_id']] = (int) $row['c'];
        }
    }
    foreach ($out as &$w) {
        $w['version_count'] = $versionCounts[$w['id']] ?? 1;
    }

    echo json_encode(['ok' => true, 'widgets' => $out]);
}

/** @return array<int,array{column:string,operator:string,value:mixed}> filtros globales de la vista */
function loadActiveFilters(PDO $pdo, int $viewId): array
{
    $stmt = $pdo->prepare('SELECT column_name, config FROM view_filters WHERE view_id = :view_id');
    $stmt->execute(['view_id' => $viewId]);
    $filters = [];
    foreach ($stmt->fetchAll() as $f) {
        $cfg = json_decode($f['config'], true) ?? [];
        $filters[] = [
            'column' => $f['column_name'],
            'operator' => $cfg['operator'] ?? 'eq',
            'value' => $cfg['value'] ?? null,
        ];
    }
    return $filters;
}

function handleSave(PDO $pdo): void
{
    $widgetId = (int) ($_POST['id'] ?? 0);
    $viewId = (int) ($_POST['view_id'] ?? 0);
    $type = $_POST['type'] ?? '';
    $config = json_decode($_POST['config'] ?? '', true);

    if (!is_array($config) || $viewId <= 0 || !in_array($type, WidgetSchema::TYPES, true)) {
        respondError(400, 'Datos inválidos.');
    }

    $datasetIds = WidgetRenderer::datasetIds($config);
    if (empty($datasetIds)) {
        respondError(422, 'El widget no indica ningún dataset.');
    }
    [$columnSchema, $customMetrics] = loadDatasetContext($pdo, $viewId, $datasetIds);
    if ($columnSchema === null) {
        respondError(422, 'Alguno de los datasets indicados no existe.');
    }

    $errors = WidgetSchema::validate($type, $config, $columnSchema, $customMetrics);
    if (!empty($errors)) {
        respondError(422, implode(' ', $errors));
    }

    $pdo->beginTransaction();
    try {
        if ($widgetId > 0) {
            $pdo->prepare('UPDATE widgets SET type = :type, config = :config WHERE id = :id')
                ->execute(['type' => $type, 'config' => json_encode($config, JSON_UNESCAPED_UNICODE), 'id' => $widgetId]);
        } else {
            $posStmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM widgets WHERE view_id = :view_id');
            $posStmt->execute(['view_id' => $viewId]);
            $position = (int) $posStmt->fetchColumn();

            $pdo->prepare('INSERT INTO widgets (view_id, type, config, position) VALUES (:view_id, :type, :config, :position)')
                ->execute(['view_id' => $viewId, 'type' => $type, 'config' => json_encode($config, JSON_UNESCAPED_UNICODE), 'position' => $position]);
            $widgetId = (int) $pdo->lastInsertId();
        }

        $pdo->prepare('INSERT INTO widget_versions (widget_id, config, source) VALUES (:widget_id, :config, "manual")')
            ->execute(['widget_id' => $widgetId, 'config' => json_encode($config, JSON_UNESCAPED_UNICODE)]);

        pruneVersions($pdo, $widgetId);

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        respondError(500, 'Error al guardar el widget: ' . $e->getMessage());
    }

    echo json_encode(['ok' => true, 'id' => $widgetId]);
}

function handleDuplicate(PDO $pdo): void
{
    $widgetId = (int) ($_POST['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT view_id, type, config FROM widgets WHERE id = :id');
    $stmt->execute(['id' => $widgetId]);
    $widget = $stmt->fetch();
    if (!$widget) {
        respondError(404, 'Widget no encontrado.');
    }

    $posStmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM widgets WHERE view_id = :view_id');
    $posStmt->execute(['view_id' => $widget['view_id']]);
    $position = (int) $posStmt->fetchColumn();

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO widgets (view_id, type, config, position) VALUES (:view_id, :type, :config, :position)')
            ->execute(['view_id' => $widget['view_id'], 'type' => $widget['type'], 'config' => $widget['config'], 'position' => $position]);
        $newId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO widget_versions (widget_id, config, source) VALUES (:widget_id, :config, "manual")')
            ->execute(['widget_id' => $newId, 'config' => $widget['config']]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        respondError(500, 'Error al duplicar: ' . $e->getMessage());
    }

    echo json_encode(['ok' => true, 'id' => $newId]);
}

function handleUndo(PDO $pdo): void
{
    $widgetId = (int) ($_POST['id'] ?? 0);

    $versions = $pdo->prepare('SELECT id, config FROM widget_versions WHERE widget_id = :widget_id ORDER BY id DESC LIMIT 2');
    $versions->execute(['widget_id' => $widgetId]);
    $rows = $versions->fetchAll();

    if (count($rows) < 2) {
        respondError(400, 'No hay versiones anteriores para deshacer.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM widget_versions WHERE id = :id')->execute(['id' => $rows[0]['id']]);
        $pdo->prepare('UPDATE widgets SET config = :config WHERE id = :id')
            ->execute(['config' => $rows[1]['config'], 'id' => $widgetId]);
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        respondError(500, 'Error al deshacer: ' . $e->getMessage());
    }

    echo json_encode(['ok' => true]);
}

function handleReorder(PDO $pdo): void
{
    $ids = $_POST['ids'] ?? [];
    $stmt = $pdo->prepare('UPDATE widgets SET position = :position WHERE id = :id');
    $pdo->beginTransaction();
    foreach ($ids as $i => $id) {
        $stmt->execute(['position' => $i, 'id' => (int) $id]);
    }
    $pdo->commit();
    echo json_encode(['ok' => true]);
}

function handleDelete(PDO $pdo): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        respondError(400, 'Falta id.');
    }
    $pdo->prepare('DELETE FROM widgets WHERE id = :id')->execute(['id' => $id]);
    echo json_encode(['ok' => true]);
}

function pruneVersions(PDO $pdo, int $widgetId, int $keep = 10): void
{
    $stmt = $pdo->prepare('SELECT id FROM widget_versions WHERE widget_id = :widget_id ORDER BY id DESC LIMIT 1000 OFFSET :offset');
    $stmt->bindValue('widget_id', $widgetId, PDO::PARAM_INT);
    $stmt->bindValue('offset', $keep, PDO::PARAM_INT);
    $stmt->execute();
    $toDelete = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($toDelete)) {
        $in = implode(',', array_map('intval', $toDelete));
        $pdo->exec("DELETE FROM widget_versions WHERE id IN ($in)");
    }
}

/**
 * Contexto de validación para un widget que abarca uno o varios datasets: el schema efectivo
 * (intersección de columnas comunes + sintéticas) y las métricas configurables de esos datasets.
 * @param int[] $datasetIds
 * @return array{0: array<string,string>|null, 1: array<int,array{id:int,nombre:string}>}
 */
function loadDatasetContext(PDO $pdo, int $viewId, array $datasetIds): array
{
    $placeholders = implode(',', array_fill(0, count($datasetIds), '?'));
    $stmt = $pdo->prepare("SELECT id, column_schema FROM datasets WHERE id IN ($placeholders)");
    $stmt->execute($datasetIds);
    $found = $stmt->fetchAll();
    if (count($found) !== count($datasetIds)) {
        return [null, []];
    }
    $schemas = array_map(fn($r) => json_decode($r['column_schema'], true), $found);
    $effectiveSchema = WidgetSchema::effectiveSchema($schemas);

    $metricsStmt = $pdo->prepare(
        "SELECT id, nombre FROM custom_metrics WHERE view_id = ? AND dataset_id IN ($placeholders)"
    );
    $metricsStmt->execute(array_merge([$viewId], $datasetIds));

    return [$effectiveSchema, $metricsStmt->fetchAll()];
}

function respondError(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}
