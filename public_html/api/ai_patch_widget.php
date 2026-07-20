<?php

define('PL_APP', true);
require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/WidgetSchema.php';
require __DIR__ . '/../app/WidgetRenderer.php';
require __DIR__ . '/../app/AnthropicClient.php';

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

function handlePropose(PDO $pdo): void
{
    $widgetId = (int) ($_POST['widget_id'] ?? 0);
    $instruction = trim($_POST['instruction'] ?? '');

    if ($widgetId <= 0 || $instruction === '') {
        respondError(400, 'Faltan datos.');
    }

    $widget = fetchWidget($pdo, $widgetId);
    [$columnSchema, $customMetrics] = fetchDatasetContext($pdo, $widget['view_id'], WidgetRenderer::datasetIds($widget['config']));

    $systemPrompt = buildPatchSystemPrompt($widget['type'], $columnSchema);
    $userPrompt = "Config actual del widget:\n" . json_encode($widget['config'], JSON_UNESCAPED_UNICODE)
        . "\n\nPedido del usuario: \"$instruction\"\n\nDevolvé SOLO el objeto JSON con los campos que cambian.";

    try {
        $responseText = AnthropicClient::complete($systemPrompt, $userPrompt, 2048);
        $patch = AnthropicClient::extractJson($responseText);
    } catch (RuntimeException $e) {
        respondError(502, 'Error al consultar la IA: ' . $e->getMessage());
    }

    if (!is_array($patch)) {
        respondError(502, 'La IA no devolvió un parche válido.');
    }

    unset($patch['dataset_id'], $patch['dataset_ids'], $patch['type']); // no se pueden cambiar por parche

    // Filtramos campos inventados: la IA a veces devuelve claves plausibles pero inexistentes
    // (ej. y_axis_config, tick_interval) que ni el schema valida ni el renderer usa. Si nos quedamos
    // sin nada aplicable, avisamos claro en vez de guardar un cambio que no hace nada.
    $patch = array_intersect_key($patch, array_flip(allowedConfigKeys($widget['type'])));
    if (empty($patch)) {
        respondError(422, 'Ese cambio no se puede aplicar por IA a este widget (el espaciado del eje, el tamaño o el estilo visual no son configurables acá). Pedí un cambio sobre los datos —métrica, agregación, filtro, orden, columnas, título— o usá "Editar".');
    }

    $mergedConfig = mergeDeep($widget['config'], $patch);

    $errors = WidgetSchema::validate($widget['type'], $mergedConfig, $columnSchema, $customMetrics);
    if (!empty($errors)) {
        respondError(422, 'El cambio propuesto no es válido: ' . implode(' ', $errors));
    }

    echo json_encode([
        'ok' => true,
        'patch' => $patch,
        'merged_config' => $mergedConfig,
    ]);
}

function handleApply(PDO $pdo): void
{
    $widgetId = (int) ($_POST['widget_id'] ?? 0);
    $mergedConfig = json_decode($_POST['merged_config'] ?? '', true);

    if ($widgetId <= 0 || !is_array($mergedConfig)) {
        respondError(400, 'Faltan datos.');
    }

    $widget = fetchWidget($pdo, $widgetId);
    [$columnSchema, $customMetrics] = fetchDatasetContext($pdo, $widget['view_id'], WidgetRenderer::datasetIds($widget['config']));

    // Revalidamos server-side antes de aplicar, por si el config viajó y fue manipulado en el cliente.
    $errors = WidgetSchema::validate($widget['type'], $mergedConfig, $columnSchema, $customMetrics);
    if (!empty($errors)) {
        respondError(422, 'El cambio ya no es válido: ' . implode(' ', $errors));
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE widgets SET config = :config WHERE id = :id')
            ->execute(['config' => json_encode($mergedConfig, JSON_UNESCAPED_UNICODE), 'id' => $widgetId]);

        $pdo->prepare('INSERT INTO widget_versions (widget_id, config, source) VALUES (:widget_id, :config, "ai")')
            ->execute(['widget_id' => $widgetId, 'config' => json_encode($mergedConfig, JSON_UNESCAPED_UNICODE)]);

        $stmt = $pdo->prepare('SELECT id FROM widget_versions WHERE widget_id = :widget_id ORDER BY id DESC LIMIT 1000 OFFSET 10');
        $stmt->execute(['widget_id' => $widgetId]);
        $toDelete = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($toDelete)) {
            $in = implode(',', array_map('intval', $toDelete));
            $pdo->exec("DELETE FROM widget_versions WHERE id IN ($in)");
        }

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        respondError(500, 'Error al aplicar el cambio: ' . $e->getMessage());
    }

    echo json_encode(['ok' => true]);
}

/** @return array{view_id:int, type:string, config:array} */
function fetchWidget(PDO $pdo, int $widgetId): array
{
    $stmt = $pdo->prepare('SELECT view_id, type, config FROM widgets WHERE id = :id');
    $stmt->execute(['id' => $widgetId]);
    $row = $stmt->fetch();
    if (!$row) {
        respondError(404, 'Widget no encontrado.');
    }
    return ['view_id' => (int) $row['view_id'], 'type' => $row['type'], 'config' => json_decode($row['config'], true)];
}

/**
 * @param int[] $datasetIds
 * @return array{0:array<string,string>, 1:array<int,array{id:int,nombre:string}>}
 */
function fetchDatasetContext(PDO $pdo, int $viewId, array $datasetIds): array
{
    if (empty($datasetIds)) {
        return [WidgetSchema::SYNTHETIC_COLUMNS, []];
    }
    $placeholders = implode(',', array_fill(0, count($datasetIds), '?'));
    $stmt = $pdo->prepare("SELECT column_schema FROM datasets WHERE id IN ($placeholders)");
    $stmt->execute($datasetIds);
    $schemas = array_map(fn($r) => json_decode($r['column_schema'], true), $stmt->fetchAll());
    $columnSchema = WidgetSchema::effectiveSchema($schemas);

    $metricsStmt = $pdo->prepare(
        "SELECT id, nombre FROM custom_metrics WHERE view_id = ? AND dataset_id IN ($placeholders)"
    );
    $metricsStmt->execute(array_merge([$viewId], $datasetIds));

    return [$columnSchema, $metricsStmt->fetchAll()];
}

function mergeDeep(array $base, array $patch): array
{
    foreach ($patch as $key => $value) {
        if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($value)) {
            $base[$key] = mergeDeep($base[$key], $value);
        } else {
            $base[$key] = $value;
        }
    }
    return $base;
}

/** Claves de config editables por parche de IA, por tipo de widget. Cualquier otra clave se descarta. */
function allowedConfigKeys(string $type): array
{
    // 'filter' (filtro propio del widget) es editable en todos los tipos.
    $common = ['title', 'filter'];
    $byType = [
        'kpi_card' => ['metric', 'aggregation', 'comparison', 'number_format', 'scale_selector'],
        'table' => ['columns', 'row_grain', 'conditional_rules', 'default_sort', 'search_enabled', 'scale_selector'],
        'line_chart' => ['y_metrics', 'x_column', 'group_by', 'aggregation', 'style'],
        'bar_chart' => ['metric', 'category_column', 'aggregation', 'order', 'orientation', 'reference_line'],
        'stacked_bar' => ['base_metric', 'segment_column', 'category_column', 'mode'],
    ];
    return array_merge($common, $byType[$type] ?? []);
}

function buildPatchSystemPrompt(string $type, array $columnSchema): string
{
    $columns = [];
    foreach ($columnSchema as $col => $colType) {
        $columns[] = "$col ($colType)";
    }
    $columnsList = implode(', ', $columns);
    $editableKeys = implode(', ', allowedConfigKeys($type));

    return <<<PROMPT
Sos el motor de edición asistida de widgets de SportAnalysis. Vas a recibir la configuración JSON actual de UN widget de tipo "$type" y un pedido en texto libre del usuario.

Reglas estrictas:
- Respondé ÚNICAMENTE con un objeto JSON que contenga SOLO los campos que cambian. Nunca repitas campos que no cambian. Nunca reescribas el widget completo.
- Los ÚNICOS campos que podés modificar en este widget son: $editableKeys. No inventes otros campos.
- No modifiques "dataset_id" ni "type".
- Solo podés usar estas columnas del dataset (no inventes otras): $columnsList
- En tablas, para mostrar una columna de texto/categórica tal cual (ej: sub-familia __sub_familia, familia __familia, posición, partido __dataset) usá una columna con "aggregation":"text". Las columnas numéricas usan sum/avg/min/max/count. Nunca pongas una agregación numérica sobre una columna de texto.
- El aspecto visual NO es configurable: escala/rango/saltos del eje, tamaño, colores del gráfico, tipografía. Eso lo maneja el sistema automáticamente. Si el pedido es sobre eso, devolvé un objeto vacío {}.
- Si el pedido no se puede resolver con los campos editables de arriba, devolvé un objeto vacío {}.
- Sin texto adicional, sin markdown, sin explicaciones.
PROMPT;
}

function respondError(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}
