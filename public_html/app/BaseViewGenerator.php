<?php

require_once __DIR__ . '/AnthropicClient.php';
require_once __DIR__ . '/WidgetSchema.php';
require_once __DIR__ . '/WidgetRenderer.php';
require_once __DIR__ . '/Database.php';

/**
 * Genera "vistas base" sugeridas por IA a partir de los datos ya cargados, sin que el PF tenga que
 * armar cada widget a mano. Dos ejes:
 *
 *   - Por CLUSTER de datos (categoría de datasets: partidos / entrenamientos / fuerza / nutrición):
 *     un tablero de 4-8 widgets que cruza TODOS los datasets de esa categoría (varios partidos ⇒
 *     info agregada a través de los partidos vía dataset_ids + la columna sintética __dataset).
 *
 *   - Por JUGADOR: una plantilla de widgets de "overview" (1 sola llamada IA) que después se instancia
 *     como una vista por jugador, cada una recortada a ese jugador con un filtro global __player_nombre.
 *
 * Igual que el resto del producto, la IA solo devuelve JSON declarativo: cada widget se valida contra
 * WidgetSchema antes de persistir. Nunca genera HTML ni código.
 */
class BaseViewGenerator
{
    private const CATEGORIA_LABELS = [
        'partidos' => 'Partidos',
        'entrenamientos' => 'Entrenamientos',
        'fuerza' => 'Fuerza',
        'nutricion' => 'Nutrición',
        'otros' => 'Otros datos',
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @return array<string,string> categoria => label, solo las categorías que tienen datasets. */
    public function nonEmptyClusters(): array
    {
        $counts = $this->pdo->query(
            'SELECT categoria, COUNT(*) AS c FROM datasets GROUP BY categoria'
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        $out = [];
        foreach (self::CATEGORIA_LABELS as $key => $label) {
            if ((int) ($counts[$key] ?? 0) > 0) {
                $out[$key] = $label;
            }
        }
        return $out;
    }

    /**
     * Sugerencias de "qué mirar" por cluster (checklist del modo guiado). Una sola llamada IA que
     * combina las columnas reales de cada categoría con conocimiento de rugby.
     * @return array<string,string[]> categoria => lista de chips sugeridos
     */
    public function suggestChecklists(): array
    {
        $clusters = $this->nonEmptyClusters();
        if (empty($clusters)) {
            throw new RuntimeException('No hay datasets cargados todavía.');
        }

        $system = <<<PROMPT
Sos asesor de preparadores físicos (PF) de rugby. A partir de las columnas reales de los datos cargados y de tu conocimiento del rugby, proponé para cada categoría una lista corta de MÉTRICAS/COSAS que un PF típicamente quiere ver.

Respondé ÚNICAMENTE con un objeto JSON, sin markdown ni texto extra. Las claves del objeto son EXACTAMENTE las claves de categoría que te indico (aunque una categoría se llame "otros" o su nombre no describa el contenido, mirá sus columnas y proponé igual).

Reglas:
- 4 a 7 ítems por categoría, cada uno corto (2 a 6 palabras), accionable y concreto (ej: "Metros promedio por partido", "Comparativa backs vs forwards", "Evolución de sprints por sesión").
- Basate en las columnas disponibles y en qué miden realmente (ej: si hay distancia, sprints, aceleraciones = datos de partido/GPS aunque la categoría diga "otros"). No propongas algo que ninguna columna pueda alimentar.
- Si una categoría casi no tiene datos numéricos útiles, devolvé menos ítems (pero intentá siempre proponer al menos 2).
PROMPT;

        $keyList = [];
        foreach ($clusters as $key => $label) {
            $keyList[] = "\"$key\" (categoría mostrada como \"$label\")";
        }
        $user = "Devolvé un objeto JSON con EXACTAMENTE estas claves: " . implode(', ', $keyList) . ".\n\n"
            . "Columnas disponibles por categoría (el encabezado en MAYÚSCULAS es la clave):\n"
            . $this->describeClusters($clusters)
            . "\n\nRespondé el JSON ahora usando esas claves exactas.";

        $result = AnthropicClient::extractJson(AnthropicClient::complete($system, $user, 2000));

        $out = [];
        foreach ($clusters as $key => $label) {
            $items = $result[$key] ?? [];
            if (!is_array($items)) {
                $items = [];
            }
            $items = array_values(array_filter(array_map(
                fn($s) => trim((string) $s),
                $items
            ), fn($s) => $s !== ''));
            $out[$key] = array_slice($items, 0, 7);
        }
        return $out;
    }

    /**
     * Genera (o regenera) la vista base de una categoría: un tablero que cruza todos sus datasets.
     * @return array{view_id:int, nombre:string, created:int, skipped:string[]}
     */
    public function generateCluster(string $categoria, string $intent = ''): array
    {
        if (!isset(self::CATEGORIA_LABELS[$categoria])) {
            throw new RuntimeException("Categoría desconocida: $categoria");
        }
        $datasets = $this->fetchDatasets("categoria = ?", [$categoria]);
        if (empty($datasets)) {
            throw new RuntimeException('No hay datasets en la categoría ' . self::CATEGORIA_LABELS[$categoria] . '.');
        }

        $label = self::CATEGORIA_LABELS[$categoria];
        $system = $this->clusterSystemPrompt($label);
        $user = $this->clusterUserPrompt($label, $datasets, $intent);

        $specs = AnthropicClient::extractJson(AnthropicClient::complete($system, $user, 8000));
        // La llamada a la IA es larga: reconectamos antes de volver a tocar la DB.
        $this->pdo = Database::ping();
        if (!is_array($specs)) {
            throw new RuntimeException('La IA no devolvió una lista de widgets.');
        }

        $datasetIds = array_map(fn($d) => $d['id'], $datasets);
        [$widgets, $skipped] = $this->validateSpecs($specs, $datasetIds);
        if (empty($widgets)) {
            throw new RuntimeException('La IA no generó ningún widget válido para ' . $label . '. Detalle: ' . implode(' | ', $skipped));
        }

        $viewId = $this->upsertView('cluster', $label, ['categoria' => $categoria], $intent, $datasetIds, $widgets);
        return ['view_id' => $viewId, 'nombre' => $label, 'created' => count($widgets), 'skipped' => $skipped];
    }

    /**
     * Genera la plantilla de widgets del overview de UN jugador (1 llamada IA, no persiste).
     * Los widgets no fijan ningún jugador: la vista se recorta después con un filtro global.
     * @return array<int,array{type:string,config:array}>
     */
    public function generatePlayerTemplate(): array
    {
        $datasets = $this->fetchDatasets('1 = 1', []);
        if (empty($datasets)) {
            throw new RuntimeException('No hay datasets cargados todavía.');
        }

        $system = $this->playerSystemPrompt();
        $user = "Datasets disponibles (agrupados por categoría):\n"
            . $this->describeDatasets($datasets)
            . "\n\nArmá el array JSON de widgets del overview de un jugador ahora.";

        $specs = AnthropicClient::extractJson(AnthropicClient::complete($system, $user, 8000));
        // La llamada a la IA es larga: reconectamos antes de volver a tocar la DB.
        $this->pdo = Database::ping();
        if (!is_array($specs)) {
            throw new RuntimeException('La IA no devolvió una lista de widgets.');
        }

        $datasetIds = array_map(fn($d) => $d['id'], $datasets);
        [$widgets, $skipped] = $this->validateSpecs($specs, $datasetIds);
        if (empty($widgets)) {
            throw new RuntimeException('La IA no generó ningún widget válido para el overview de jugador. Detalle: ' . implode(' | ', $skipped));
        }
        return $widgets;
    }

    /**
     * Instancia la plantilla como una vista por jugador, cada una recortada a su jugador con un
     * filtro global __player_nombre. Sin llamadas IA: clona la plantilla en la DB.
     * @param array<int,array{type:string,config:array}> $templateWidgets
     * @return array<int,array{view_id:int, nombre:string}>
     */
    public function instantiatePlayerViews(array $templateWidgets): array
    {
        if (empty($templateWidgets)) {
            throw new RuntimeException('No hay widgets plantilla para instanciar.');
        }
        $players = $this->pdo->query('SELECT id, nombre FROM players ORDER BY nombre')->fetchAll();
        if (empty($players)) {
            throw new RuntimeException('No hay jugadores en el plantel.');
        }

        // Datasets que tocan los widgets plantilla → view_datasets de cada vista.
        $datasetIds = [];
        foreach ($templateWidgets as $w) {
            foreach (WidgetRenderer::datasetIds($w['config']) as $id) {
                $datasetIds[$id] = true;
            }
        }
        $datasetIds = array_keys($datasetIds);

        $created = [];
        foreach ($players as $p) {
            $nombre = 'Overview — ' . $p['nombre'];
            $viewId = $this->upsertView(
                'player',
                $nombre,
                ['player_id' => (int) $p['id']],
                'Overview de performance de ' . $p['nombre'],
                $datasetIds,
                $templateWidgets,
                ['column' => '__player_nombre', 'value' => $p['nombre']]
            );
            $created[] = ['view_id' => $viewId, 'nombre' => $nombre];
        }
        return $created;
    }

    // ---------------------------------------------------------------------
    // Persistencia
    // ---------------------------------------------------------------------

    /**
     * Crea o reemplaza una vista base (idempotente por tipo+categoría o tipo+player_id) y la llena
     * con los widgets dados. Devuelve el view_id.
     * @param array{categoria?:string,player_id?:int} $key
     * @param array<int,array{type:string,config:array}> $widgets
     * @param array{column:string,value:string}|null $globalFilter filtro global de vista (overview jugador)
     */
    private function upsertView(string $tipo, string $nombre, array $key, string $intent, array $datasetIds, array $widgets, ?array $globalFilter = null): int
    {
        $this->pdo->beginTransaction();
        try {
            // ¿existe ya una vista base para esta clave? → la reusamos y limpiamos su contenido.
            if (isset($key['categoria'])) {
                $sel = $this->pdo->prepare('SELECT id FROM views WHERE tipo = "cluster" AND categoria = ? LIMIT 1');
                $sel->execute([$key['categoria']]);
            } else {
                $sel = $this->pdo->prepare('SELECT id FROM views WHERE tipo = "player" AND player_id = ? LIMIT 1');
                $sel->execute([$key['player_id']]);
            }
            $viewId = (int) ($sel->fetchColumn() ?: 0);

            if ($viewId > 0) {
                $this->pdo->prepare('UPDATE views SET nombre = ?, description = ? WHERE id = ?')
                    ->execute([$nombre, $intent, $viewId]);
                // Limpiamos contenido previo (widgets → cascada a versiones; datasets y filtros).
                $this->pdo->prepare('DELETE FROM widgets WHERE view_id = ?')->execute([$viewId]);
                $this->pdo->prepare('DELETE FROM view_datasets WHERE view_id = ?')->execute([$viewId]);
                $this->pdo->prepare('DELETE FROM view_filters WHERE view_id = ?')->execute([$viewId]);
            } else {
                $pos = (int) $this->pdo->query('SELECT COALESCE(MAX(position), -1) + 1 FROM views')->fetchColumn();
                $this->pdo->prepare(
                    'INSERT INTO views (nombre, tipo, categoria, player_id, description, position) VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([
                    $nombre,
                    $tipo,
                    $key['categoria'] ?? null,
                    $key['player_id'] ?? null,
                    $intent,
                    $pos,
                ]);
                $viewId = (int) $this->pdo->lastInsertId();
            }

            $vdStmt = $this->pdo->prepare('INSERT INTO view_datasets (view_id, dataset_id) VALUES (?, ?)');
            foreach (array_unique(array_map('intval', $datasetIds)) as $did) {
                $vdStmt->execute([$viewId, $did]);
            }

            $wStmt = $this->pdo->prepare('INSERT INTO widgets (view_id, type, config, position) VALUES (?, ?, ?, ?)');
            $vStmt = $this->pdo->prepare('INSERT INTO widget_versions (widget_id, config, source) VALUES (?, ?, "initial")');
            $position = 0;
            foreach ($widgets as $w) {
                $encoded = json_encode($w['config'], JSON_UNESCAPED_UNICODE);
                $wStmt->execute([$viewId, $w['type'], $encoded, $position++]);
                $vStmt->execute([(int) $this->pdo->lastInsertId(), $encoded]);
            }

            if ($globalFilter !== null) {
                $this->pdo->prepare(
                    'INSERT INTO view_filters (view_id, dataset_id, column_name, filter_type, config) VALUES (?, NULL, ?, "valores", ?)'
                )->execute([
                    $viewId,
                    $globalFilter['column'],
                    json_encode(['operator' => 'eq', 'value' => $globalFilter['value']], JSON_UNESCAPED_UNICODE),
                ]);
            }

            $this->pdo->commit();
            return $viewId;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw new RuntimeException('Error al guardar la vista base: ' . $e->getMessage());
        }
    }

    // ---------------------------------------------------------------------
    // Validación de specs (compartida por cluster y jugador)
    // ---------------------------------------------------------------------

    /**
     * Valida una lista de specs de widget contra el conjunto de datasets permitido.
     * @param array<int,int> $allowedDatasetIds
     * @return array{0: array<int,array{type:string,config:array}>, 1: string[]}  [widgets válidos, skipped]
     */
    private function validateSpecs(array $specs, array $allowedDatasetIds): array
    {
        $allowed = array_flip(array_map('intval', $allowedDatasetIds));
        $widgets = [];
        $skipped = [];

        foreach ($specs as $i => $spec) {
            $type = $spec['type'] ?? null;
            $config = $spec['config'] ?? null;
            if (!is_string($type) || !is_array($config)) {
                $skipped[] = "Widget #$i: falta type o config.";
                continue;
            }
            if (isset($spec['title']) && empty($config['title'])) {
                $config['title'] = $spec['title'];
            }
            if (empty($config['title'])) {
                $config['title'] = 'Widget';
            }

            $datasetIds = WidgetRenderer::datasetIds($config);
            if (empty($datasetIds)) {
                $skipped[] = "Widget #$i (\"{$config['title']}\"): no indica datasets.";
                continue;
            }
            foreach ($datasetIds as $did) {
                if (!isset($allowed[$did])) {
                    $skipped[] = "Widget #$i (\"{$config['title']}\"): dataset $did fuera del alcance.";
                    continue 2;
                }
            }

            $effectiveSchema = $this->effectiveSchemaFor($datasetIds);
            // Las vistas base no usan métricas configurables (view-scoped): validamos sin ellas.
            $errors = WidgetSchema::validate($type, $config, $effectiveSchema, []);
            if (!empty($errors)) {
                $skipped[] = "Widget #$i (\"{$config['title']}\"): " . implode(' ', $errors);
                continue;
            }

            $config['dataset_ids'] = $datasetIds;
            unset($config['dataset_id']);
            $widgets[] = ['type' => $type, 'config' => $config];
        }

        return [$widgets, $skipped];
    }

    /** @param int[] $datasetIds */
    private function effectiveSchemaFor(array $datasetIds): array
    {
        $placeholders = implode(',', array_fill(0, count($datasetIds), '?'));
        $stmt = $this->pdo->prepare("SELECT column_schema FROM datasets WHERE id IN ($placeholders)");
        $stmt->execute($datasetIds);
        $schemas = array_map(fn($json) => json_decode($json, true), $stmt->fetchAll(PDO::FETCH_COLUMN));
        return WidgetSchema::effectiveSchema($schemas);
    }

    // ---------------------------------------------------------------------
    // Datos + prompts
    // ---------------------------------------------------------------------

    /**
     * @return array<int,array{id:int,nombre:string,categoria:string,column_schema:array,empty_columns:array}>
     */
    private function fetchDatasets(string $where, array $params): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, nombre, categoria, column_schema FROM datasets WHERE $where ORDER BY categoria, uploaded_at"
        );
        $stmt->execute($params);
        $datasets = $stmt->fetchAll();
        foreach ($datasets as &$d) {
            $d['id'] = (int) $d['id'];
            $d['column_schema'] = json_decode($d['column_schema'], true) ?: [];
            $d['empty_columns'] = $this->emptyColumns($d['id'], $d['column_schema']);
        }
        return $datasets;
    }

    /**
     * Columnas sin datos útiles (todas vacías o todas en cero) en una muestra de filas matcheadas.
     * Misma heurística que WidgetBuilder: se le marcan a la IA para que no arme widgets vacíos.
     * @return array<string,true>
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
                    continue;
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

    /** Catálogo de datasets agrupado por categoría, con tipo de columna y marca [SIN DATOS]. */
    private function describeDatasets(array $datasets): string
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
        return implode("\n", $catalog);
    }

    /** Igual que describeDatasets pero cargando los datasets de un set de categorías (para suggest). */
    private function describeClusters(array $clusters): string
    {
        $keys = array_keys($clusters);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $datasets = $this->fetchDatasets("categoria IN ($placeholders)", $keys);
        return $this->describeDatasets($datasets);
    }

    private function clusterUserPrompt(string $label, array $datasets, string $intent): string
    {
        $text = "Categoría: $label\n\n"
            . "Datasets de esta categoría (cada uno es un partido/sesión/test):\n"
            . $this->describeDatasets($datasets) . "\n\n";

        if (trim($intent) !== '') {
            $text .= "El PF pidió ver especialmente esto:\n" . trim($intent) . "\n\n";
        } else {
            $text .= "El PF no especificó nada: armá el tablero con las métricas más relevantes para un PF de rugby en esta categoría.\n\n";
        }
        $text .= "Generá el array JSON de widgets ahora.";
        return $text;
    }

    private function clusterSystemPrompt(string $label): string
    {
        return "Sos el motor de vistas base de SportAnalysis, un producto para preparadores físicos (PF) de rugby. "
            . "Te doy TODOS los datasets de la categoría \"$label\" (cada dataset es un partido/sesión/test distinto) y armás un TABLERO de 4 a 8 widgets.\n\n"
            . "Tu ÚNICA salida es un ARRAY JSON de widgets, sin markdown ni texto antes o después: "
            . "[ { \"type\": \"<tipo>\", \"title\": \"<título>\", \"config\": { ... } }, ... ]\n\n"
            . "REGLA CLAVE DE AGREGACIÓN: como hay varios partidos/sesiones (varios datasets), casi todos los widgets deben CRUZARLOS: "
            . "poné en config \"dataset_ids\" con los ids de TODOS los datasets de esta categoría, y usá la columna sintética \"__dataset\" "
            . "(categórica = el partido/sesión de origen) como eje/categoría/agrupación cuando quieras ver la evolución partido a partido. "
            . "Ej: \"metros promedio por partido\" = dataset_ids con todos, category/x = \"__dataset\", aggregation = \"avg\".\n\n"
            . $this->commonWidgetRules();
    }

    private function playerSystemPrompt(): string
    {
        return "Sos el motor de vistas base de SportAnalysis, un producto para preparadores físicos (PF) de rugby. "
            . "Armás un TABLERO de OVERVIEW de UN jugador: la vista se va a recortar automáticamente a un solo jugador con un filtro global, "
            . "así que NO fijes ningún jugador ni uses \"__player_nombre\" como filtro o agrupación. Pensá cada widget como \"los datos de este jugador\".\n\n"
            . "Tu ÚNICA salida es un ARRAY JSON de 4 a 7 widgets, sin markdown ni texto antes o después: "
            . "[ { \"type\": \"<tipo>\", \"title\": \"<título>\", \"config\": { ... } }, ... ]\n\n"
            . "REGLAS DE ESTE OVERVIEW:\n"
            . "- Mostrá la evolución del jugador sesión a sesión / partido a partido: usá \"__dataset\" (el partido/sesión) como eje temporal (x_column) o categoría.\n"
            . "- Cada widget solo puede usar columnas que existan en TODOS sus dataset_ids. En la práctica, agrupá en cada widget los datasets que comparten columnas (normalmente los de una misma categoría: todos los partidos juntos, todas las sesiones de fuerza juntas, etc.). NO mezcles categorías distintas en un mismo widget.\n"
            . "- Cubrí varias facetas del jugador con las categorías disponibles (carga de partidos, entrenamientos, fuerza, nutrición), un par de widgets por faceta.\n\n"
            . $this->commonWidgetRules();
    }

    /** Reglas + esquema de los 5 tipos de widget, compartidas por cluster y jugador. */
    private function commonWidgetRules(): string
    {
        return <<<PROMPT
REGLAS GENERALES:
- Nunca inventes columnas ni datasets. Usá exactamente los nombres de columna y los ids que te doy.
- NO uses métricas configurables (source "custom_metric"): estas vistas no tienen métricas propias. Usá siempre source "column".
- Columnas marcadas "[SIN DATOS]" están vacías o en cero: NUNCA armes un widget sobre ellas ni agrupes por ellas.
- Para el puesto/posición usá la columna sintética "__sub_familia"; para back/forward usá "__familia". Vienen del plantel y siempre tienen datos. No uses columnas del CSV tipo "PUESTO"/"POSICION".
- Columnas sintéticas siempre disponibles (categóricas/texto): "__dataset" (partido/sesión de origen), "__familia", "__sub_familia", "__player_nombre".
- Solo columnas "numerica" sirven como metric/base_metric/y_metrics con agregaciones distintas de "count". Las "categorica" (incluidas las sintéticas) son las únicas válidas para group_by/segment_column.

Los 5 tipos de widget y la forma EXACTA de su config (config SIEMPRE lleva "dataset_ids": [int, ...]):

1. "kpi_card":
{ "type":"kpi_card","title":"str","config":{ "dataset_ids":[int],
  "metric": {"source":"column","column":"<numérica>"},
  "aggregation":"sum"|"avg"|"min"|"max"|"count",
  "filter": {"column":"<col>","operator":"eq"|"neq"|"gt"|"gte"|"lt"|"lte","value":<str|num>} (opcional),
  "number_format": {"decimals":int,"unit":"str"},
  "scale_selector": bool } }

2. "table":
{ "type":"table","title":"str","config":{ "dataset_ids":[int],
  "columns":[ {"source":"column","column":"<col>","label":"str","aggregation":"sum"|"avg"|"min"|"max"|"count"|"text"} ],
  "row_grain":"player"|"player_session"|"<columna categórica, ej __dataset, __sub_familia>",
  "conditional_rules":[ {"column":"<label de una columna de arriba>","operator":"gt"|"gte"|"lt"|"lte"|"eq"|"between","value":num|[num,num],"color":"moss"|"amber"|"clay"} ] (máx 3, opcional),
  "default_sort": {"column":"<label>","direction":"asc"|"desc"} (opcional),
  "search_enabled": bool, "scale_selector": bool } }
  (aggregation "text" = columna de dimensión, muestra el valor sin agregar; usala para columnas categóricas/texto como __sub_familia o __dataset.)

3. "line_chart":
{ "type":"line_chart","title":"str","config":{ "dataset_ids":[int],
  "y_metrics":[ {"source":"column","column":"<numérica>","label":"str"} ],
  "x_column":"<columna fecha/categórica de eje temporal; usá __dataset para 'por partido/sesión'>",
  "group_by":"<categórica opcional, hasta 6 líneas>" (opcional),
  "aggregation":"sum"|"avg"|"min"|"max"|"count",
  "style":"line"|"line_markers" } }

4. "bar_chart":
{ "type":"bar_chart","title":"str","config":{ "dataset_ids":[int],
  "metric": {"source":"column","column":"<numérica>"},
  "category_column":"<categórica; usá __dataset para 'por partido/sesión'>",
  "aggregation":"sum"|"avg"|"min"|"max"|"count",
  "order":"alphabetical"|"ranking",
  "orientation":"vertical"|"horizontal",
  "reference_line": {"value":num,"label":"str"} (opcional) } }

5. "stacked_bar":
{ "type":"stacked_bar","title":"str","config":{ "dataset_ids":[int],
  "base_metric": {"source":"column","column":"<numérica>"},
  "segment_column":"<categórica, hasta 6 segmentos>",
  "category_column":"<categórica>",
  "mode":"absolute"|"percent" } }
PROMPT;
    }
}
