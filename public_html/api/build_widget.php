<?php

require __DIR__ . '/../app/bootstrap_api.php';
require __DIR__ . '/../app/WidgetSchema.php';
require __DIR__ . '/../app/WidgetRenderer.php';
require __DIR__ . '/../app/WidgetBuilder.php';

// Guard de sesión. Va antes de session_write_close() (lee $_SESSION) y antes de tocar la base.
// Además valida el token anti-CSRF en todo método que no sea GET/HEAD.
requireAuth();

requireMethod('POST');

// PHP mantiene un lock exclusivo sobre el archivo de sesión mientras está abierta, lo que serializa
// todas las requests del mismo usuario. Con una llamada a la IA de ~60s por delante, eso congelaría
// la app entera. Cerramos la sesión para escritura acá; a partir de este punto no se toca $_SESSION.
session_write_close();

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
    // view_id viene del cliente y WidgetBuilder lo usa para armar el contexto que va al prompt de
    // la IA. 404 acá, antes de construir nada, si la vista es de otro club.
    Scope::require($pdo, 'views', $viewId);

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

    respondOk($result);
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

    // Mismo IDOR que widgets.php POST: el view_id viaja a un INSERT, que no tiene WHERE que filtre.
    Scope::require($pdo, 'views', $viewId);

    $datasetIds = WidgetRenderer::datasetIds($config);
    if (empty($datasetIds)) {
        respondError(422, 'El widget no indica ningún dataset.');
    }
    // config.dataset_ids es JSON libre, sin FK que lo cubra: lo valida Scope o no lo valida nadie.
    Scope::requireAll($pdo, 'datasets', $datasetIds);

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
        $clubId = Auth::clubId();

        $posStmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM widgets WHERE view_id = :view_id AND club_id = :club');
        $posStmt->execute(['view_id' => $viewId, 'club' => $clubId]);
        $position = (int) $posStmt->fetchColumn();

        $encoded = json_encode($config, JSON_UNESCAPED_UNICODE);
        $pdo->prepare('INSERT INTO widgets (club_id, view_id, type, config, position) VALUES (:club, :view_id, :type, :config, :position)')
            ->execute(['club' => $clubId, 'view_id' => $viewId, 'type' => $type, 'config' => $encoded, 'position' => $position]);
        $widgetId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO widget_versions (club_id, widget_id, config, source) VALUES (:club, :widget_id, :config, "initial")')
            ->execute(['club' => $clubId, 'widget_id' => $widgetId, 'config' => $encoded]);

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
    $stmt = $pdo->prepare("SELECT column_schema FROM datasets WHERE id IN ($placeholders) AND club_id = ?");
    $stmt->execute([...array_values($datasetIds), Auth::clubId()]);
    $found = $stmt->fetchAll();
    if (count($found) !== count($datasetIds)) {
        return [null, []];
    }
    $schemas = array_map(fn($r) => json_decode($r['column_schema'], true), $found);
    $effectiveSchema = WidgetSchema::effectiveSchema($schemas);

    $metricsStmt = $pdo->prepare(
        "SELECT id, nombre FROM custom_metrics WHERE view_id = ? AND dataset_id IN ($placeholders) AND club_id = ?"
    );
    $metricsStmt->execute([$viewId, ...array_values($datasetIds), Auth::clubId()]);

    return [$effectiveSchema, $metricsStmt->fetchAll()];
}
