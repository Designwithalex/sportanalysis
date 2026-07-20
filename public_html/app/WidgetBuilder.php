<?php

require_once __DIR__ . '/AnthropicClient.php';
require_once __DIR__ . '/WidgetSchema.php';
require_once __DIR__ . '/WidgetRenderer.php';
require_once __DIR__ . '/Database.php';

/**
 * Genera UN widget a partir de un nombre + prompt en lenguaje natural del preparador físico.
 * Reemplaza al ViewGenerator (que generaba el dashboard entero de una): acá el PF arma la vista
 * widget por widget desde el panel SportAnalysis.
 *
 * La IA interpreta el pedido, elige tipo/columnas/agregación y decide qué datasets cruzar
 * (config.dataset_ids). Si el pedido es ambiguo, en vez de un widget devuelve preguntas para
 * guiar al PF (flujo multi-turno). Su único output sigue siendo JSON de configuración: nunca
 * genera HTML ni código, y todo widget se valida contra WidgetSchema antes de devolverse.
 */
class WidgetBuilder
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param string $name       nombre que el PF le puso al widget
     * @param string $prompt      pedido original en lenguaje natural
     * @param array<int,array{question:string,answer:string}> $answers aclaraciones ya respondidas
     * @return array{status:'question',questions:string[]}
     *        |array{status:'widget',type:string,config:array}
     *        |array{status:'error',error:string}
     */
    public function build(int $viewId, string $name, string $prompt, array $answers = []): array
    {
        $datasets = $this->fetchDatasets();
        if (empty($datasets)) {
            return ['status' => 'error', 'error' => 'Todavía no hay datasets cargados. Subí datos antes de crear widgets.'];
        }

        $systemPrompt = $this->buildSystemPrompt();
        $userPrompt = $this->buildUserPrompt($datasets, $name, $prompt, $answers);

        $responseText = AnthropicClient::complete($systemPrompt, $userPrompt, 3000);
        // La llamada a la IA puede tardar lo suficiente para que el hosting corte la conexión ociosa;
        // reconectamos antes de validar el widget contra la DB.
        $this->pdo = Database::ping();
        $result = AnthropicClient::extractJson($responseText);

        if (!is_array($result) || !isset($result['status'])) {
            return ['status' => 'error', 'error' => 'La IA no devolvió una respuesta interpretable. Reformulá el pedido.'];
        }

        if ($result['status'] === 'question') {
            $questions = array_values(array_filter(
                array_map('strval', $result['questions'] ?? []),
                fn($q) => trim($q) !== ''
            ));
            if (empty($questions)) {
                return ['status' => 'error', 'error' => 'La IA pidió aclaraciones pero no formuló preguntas. Reformulá el pedido.'];
            }
            return ['status' => 'question', 'questions' => array_slice($questions, 0, 3)];
        }

        if ($result['status'] !== 'widget') {
            return ['status' => 'error', 'error' => 'Respuesta desconocida de la IA.'];
        }

        $type = $result['type'] ?? null;
        $config = $result['config'] ?? null;
        if (!is_string($type) || !is_array($config)) {
            return ['status' => 'error', 'error' => 'La IA no devolvió un widget con tipo y configuración válidos.'];
        }
        if (isset($result['title']) && !isset($config['title'])) {
            $config['title'] = $result['title'];
        }
        if (empty($config['title'])) {
            $config['title'] = $name !== '' ? $name : 'Widget';
        }

        return $this->validateWidget($viewId, $type, $config);
    }

    /**
     * @return array{status:'widget',type:string,config:array}|array{status:'error',error:string}
     */
    private function validateWidget(int $viewId, string $type, array $config): array
    {
        $datasetIds = WidgetRenderer::datasetIds($config);
        if (empty($datasetIds)) {
            return ['status' => 'error', 'error' => 'El widget generado no indica ningún dataset.'];
        }

        $placeholders = implode(',', array_fill(0, count($datasetIds), '?'));
        $stmt = $this->pdo->prepare("SELECT column_schema FROM datasets WHERE id IN ($placeholders)");
        $stmt->execute($datasetIds);
        $found = $stmt->fetchAll();
        if (count($found) !== count($datasetIds)) {
            return ['status' => 'error', 'error' => 'La IA eligió un dataset que no existe.'];
        }
        $schemas = array_map(fn($r) => json_decode($r['column_schema'], true), $found);
        $effectiveSchema = WidgetSchema::effectiveSchema($schemas);

        $metricsStmt = $this->pdo->prepare(
            "SELECT id, nombre FROM custom_metrics WHERE view_id = ? AND dataset_id IN ($placeholders)"
        );
        $metricsStmt->execute(array_merge([$viewId], $datasetIds));
        $customMetrics = $metricsStmt->fetchAll();

        $errors = WidgetSchema::validate($type, $config, $effectiveSchema, $customMetrics);
        if (!empty($errors)) {
            return ['status' => 'error', 'error' => 'El widget propuesto no es válido: ' . implode(' ', $errors)];
        }

        // Normalizamos: guardamos siempre dataset_ids (array), sin el viejo dataset_id.
        $config['dataset_ids'] = $datasetIds;
        unset($config['dataset_id']);

        return ['status' => 'widget', 'type' => $type, 'config' => $config];
    }

    /** @return array<int,array{id:int,nombre:string,categoria:string,column_schema:array}> */
    private function fetchDatasets(): array
    {
        $datasets = $this->pdo->query(
            'SELECT id, nombre, categoria, column_schema FROM datasets ORDER BY categoria, uploaded_at'
        )->fetchAll();
        foreach ($datasets as &$d) {
            $d['id'] = (int) $d['id'];
            $d['column_schema'] = json_decode($d['column_schema'], true);
            $d['empty_columns'] = $this->emptyColumns($d['id'], $d['column_schema']);
        }
        return $datasets;
    }

    /**
     * Columnas sin datos útiles (todas vacías o todas en cero) según una muestra de filas matcheadas.
     * Común en planillas de fuerza: meses futuros sin registrar, o columnas de puesto sin completar.
     * Se le marcan a la IA para que no arme widgets sobre columnas muertas.
     * @return array<string,true> set de nombres de columna sin datos
     */
    private function emptyColumns(int $datasetId, array $columnSchema): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT raw_data FROM dataset_rows WHERE dataset_id = :id AND match_status = 'matched' LIMIT 300"
        );
        $stmt->execute(['id' => $datasetId]);
        $rows = array_map(fn($r) => json_decode($r, true) ?: [], $stmt->fetchAll(PDO::FETCH_COLUMN));
        if (empty($rows)) {
            return [];
        }

        $empty = [];
        foreach (array_keys($columnSchema) as $col) {
            $hasData = false;
            foreach ($rows as $row) {
                $v = $row[$col] ?? '';
                if ($v === '' || $v === null) {
                    continue;
                }
                $num = is_numeric(str_replace(',', '.', (string) $v)) ? (float) str_replace(',', '.', (string) $v) : null;
                if ($num !== null && $num == 0.0) {
                    continue; // cero = no registrado todavía, no cuenta como dato
                }
                $hasData = true;
                break;
            }
            if (!$hasData) {
                $empty[$col] = true;
            }
        }
        return $empty;
    }

    private function buildSystemPrompt(): string
    {
        return <<<PROMPT
Sos el asistente que arma widgets de SportAnalysis, un producto para preparadores físicos (PF) de rugby. El PF te da el NOMBRE de un widget y un PEDIDO en lenguaje natural, y vos armás UN solo widget.

Tu ÚNICA salida es un objeto JSON (sin markdown, sin texto antes ni después) con uno de estos dos formatos:

A) Si el pedido es claro y se puede resolver con los datos disponibles:
{ "status": "widget", "type": "<uno de los 5 tipos>", "config": { ... } }

B) Si el pedido es ambiguo o le falta información clave (qué métrica, cómo agrupar, qué partidos/datasets, total o por jugador, etc.):
{ "status": "question", "questions": ["pregunta 1", "pregunta 2"] }

Reglas del flujo:
- Preguntá SOLO si es realmente necesario, máximo 3 preguntas, cortas y concretas. Si el PF ya respondió aclaraciones (te las paso más abajo), NO vuelvas a preguntar lo mismo: usá lo respondido y devolvé el widget.
- Nunca inventes columnas ni datasets. Usá exactamente los nombres de columna que te doy.
- Vos hacés el trabajo de cruzar datos y elegir agregaciones: el PF no crea métricas a mano.

CÓMO CRUZAR DATOS (importante):
- Cada partido o sesión se sube como su propio dataset. Para cruzar varios (ej: "promedio por partido de los últimos 5 partidos") poné en config varios ids en "dataset_ids": [.., .., ..].
- Cuando un widget abarca varios datasets, tenés disponible la columna sintética "__dataset" (categórica), que vale el nombre del dataset de origen de cada fila = el partido/sesión. Para "AVG de metros por partido": dataset_ids con los partidos, eje/categoría/agrupación = "__dataset", agregación = "avg".
- Otras columnas sintéticas siempre disponibles: "__familia" (back/forward), "__sub_familia", "__player_nombre".
- Al elegir varios datasets, solo podés usar columnas que existan en TODOS ellos (te indico las columnas de cada uno).

DATOS FALTANTES Y DIMENSIONES DEL PLANTEL (crítico para no armar widgets vacíos):
- Las columnas marcadas "[SIN DATOS]" están vacías o todas en cero (ej: meses todavía no registrados, o columnas que el PF no completó). NUNCA armes un widget sobre esas columnas ni agrupes por ellas: darían una tabla/gráfico vacío. Si TODAS las columnas que servirían para el pedido están [SIN DATOS], devolvé una pregunta explicando que esos datos todavía no están cargados.
- Para el PUESTO / posición del jugador usá SIEMPRE la columna sintética "__sub_familia", y para back/forward usá "__familia". Vienen del plantel y siempre tienen datos. NO uses columnas del CSV tipo "PUESTO", "POSICION" o similar aunque existan, porque suelen venir incompletas.

Los 5 tipos de widget y la forma EXACTA de su config (config SIEMPRE lleva "dataset_ids": [int, ...]):

1. "kpi_card":
{ "type":"kpi_card","config":{ "dataset_ids":[int],
  "metric": {"source":"column","column":"<numérica>"} | {"source":"custom_metric","metric_id":int},
  "aggregation":"sum"|"avg"|"min"|"max"|"count",
  "filter": {"column":"<col>","operator":"eq"|"neq"|"gt"|"gte"|"lt"|"lte","value":<str|num>} (opcional),
  "comparison": {"enabled":true,"reference_value":num,"label":"str"} (opcional),
  "number_format": {"decimals":int,"unit":"str"},
  "scale_selector": bool } }

2. "table":
{ "type":"table","config":{ "dataset_ids":[int],
  "columns":[ {"source":"column","column":"<col>","label":"str","aggregation":"sum"|"avg"|"min"|"max"|"count"|"text"} ],
  // aggregation "text" = columna de dimensión: muestra el valor tal cual, sin agregar. Usala para
  // columnas de texto/categóricas como sub-familia (__sub_familia), familia (__familia), posición o
  // partido (__dataset). Las columnas numéricas usan sum/avg/min/max/count.
  "row_grain":"player"|"player_session"|"<columna categórica para agrupar filas, ej __sub_familia, __familia, __dataset>",
  "conditional_rules":[ {"column":"<label de una columna de arriba>","operator":"gt"|"gte"|"lt"|"lte"|"eq"|"between","value":num|[num,num],"color":"moss"|"amber"|"clay"} ] (máx 3, opcional),
  "default_sort": {"column":"<label>","direction":"asc"|"desc"} (opcional),
  "search_enabled": bool, "scale_selector": bool } }

3. "line_chart":
{ "type":"line_chart","config":{ "dataset_ids":[int],
  "y_metrics":[ {"source":"column","column":"<col>","label":"str"} ],
  "x_column":"<columna fecha/categórica que actúa de eje temporal; usá __dataset para 'por partido'>",
  "group_by":"<categórica opcional, hasta 6 líneas>" (opcional),
  "aggregation":"sum"|"avg"|"min"|"max"|"count",
  "style":"line"|"line_markers" } }

4. "bar_chart":
{ "type":"bar_chart","config":{ "dataset_ids":[int],
  "metric": {"source":"column","column":"<col>"},
  "category_column":"<categórica; usá __dataset para 'por partido'>",
  "aggregation":"sum"|"avg"|"min"|"max"|"count",
  "order":"alphabetical"|"ranking",
  "orientation":"vertical"|"horizontal",
  "reference_line": {"value":num,"label":"str"} (opcional) } }

5. "stacked_bar":
{ "type":"stacked_bar","config":{ "dataset_ids":[int],
  "base_metric": {"source":"column","column":"<col>"},
  "segment_column":"<categórica, hasta 6 segmentos>",
  "category_column":"<categórica>",
  "mode":"absolute"|"percent" } }

Solo columnas de tipo "numerica" sirven como metric/base_metric/y_metrics con agregaciones distintas de "count". Las columnas "categorica" (incluidas las sintéticas) son las únicas válidas para group_by/segment_column.
PROMPT;
    }

    /**
     * @param array<int,array{id:int,nombre:string,categoria:string,column_schema:array}> $datasets
     * @param array<int,array{question:string,answer:string}> $answers
     */
    private function buildUserPrompt(array $datasets, string $name, string $prompt, array $answers): string
    {
        $byCategoria = [];
        foreach ($datasets as $d) {
            $empty = $d['empty_columns'] ?? [];
            $columns = [];
            foreach ($d['column_schema'] as $col => $colType) {
                $mark = isset($empty[$col]) ? ' [SIN DATOS]' : '';
                $columns[] = "$col ($colType)$mark";
            }
            $byCategoria[$d['categoria']][] = "  · id={$d['id']} \"{$d['nombre']}\": " . implode(', ', $columns);
        }

        $catalog = [];
        foreach ($byCategoria as $categoria => $lines) {
            $catalog[] = strtoupper($categoria) . ":\n" . implode("\n", $lines);
        }

        $text = "Datasets disponibles (agrupados por categoría):\n" . implode("\n", $catalog) . "\n\n"
            . "Nombre del widget: \"$name\"\n"
            . "Pedido del PF: \"$prompt\"\n";

        if (!empty($answers)) {
            $text .= "\nAclaraciones que el PF ya respondió:\n";
            foreach ($answers as $qa) {
                $q = trim($qa['question'] ?? '');
                $a = trim($qa['answer'] ?? '');
                if ($q !== '' || $a !== '') {
                    $text .= "  Q: $q\n  A: $a\n";
                }
            }
            $text .= "\nUsá estas aclaraciones y devolvé el widget (status \"widget\"). No repitas preguntas ya respondidas.\n";
        }

        $text .= "\nRespondé ahora con el JSON.";
        return $text;
    }
}
