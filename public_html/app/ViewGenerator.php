<?php

require_once __DIR__ . '/AnthropicClient.php';
require_once __DIR__ . '/WidgetSchema.php';
require_once __DIR__ . '/WidgetRenderer.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Scope.php';

/**
 * Genera la GRILLA ENTERA de una vista a partir de su descripción en lenguaje natural.
 *
 * Es el Paso 3 → Paso 4 del producto (ver CLAUDE.md): el PF escribe qué quiere ver y la IA arma
 * el tablero completo de una. Convive con las otras dos entradas de generación, que resuelven
 * problemas distintos y no lo reemplazan:
 *
 *   · WidgetBuilder      — UN widget por pedido, con repregunta si el pedido es ambiguo.
 *   · BaseViewGenerator  — las vistas BASE del club (user_id NULL), por categoría y por jugador.
 *   · ViewGenerator      — este: la vista entera desde la `description` de la vista.
 *
 * SEMÁNTICA: REEMPLAZO, NO ACUMULACIÓN. Generar dos veces sobre la misma vista no duplica el
 * tablero: borra los widgets actuales y crea los nuevos. Es la misma decisión que ya tomaba
 * BaseViewGenerator::upsertView(), y por las mismas razones — "regenerar" tiene que dejar la vista
 * como si se hubiera generado por primera vez.
 *
 * DÓNDE ESTÁ LA TRANSACCIÓN Y POR QUÉ AHÍ. La llamada a la IA y la validación de TODOS los widgets
 * pasan ANTES de tocar la base. Recién con la lista completa de widgets válidos en memoria se abre
 * la transacción que borra e inserta. Así, el caso "la IA devolvió basura" no llega nunca a la
 * parte destructiva: la vista se queda con los widgets viejos y el usuario ve un 422. Y si algo
 * falla igual durante el DELETE/INSERT (o se cae la conexión), el rollback deja los viejos intactos:
 * en ningún camino de error la vista queda vacía.
 *
 * `widget_versions` NO se borra a mano: su FK es
 * `(widget_id, club_id) REFERENCES widgets(id, club_id) ON DELETE CASCADE`, así que el DELETE de
 * widgets se lleva el historial. Borrarlas aparte sería redundante y, peor, daría a entender que la
 * cascada no existe.
 *
 * AISLAMIENTO POR CLUB: la vista, sus datasets y sus métricas se leen filtrando por el club de la
 * sesión, así que al prompt de la IA nunca viajan columnas ni nombres de otro club. Los INSERT
 * pasan club_id explícito y el DELETE va acotado por `view_id` + `club_id` (el patrón auditado de
 * BaseViewGenerator::upsertView()).
 *
 * SEGUNDO EJE (usuario): fetchView() aplica además el predicado de visibilidad de vistas, así que
 * una vista privada de otro usuario del mismo club tampoco se puede generar. El permiso de
 * ESCRITURA sobre una vista del club (solo admin_club) lo aplica api/generate.php, el único caller.
 *
 * La IA nunca genera HTML ni código: su única salida es este JSON de configuración, y cada widget
 * se valida contra WidgetSchema antes de persistirse.
 */
class ViewGenerator
{
    /**
     * Techo duro de widgets por generación. El prompt pide 3 a 8; esto acota el caso en que la IA
     * se entusiasme y devuelva cincuenta, que sería una vista inusable y un montón de INSERT.
     */
    private const MAX_WIDGETS = 12;

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
     * Genera (o REGENERA) el tablero completo de una vista.
     *
     * @return array{deleted:int, created:int, skipped:string[]}
     */
    public function generate(int $viewId): array
    {
        $view = $this->fetchView($viewId);

        $description = trim((string) ($view['description'] ?? ''));
        if ($description === '') {
            // Sin descripción no hay pedido: generar igual sería inventarle un tablero al PF y, peor,
            // borrarle el que ya tenía. Se corta ANTES de gastar una llamada a la IA.
            throw new RuntimeException('Esta vista no tiene descripción. Escribí qué querés ver y volvé a generar.');
        }

        $datasets = $this->fetchDatasets($viewId);
        if (empty($datasets)) {
            throw new RuntimeException('No hay datasets disponibles para esta vista. Subí datos antes de generar.');
        }

        $responseText = AnthropicClient::complete(
            $this->buildSystemPrompt(),
            $this->buildUserPrompt($view, $datasets),
            8000
        );
        // La llamada a la IA es larga (~60s): en hosting compartido la conexión ociosa se cae.
        // Reconectamos ANTES de volver a tocar la base.
        $this->pdo = Database::ping();

        $widgetSpecs = AnthropicClient::extractJson($responseText);
        if (!is_array($widgetSpecs)) {
            throw new RuntimeException('La IA no devolvió una lista de widgets.');
        }

        // Validación completa antes de escribir una sola fila: ver la nota de la cabecera.
        [$widgets, $skipped] = $this->validateSpecs($viewId, $widgetSpecs, $datasets);
        if (empty($widgets)) {
            throw new RuntimeException(
                'La IA no generó ningún widget válido, así que la vista quedó como estaba. Detalle: '
                . implode(' | ', $skipped)
            );
        }

        return $this->replaceWidgets($viewId, $widgets) + ['skipped' => $skipped];
    }

    // ---------------------------------------------------------------------
    // Persistencia
    // ---------------------------------------------------------------------

    /**
     * Borra los widgets actuales de la vista y guarda los nuevos, todo en una transacción.
     *
     * @param array<int,array{type:string,config:array}> $widgets
     * @return array{deleted:int, created:int}
     */
    private function replaceWidgets(int $viewId, array $widgets): array
    {
        $clubId = $this->clubId();

        $this->pdo->beginTransaction();
        try {
            // Mismo patrón que BaseViewGenerator::upsertView(): el `club_id` se repite aunque el
            // $viewId ya venga acotado por fetchView(). Es LA sentencia destructiva de esta clase y
            // no puede depender de que un SELECT de más arriba siga siendo correcto mañana.
            // widget_versions se va sola por la FK ON DELETE CASCADE (ver cabecera).
            $del = $this->pdo->prepare('DELETE FROM widgets WHERE view_id = ? AND club_id = ?');
            $del->execute([$viewId, $clubId]);
            $deleted = $del->rowCount();

            $wStmt = $this->pdo->prepare(
                'INSERT INTO widgets (club_id, view_id, type, config, position) VALUES (?, ?, ?, ?, ?)'
            );
            $vStmt = $this->pdo->prepare(
                'INSERT INTO widget_versions (club_id, widget_id, config, source) VALUES (?, ?, ?, "initial")'
            );

            $position = 0;
            foreach ($widgets as $w) {
                $encoded = json_encode($w['config'], JSON_UNESCAPED_UNICODE);
                $wStmt->execute([$clubId, $viewId, $w['type'], $encoded, $position++]);
                $vStmt->execute([$clubId, (int) $this->pdo->lastInsertId(), $encoded]);
            }

            $this->pdo->commit();

            return ['deleted' => $deleted, 'created' => count($widgets)];
        } catch (PDOException $e) {
            // El rollback va defendido: si lo que falló fue la conexión, `rollBack()` tira su propia
            // PDOException y taparía el error real con uno peor ("no active transaction"). InnoDB ya
            // revierte la transacción sola cuando se cae la conexión, así que los widgets viejos
            // sobreviven igual; esto solo evita que se pierda el mensaje.
            try {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } catch (PDOException $ignored) {
                // Sin nada que hacer: la transacción ya no existe.
            }

            throw new RuntimeException(
                'Error al guardar los widgets generados; la vista quedó como estaba: ' . $e->getMessage()
            );
        }
    }

    // ---------------------------------------------------------------------
    // Validación de specs
    // ---------------------------------------------------------------------

    /**
     * Valida cada spec de la IA contra WidgetSchema. No escribe nada.
     *
     * Un widget inválido no aborta la generación: se saltea con su motivo y el resto sigue. Lo que
     * sí aborta (arriba, en generate()) es que no quede ninguno válido.
     *
     * @param array<int,array{id:int,column_schema:array}> $datasets datasets permitidos para esta vista
     * @return array{0: array<int,array{type:string,config:array}>, 1: string[]} [widgets válidos, skipped]
     */
    private function validateSpecs(int $viewId, array $specs, array $datasets): array
    {
        $schemasById = [];
        foreach ($datasets as $d) {
            $schemasById[(int) $d['id']] = $d['column_schema'];
        }

        $widgets = [];
        $skipped = [];

        foreach (array_slice(array_values($specs), 0, self::MAX_WIDGETS) as $i => $spec) {
            $type = is_array($spec) ? ($spec['type'] ?? null) : null;
            $config = is_array($spec) ? ($spec['config'] ?? null) : null;
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

            // Acepta las dos formas (dataset_ids [] o el viejo dataset_id) y devuelve siempre ids int.
            $datasetIds = WidgetRenderer::datasetIds($config);
            if (empty($datasetIds)) {
                $skipped[] = "Widget #$i (\"{$config['title']}\"): no indica datasets.";
                continue;
            }
            foreach ($datasetIds as $did) {
                // $schemasById sale de fetchDatasets(), que filtra por club: un dataset ajeno (o de
                // otra vista) no está acá y el widget se descarta entero.
                if (!isset($schemasById[$did])) {
                    $skipped[] = "Widget #$i (\"{$config['title']}\"): dataset $did fuera del alcance de esta vista.";
                    continue 2;
                }
            }

            // Schema efectivo = intersección de las columnas comunes a los datasets del widget, más
            // las sintéticas (__dataset, __familia, __sub_familia, __player_nombre) que inyecta el
            // WidgetRenderer. Validar contra el column_schema crudo rechazaría widgets que el
            // renderer sí sabe dibujar.
            $effectiveSchema = WidgetSchema::effectiveSchema(
                array_map(fn (int $did): array => $schemasById[$did], $datasetIds)
            );

            $errors = WidgetSchema::validate(
                $type,
                $config,
                $effectiveSchema,
                $this->fetchCustomMetrics($viewId, $datasetIds)
            );
            if (!empty($errors)) {
                $skipped[] = "Widget #$i (\"{$config['title']}\"): " . implode(' ', $errors);
                continue;
            }

            // Normalizamos a la forma que guarda todo el resto del sistema.
            $config['dataset_ids'] = $datasetIds;
            unset($config['dataset_id']);

            $widgets[] = ['type' => $type, 'config' => $config];
        }

        return [$widgets, $skipped];
    }

    // ---------------------------------------------------------------------
    // Lecturas
    // ---------------------------------------------------------------------

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

    /**
     * Datasets que la IA puede usar en esta vista.
     *
     * Si la vista tiene datasets asignados (`view_datasets`), esos y solo esos. Si no tiene ninguno
     * —que es lo normal hoy: el modal de "nueva vista" no pide datasets, cada widget elige los
     * suyos— se le ofrecen TODOS los del club y que la IA decida cuáles cruzar, igual que hacen
     * WidgetBuilder y el overview por jugador de BaseViewGenerator. Sin este fallback, generar
     * desde la descripción fallaría siempre en el flujo real.
     *
     * @return array<int,array{id:int,nombre:string,categoria:string,column_schema:array,empty_columns:array}>
     */
    private function fetchDatasets(int $viewId): array
    {
        $clubId = $this->clubId();

        $stmt = $this->pdo->prepare(
            'SELECT d.id, d.nombre, d.categoria, d.column_schema
             FROM datasets d
             INNER JOIN view_datasets vd ON vd.dataset_id = d.id AND vd.club_id = d.club_id
             WHERE vd.view_id = :view_id AND d.club_id = :club
             ORDER BY d.categoria, d.uploaded_at'
        );
        $stmt->execute(['view_id' => $viewId, 'club' => $clubId]);
        $datasets = $stmt->fetchAll();

        if (empty($datasets)) {
            $stmt = $this->pdo->prepare(
                'SELECT id, nombre, categoria, column_schema FROM datasets
                 WHERE club_id = :club ORDER BY categoria, uploaded_at'
            );
            $stmt->execute(['club' => $clubId]);
            $datasets = $stmt->fetchAll();
        }

        foreach ($datasets as &$d) {
            $d['id'] = (int) $d['id'];
            $d['column_schema'] = json_decode($d['column_schema'], true) ?: [];
            $d['empty_columns'] = $this->emptyColumns($d['id'], $d['column_schema']);
        }
        unset($d);

        return $datasets;
    }

    /**
     * Métricas configurables de esta vista para los datasets de UN widget.
     *
     * Es lo que distingue a este generador de BaseViewGenerator: las métricas son de la vista, así
     * que acá sí existen y la IA puede referenciarlas con source "custom_metric".
     *
     * @param int[] $datasetIds
     * @return array<int,array{id:int,nombre:string}>
     */
    private function fetchCustomMetrics(int $viewId, array $datasetIds): array
    {
        if (empty($datasetIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($datasetIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, nombre FROM custom_metrics
             WHERE view_id = ? AND dataset_id IN ($placeholders) AND club_id = ?"
        );
        $stmt->execute([$viewId, ...$datasetIds, $this->clubId()]);

        return $stmt->fetchAll();
    }

    /**
     * Columnas sin datos útiles (todas vacías o todas en cero) en una muestra de filas matcheadas.
     * Misma heurística que WidgetBuilder y BaseViewGenerator: se le marcan a la IA para que no arme
     * widgets sobre columnas muertas (planillas de fuerza con meses futuros sin registrar, etc.).
     *
     * @return array<string,true>
     */
    private function emptyColumns(int $datasetId, array $columnSchema): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT raw_data FROM dataset_rows
             WHERE dataset_id = :id AND club_id = :club AND match_status = 'matched' LIMIT 300"
        );
        $stmt->execute(['id' => $datasetId, 'club' => $this->clubId()]);
        $rows = array_map(fn ($r) => json_decode($r, true) ?: [], $stmt->fetchAll(PDO::FETCH_COLUMN));
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

    // ---------------------------------------------------------------------
    // Prompts
    // ---------------------------------------------------------------------

    private function buildSystemPrompt(): string
    {
        return <<<PROMPT
Sos el motor de generación de dashboards de SportAnalysis, un producto para preparadores físicos (PF) de rugby. El PF describe en lenguaje natural qué quiere ver en una vista y vos armás el TABLERO COMPLETO.

Tu única salida es un ARRAY JSON de widgets. Nunca generás HTML, CSS, JavaScript ni código de ningún tipo — solo configuración declarativa que un renderer PHP fijo interpreta.

REGLAS GENERALES:
- Respondé ÚNICAMENTE con un array JSON válido, sin texto antes ni después, sin markdown.
- Generá entre 3 y 8 widgets. Podés proponer widgets adicionales útiles más allá de lo pedido literalmente (ej: comparar backs vs forwards si hay datos de ambas familias), con moderación.
- Nunca inventes columnas ni datasets. Usá exactamente los nombres de columna y los ids que te doy.
- Columnas marcadas "[SIN DATOS]" están vacías o todas en cero: NUNCA armes un widget sobre ellas ni agrupes por ellas.
- Solo columnas de tipo "numerica" sirven como metric/base_metric/y_metrics con agregaciones distintas de "count". Las "categorica" (incluidas las sintéticas) son las únicas válidas para group_by/segment_column.

CÓMO CRUZAR DATOS (importante):
- Cada partido o sesión se sube como su propio dataset. Para cruzar varios poné en config varios ids en "dataset_ids": [.., .., ..].
- Cuando un widget abarca varios datasets tenés disponible la columna sintética "__dataset" (categórica) = el nombre del dataset de origen de cada fila, es decir el partido/sesión. Para "metros promedio por partido": dataset_ids con todos los partidos, eje/categoría = "__dataset", aggregation = "avg".
- Al elegir varios datasets solo podés usar columnas que existan en TODOS ellos. En la práctica, agrupá en un mismo widget los datasets que comparten columnas (normalmente los de una misma categoría). NO mezcles categorías distintas en un mismo widget.
- Otras columnas sintéticas siempre disponibles: "__familia" (back/forward), "__sub_familia" (puesto), "__player_nombre". Vienen del plantel y siempre tienen datos: para el puesto usá "__sub_familia" y no columnas del CSV tipo "PUESTO"/"POSICION", que suelen venir incompletas.
- "__fecha" (tipo fecha) = fecha REAL del partido/sesión, no la de carga. Es el eje temporal correcto: usala como "x_column" para evolución en el tiempo. "__dataset" ordena por nombre del dataset, que no siempre es cronológico.
- "__split" (categórica) = tramo de la sesión ("all", "game", "1st.half", "2nd.half", bloques del entrenamiento). Están ANIDADOS y las filas se repiten por tramo; la vista ya filtra en "all". No agrupes ni filtres por "__split" salvo pedido explícito de comparar tramos.
- Para CONTAR filas ("cuántas lesiones", "cuántas sesiones") usá aggregation "count" sobre una columna que esté SIEMPRE cargada —la del nombre del jugador, o la de fecha—, nunca sobre una métrica opcional: count cuenta las filas que tienen ese valor, así que apuntarlo a una columna con huecos devuelve un total más chico que el real.
- En una tabla con "row_grain":"player", la primera columna YA es el nombre del jugador: la agrega el renderer sola. No la repitas agregando "__player_nombre" en "columns" o la tabla sale con la columna Jugador dos veces.

Los 5 tipos de widget y la forma EXACTA de su config (config SIEMPRE lleva "dataset_ids": [int, ...]):

1. "kpi_card":
{ "type":"kpi_card","title":"str","config":{ "dataset_ids":[int],
  "metric": {"source":"column","column":"<numérica>"} | {"source":"custom_metric","metric_id":int},
  "aggregation":"sum"|"avg"|"min"|"max"|"count",
  "filter": {"column":"<col>","operator":"eq"|"neq"|"gt"|"gte"|"lt"|"lte","value":<str|num>} (opcional),
  "comparison": {"enabled":true,"reference_value":num,"label":"str"} (opcional),
  "number_format": {"decimals":int,"unit":"str"},
  "scale_selector": bool } }

2. "table":
{ "type":"table","title":"str","config":{ "dataset_ids":[int],
  "columns":[ {"source":"column","column":"<col>","label":"str","aggregation":"sum"|"avg"|"min"|"max"|"count"|"text"} ],
  "row_grain":"player"|"player_session"|"<columna categórica para agrupar filas, ej __sub_familia, __dataset>",
  "conditional_rules":[ {"column":"<label de una columna de arriba>","operator":"gt"|"gte"|"lt"|"lte"|"eq"|"between","value":num|[num,num],"color":"moss"|"amber"|"clay"} ] (máx 3, opcional),
  "default_sort": {"column":"<label>","direction":"asc"|"desc"} (opcional),
  "search_enabled": bool, "scale_selector": bool } }
  (aggregation "text" = columna de dimensión: muestra el valor tal cual, sin agregar. Usala para columnas categóricas/texto como __sub_familia, __familia o __dataset.)

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

    private function buildUserPrompt(array $view, array $datasets): string
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
            $catalog[] = strtoupper((string) $categoria) . ":\n" . implode("\n", $lines);
        }

        return "Vista: \"{$view['nombre']}\"\n"
            . "Esto es lo que el PF quiere ver en esta vista:\n{$view['description']}\n\n"
            . "Datasets disponibles (agrupados por categoría; cada uno es un partido/sesión/test):\n"
            . implode("\n", $catalog)
            . "\n\nGenerá el array JSON de widgets ahora.";
    }
}
