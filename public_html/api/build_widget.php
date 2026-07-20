<?php

define('PL_APP', true);
require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/WidgetSchema.php';
require __DIR__ . '/../app/WidgetRenderer.php';
require __DIR__ . '/../app/WidgetBuilder.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}

$pdo = Database::get();
$action = $_POST['action'] ?? 'propose';

if ($action === 'propose') {
    handlePropose($pdo);
} elseif ($action === 'apply') {
    handleApply($pdo);
} else {
    respondError(400, 'Acción desconocida.');
}
exit;

/**
 * Turno del flujo multi-turno: la IA devuelve un widget listo (preview) o preguntas de aclaración.
 * El cliente reenvía el pedido + las aclaraciones acumuladas en cada turno (stateless).
 */
function handlePropose(PDO $pdo): void
{
    $viewId = (int) ($_POST['view_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $prompt = trim($_POST['prompt'] ?? '');

    if ($viewId <= 0 || $prompt === '') {
        respondError(400, 'Faltan datos: se necesita una vista y un pedido.');
    }

    $answers = json_decode($_POST['answers'] ?? '[]', true);
    if (!is_array($answers)) {
        $answers = [];
    }

    try {
        $builder = new WidgetBuilder($pdo);
        $result = $builder->build($viewId, $name, $prompt, $answers);
    } catch (RuntimeException $e) {
        respondError(502, 'Error al consultar la IA: ' . $e->getMessage());
    }

    if ($result['status'] === 'error') {
        respondError(422, $result['error']);
    }

    echo json_encode(['ok' => true] + $result);
}

/**
 * Confirma el widget ya previsualizado: lo valida de nuevo server-side y lo guarda con su
 * primera versión (source "initial") para el historial de undo.
 */
function handleApply(PDO $pdo): void
{
    $viewId = (int) ($_POST['view_id'] ?? 0);
    $type = $_POST['type'] ?? '';
    $config = json_decode($_POST['config'] ?? '', true);

    if ($viewId <= 0 || !is_array($config) || !in_array($type, WidgetSchema::TYPES, true)) {
        respondError(400, 'Datos inválidos.');
    }

    $datasetIds = WidgetRenderer::datasetIds($config);
    if (empty($datasetIds)) {
        respondError(422, 'El widget no indica ningún dataset.');
    }

    [$columnSchema, $customMetrics] = loadContext($pdo, $viewId, $datasetIds);
    if ($columnSchema === null) {
        respondError(422, 'Alguno de los datasets indicados no existe.');
    }

    $errors = WidgetSchema::validate($type, $config, $columnSchema, $customMetrics);
    if (!empty($errors)) {
        respondError(422, 'El widget ya no es válido: ' . implode(' ', $errors));
    }

    $pdo->beginTransaction();
    try {
        $posStmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM widgets WHERE view_id = :view_id');
        $posStmt->execute(['view_id' => $viewId]);
        $position = (int) $posStmt->fetchColumn();

        $encoded = json_encode($config, JSON_UNESCAPED_UNICODE);
        $pdo->prepare('INSERT INTO widgets (view_id, type, config, position) VALUES (:view_id, :type, :config, :position)')
            ->execute(['view_id' => $viewId, 'type' => $type, 'config' => $encoded, 'position' => $position]);
        $widgetId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO widget_versions (widget_id, config, source) VALUES (:widget_id, :config, "initial")')
            ->execute(['widget_id' => $widgetId, 'config' => $encoded]);

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        respondError(500, 'Error al guardar el widget: ' . $e->getMessage());
    }

    echo json_encode(['ok' => true, 'id' => $widgetId]);
}

/**
 * @param int[] $datasetIds
 * @return array{0: array<string,string>|null, 1: array<int,array{id:int,nombre:string}>}
 */
function loadContext(PDO $pdo, int $viewId, array $datasetIds): array
{
    $placeholders = implode(',', array_fill(0, count($datasetIds), '?'));
    $stmt = $pdo->prepare("SELECT column_schema FROM datasets WHERE id IN ($placeholders)");
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
