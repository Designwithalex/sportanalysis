<?php

require_once __DIR__ . '/AnthropicClient.php';
require_once __DIR__ . '/WidgetSchema.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Scope.php';

/**
 * Arma el prompt para una vista (descripcion + datasets asociados), le pide a la IA
 * una grilla de widgets dentro de la libreria fija, valida cada uno contra WidgetSchema
 * y los guarda. La IA nunca genera HTML ni código: solo este JSON de configuración.
 *
 * AISLAMIENTO POR CLUB: la vista, sus datasets y sus métricas se leen filtrando por el club
 * de la sesión, así que al prompt de la IA nunca viajan columnas ni nombres de otro club.
 * Los INSERT pasan club_id explícito (el DEFAULT 1 del esquema oculta el bug, no lo evita).
 *
 * SEGUNDO EJE (usuario): fetchView() aplica además el predicado de visibilidad de vistas, así que
 * una vista privada de otro usuario del mismo club tampoco se puede generar. El permiso de
 * ESCRITURA sobre una vista del club (solo admin_club) lo aplica api/generate.php, el único caller.
 */
class ViewGenerator
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** Club de la sesión. Falla fuerte si no hay: sin club no hay consulta segura. */
    private function clubId(): int
    {
        $clubId = Auth::clubId();
        if ($clubId <= 0) {
            throw new RuntimeException('Sesión sin club: no se puede generar la vista.');
        }
        return $clubId;
    }

    /**
     * @return array{created:int, skipped:array<int,string>}
     */
    public function generate(int $viewId): array
    {
        $view = $this->fetchView($viewId);
        $datasets = $this->fetchDatasets($viewId);

        if (empty($datasets)) {
            throw new RuntimeException('Esta vista no tiene datasets asociados.');
        }

        $systemPrompt = $this->buildSystemPrompt();
        $userPrompt = $this->buildUserPrompt($view, $datasets);

        $responseText = AnthropicClient::complete($systemPrompt, $userPrompt, 8000);
        $widgetSpecs = AnthropicClient::extractJson($responseText);

        if (!is_array($widgetSpecs)) {
            throw new RuntimeException('La IA no devolvió una lista de widgets.');
        }

        $datasetsById = [];
        foreach ($datasets as $d) {
            $datasetsById[$d['id']] = $d;
        }

        $created = 0;
        $skipped = [];
        $position = 0;

        $clubId = $this->clubId();
        $insertWidget = $this->pdo->prepare(
            'INSERT INTO widgets (club_id, view_id, type, config, position)
             VALUES (:club, :view_id, :type, :config, :position)'
        );
        $insertVersion = $this->pdo->prepare(
            'INSERT INTO widget_versions (club_id, widget_id, config, source)
             VALUES (:club, :widget_id, :config, "initial")'
        );

        $this->pdo->beginTransaction();
        try {
            foreach ($widgetSpecs as $i => $spec) {
                $type = $spec['type'] ?? null;
                $config = $spec['config'] ?? null;
                $datasetId = (int) ($config['dataset_id'] ?? 0);

                if (!isset($datasetsById[$datasetId])) {
                    $skipped[] = "Widget #$i: dataset_id $datasetId no pertenece a esta vista.";
                    continue;
                }
                if (!is_array($config) || !isset($spec['title'])) {
                    $skipped[] = "Widget #$i: falta title o config.";
                    continue;
                }
                $config['title'] = $spec['title'];

                $columnSchema = $datasetsById[$datasetId]['column_schema'];
                $customMetrics = $this->fetchCustomMetrics($viewId, $datasetId);

                $errors = WidgetSchema::validate($type, $config, $columnSchema, $customMetrics);
                if (!empty($errors)) {
                    $skipped[] = "Widget #$i (\"{$spec['title']}\"): " . implode(' ', $errors);
                    continue;
                }

                $insertWidget->execute([
                    'club' => $clubId,
                    'view_id' => $viewId,
                    'type' => $type,
                    'config' => json_encode($config, JSON_UNESCAPED_UNICODE),
                    'position' => $position++,
                ]);
                $widgetId = (int) $this->pdo->lastInsertId();

                $insertVersion->execute([
                    'club' => $clubId,
                    'widget_id' => $widgetId,
                    'config' => json_encode($config, JSON_UNESCAPED_UNICODE),
                ]);

                $created++;
            }

            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw new RuntimeException('Error al guardar los widgets generados: ' . $e->getMessage());
        }

        if ($created === 0) {
            throw new RuntimeException('La IA no generó ningún widget válido. Detalle: ' . implode(' | ', $skipped));
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    private function fetchView(int $viewId): array
    {
        // Mismo predicado de visibilidad que Scope, escrito una sola vez en sqlVistaVisible():
        // club + (vista del club O mía). Una vista privada de OTRO usuario del mismo club no se
        // puede leer ni generar desde acá, aunque el club coincida.
        $stmt = $this->pdo->prepare(
            'SELECT v.id, v.nombre, v.description FROM views v
             WHERE v.id = :id AND v.club_id = :club AND ' . Scope::sqlVistaVisible()
        );
        $stmt->execute([
            'id' => $viewId,
            'club' => $this->clubId(),
            'user' => Auth::userId(),
        ]);
        $view = $stmt->fetch();
        if (!$view) {
            // Mismo mensaje para "no existe", "no es de tu club" y "es privada de otro": no
            // confirmamos ids ajenos.
            throw new RuntimeException('Vista no encontrada.');
        }
        return $view;
    }

    /** @return array<int,array{id:int,nombre:string,column_schema:array,player_column_name:?string}> */
    private function fetchDatasets(int $viewId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.id, d.nombre, d.column_schema, d.player_column_name
             FROM datasets d
             INNER JOIN view_datasets vd ON vd.dataset_id = d.id AND vd.club_id = d.club_id
             WHERE vd.view_id = :view_id AND d.club_id = :club'
        );
        $stmt->execute(['view_id' => $viewId, 'club' => $this->clubId()]);
        $datasets = $stmt->fetchAll();
        foreach ($datasets as &$d) {
            $d['column_schema'] = json_decode($d['column_schema'], true);
        }
        return $datasets;
    }

    private function fetchCustomMetrics(int $viewId, int $datasetId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, nombre, formula FROM custom_metrics
             WHERE view_id = :view_id AND dataset_id = :dataset_id AND club_id = :club'
        );
        $stmt->execute([
            'view_id' => $viewId,
            'dataset_id' => $datasetId,
            'club' => $this->clubId(),
        ]);
        return $stmt->fetchAll();
    }

    private function buildSystemPrompt(): string
    {
        return <<<PROMPT
Sos el motor de generación de dashboards de SportAnalysis, un producto para preparadores físicos de rugby.

Tu única salida es un ARRAY JSON de widgets. Nunca generás HTML, CSS, JavaScript ni código de ningún tipo — solo configuración declarativa que un renderer PHP fijo interpreta.

Reglas estrictas:
- Respondé ÚNICAMENTE con un array JSON válido, sin texto antes ni después, sin markdown.
- Cada widget debe usar SOLO columnas que existen en el dataset indicado (te paso el schema exacto de cada dataset).
- No inventes columnas ni datasets. Si la descripción pide algo que ninguna columna disponible puede resolver, omitilo.
- Generá entre 3 y 8 widgets. Podés proponer widgets adicionales útiles más allá de lo pedido literalmente (ej: un radar comparativo si hay datos de ambas familias), con moderación.
- Los tipos de widget disponibles son EXACTAMENTE estos 5, con esta forma de config:

1. "kpi_card":
{
  "type": "kpi_card",
  "title": "string",
  "config": {
    "dataset_id": number,
    "metric": { "source": "column", "column": "nombre exacto de columna numérica" } | { "source": "custom_metric", "metric_id": number },
    "aggregation": "sum"|"avg"|"min"|"max"|"count",
    "filter": { "column": "string", "operator": "eq"|"neq"|"gt"|"gte"|"lt"|"lte", "value": "string|number" } (opcional),
    "comparison": { "enabled": true, "reference_value": number, "label": "string" } (opcional),
    "number_format": { "decimals": number, "unit": "string" },
    "scale_selector": boolean
  }
}

2. "table":
{
  "type": "table",
  "title": "string",
  "config": {
    "dataset_id": number,
    "columns": [ { "source": "column", "column": "string", "label": "string", "aggregation": "sum"|"avg"|"min"|"max"|"count" } ],
    "row_grain": "player"|"player_session",
    "conditional_rules": [ { "column": "label de una columna arriba", "operator": "gt"|"gte"|"lt"|"lte"|"eq"|"between", "value": number|[number,number], "color": "moss"|"amber"|"clay" } ] (máximo 3, opcional),
    "default_sort": { "column": "label de columna", "direction": "asc"|"desc" } (opcional),
    "search_enabled": boolean,
    "scale_selector": boolean
  }
}

3. "line_chart":
{
  "type": "line_chart",
  "title": "string",
  "config": {
    "dataset_id": number,
    "y_metrics": [ { "source": "column", "column": "string", "label": "string" } ],
    "x_column": "columna de tipo fecha o categorica que actua como eje temporal/sesion",
    "group_by": "columna categorica opcional para separar en hasta 6 líneas" (opcional),
    "aggregation": "sum"|"avg"|"min"|"max"|"count",
    "style": "line"|"line_markers"
  }
}

4. "bar_chart":
{
  "type": "bar_chart",
  "title": "string",
  "config": {
    "dataset_id": number,
    "metric": { "source": "column", "column": "string" },
    "category_column": "columna categorica para el eje de categorias",
    "aggregation": "sum"|"avg"|"min"|"max"|"count",
    "order": "alphabetical"|"ranking",
    "orientation": "vertical"|"horizontal",
    "reference_line": { "value": number, "label": "string" } (opcional)
  }
}

5. "stacked_bar":
{
  "type": "stacked_bar",
  "title": "string",
  "config": {
    "dataset_id": number,
    "base_metric": { "source": "column", "column": "string" },
    "segment_column": "columna categorica, hasta 6 segmentos",
    "category_column": "columna categorica para el eje de categorias",
    "mode": "absolute"|"percent"
  }
}

Solo columnas de tipo "numerica" pueden usarse como metric/base_metric/y_metrics con agregaciones distintas de "count". Columnas de tipo "categorica" son las únicas válidas para group_by/segment_column.
PROMPT;
    }

    private function buildUserPrompt(array $view, array $datasets): string
    {
        $datasetsDescription = [];
        foreach ($datasets as $d) {
            $columns = [];
            foreach ($d['column_schema'] as $col => $colType) {
                $columns[] = "$col ($colType)";
            }
            $datasetsDescription[] = "Dataset id={$d['id']} \"{$d['nombre']}\": columnas: " . implode(', ', $columns);
        }

        return "Vista: \"{$view['nombre']}\"\n"
            . "Descripción del usuario: {$view['description']}\n\n"
            . "Datasets disponibles para esta vista:\n"
            . implode("\n", $datasetsDescription)
            . "\n\nGenerá el array JSON de widgets ahora.";
    }
}
