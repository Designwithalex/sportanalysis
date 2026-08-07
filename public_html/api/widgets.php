<?php

require __DIR__ . '/../app/bootstrap_api.php';
require __DIR__ . '/../app/ViewPermission.php';
require __DIR__ . '/../app/WidgetSchema.php';
require __DIR__ . '/../app/WidgetRenderer.php';

/**
 * Dimensiones que admite la barra de filtro rápido del dashboard.
 *
 * Es un subconjunto cerrado: lo que llega acá se compara contra las columnas sintéticas que el
 * renderer inyecta en toda fila, así que un nombre fuera de esta lista no filtraría nada — o peor,
 * filtraría contra una columna propia de un dataset y devolvería resultados distintos según qué
 * dataset use cada widget.
 *
 * Va ACÁ ARRIBA y no al lado de adHocFilters(), que sería su lugar natural: las funciones de este
 * archivo se hoistean, las constantes NO. El dispatch de abajo llama a handleList() y termina con
 * exit, así que un `const` declarado más abajo nunca llega a ejecutarse y la constante queda sin
 * definir justo en la request que la necesita.
 */
const FILTROS_RAPIDOS = ['__familia', '__sub_familia', '__player_nombre', '__dataset', '__split'];

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

function handleList(PDO $pdo): void
{
    $viewId = (int) ($_GET['view_id'] ?? 0);
    if ($viewId <= 0) {
        respondError(400, 'Falta view_id.');
    }
    // view_id viene del cliente: 404 si la vista no es de este club.
    Scope::require($pdo, 'views', $viewId);

    $stmt = $pdo->prepare('SELECT id, type, config, position FROM widgets WHERE view_id = :view_id AND club_id = :club ORDER BY position, id');
    $stmt->execute(['view_id' => $viewId, 'club' => Auth::clubId()]);
    $widgets = $stmt->fetchAll();

    $globalFilters = mergeFilters(loadActiveFilters($pdo, $viewId), adHocFilters());

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
        $vStmt = $pdo->prepare("SELECT widget_id, COUNT(*) AS c FROM widget_versions WHERE widget_id IN ($in) AND club_id = ? GROUP BY widget_id");
        $vStmt->execute([...$ids, Auth::clubId()]);
        foreach ($vStmt->fetchAll() as $row) {
            $versionCounts[$row['widget_id']] = (int) $row['c'];
        }
    }
    foreach ($out as &$w) {
        $w['version_count'] = $versionCounts[$w['id']] ?? 1;
    }

    echo json_encode(['ok' => true, 'widgets' => $out]);
}

/**
 * Filtros que manda la barra del dashboard, en `?q[__familia]=forward&q[__split]=1st.half`.
 *
 * NO SE PERSISTEN, Y ESA ES LA DECISIÓN. Los `view_filters` son configuración de la vista: viven
 * en la base, los ve todo el club cuando la vista es del club, y por eso escribirlos está gateado
 * a un admin. Pero mirar los forwards y después los backs no es reconfigurar el tablero de todos:
 * es una lente de UN usuario durante diez segundos. Guardarla obligaría a pedir permiso de admin
 * para filtrar, y le cambiaría el dashboard al resto del club sin que se enteren.
 *
 * Al ser solo lectura no hace falta permiso de escritura ni token CSRF: es un GET que recorta lo
 * que ya se podía ver.
 *
 * @return array<int,array{column:string,operator:string,value:mixed}>
 */
function adHocFilters(): array
{
    $crudos = $_GET['q'] ?? null;
    if (!is_array($crudos)) {
        return [];
    }

    $out = [];
    foreach ($crudos as $columna => $valor) {
        // Un valor vacío es "sin filtro en esta dimensión", que es como la barra manda el estado
        // "Todos". No es un filtro por string vacío.
        if (!is_string($valor) || trim($valor) === '') {
            continue;
        }
        $valor = trim($valor);

        // `periodo` no es una columna sino un atajo: se traduce a un piso sobre __fecha, la fecha
        // REAL de la sesión. El corte se calcula en el servidor y no en el navegador porque la
        // fecha del cliente puede estar en cualquier lado, y "últimos 7 días" tiene que significar
        // lo mismo para todos los del club.
        if ($columna === 'periodo') {
            if ($desde = fechaDeCorte($valor)) {
                $out[] = ['column' => '__fecha', 'operator' => 'gte', 'value' => $desde];
            }
            continue;
        }

        if (!in_array($columna, FILTROS_RAPIDOS, true)) {
            continue;
        }
        $out[] = ['column' => $columna, 'operator' => 'eq', 'value' => $valor];
    }

    return $out;
}

/**
 * Fecha desde la que cuenta un período, o null si el período no se reconoce (= sin filtro).
 *
 * Los períodos son relativos a HOY, no a la última sesión cargada: "últimos 7 días" tiene que
 * poder dar vacío. Si contara desde la última sesión, un plantel que no entrena hace un mes
 * mostraría igual su última semana de actividad y parecería que entrenó.
 */
function fechaDeCorte(string $periodo): ?string
{
    return match ($periodo) {
        '7d'  => date('Y-m-d', strtotime('-7 days')),
        '14d' => date('Y-m-d', strtotime('-14 days')),
        '30d' => date('Y-m-d', strtotime('-30 days')),
        'mes' => date('Y-m-01'),
        default => null,
    };
}

/**
 * Combina los filtros guardados de la vista con los de la barra. A misma columna gana el de la
 * barra: son excluyentes, y sumarlos daría `__split = all AND __split = 1st.half`, que no matchea
 * ninguna fila y le mostraría al usuario un tablero vacío en vez del primer tiempo que pidió.
 *
 * @param array<int,array{column:string,operator:string,value:mixed}> $guardados
 * @param array<int,array{column:string,operator:string,value:mixed}> $rapidos
 * @return array<int,array{column:string,operator:string,value:mixed}>
 */
function mergeFilters(array $guardados, array $rapidos): array
{
    $pisadas = array_column($rapidos, 'column');

    $out = array_values(array_filter(
        $guardados,
        fn (array $f) => !in_array($f['column'], $pisadas, true)
    ));

    return array_merge($out, $rapidos);
}

/** @return array<int,array{column:string,operator:string,value:mixed}> filtros globales de la vista */
function loadActiveFilters(PDO $pdo, int $viewId): array
{
    $stmt = $pdo->prepare('SELECT column_name, config FROM view_filters WHERE view_id = :view_id AND club_id = :club');
    $stmt->execute(['view_id' => $viewId, 'club' => Auth::clubId()]);
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

    // IDOR canónico: sin esto, un POST con el view_id de otro club insertaba un widget colgado de
    // una vista ajena. La validación va ANTES del INSERT, no en el WHERE (un INSERT no tiene WHERE).
    // Suma el permiso de escritura: agregar un widget a una vista del club se lo agrega a todos.
    requireEditarVistaId($pdo, $viewId);
    if ($widgetId > 0) {
        $widget = Scope::require($pdo, 'widgets', $widgetId);
        // El widget puede colgar de una vista DISTINTA de la que vino en el POST (el UPDATE no usa
        // view_id): la que manda para el permiso es la suya.
        if ((int) $widget['view_id'] !== $viewId) {
            requireEditarVistaId($pdo, (int) $widget['view_id']);
        }
    }

    $datasetIds = WidgetRenderer::datasetIds($config);
    if (empty($datasetIds)) {
        respondError(422, 'El widget no indica ningún dataset.');
    }
    // config.dataset_ids es JSON libre: ninguna FK lo cubre. Si no se valida acá, un widget puede
    // quedar apuntando a datasets de otro club y el renderer se los muestra.
    Scope::requireAll($pdo, 'datasets', $datasetIds);
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
            $pdo->prepare('UPDATE widgets SET type = :type, config = :config WHERE id = :id AND club_id = :club')
                ->execute(['type' => $type, 'config' => json_encode($config, JSON_UNESCAPED_UNICODE), 'id' => $widgetId, 'club' => Auth::clubId()]);
        } else {
            $posStmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM widgets WHERE view_id = :view_id AND club_id = :club');
            $posStmt->execute(['view_id' => $viewId, 'club' => Auth::clubId()]);
            $position = (int) $posStmt->fetchColumn();

            $pdo->prepare('INSERT INTO widgets (club_id, view_id, type, config, position) VALUES (:club, :view_id, :type, :config, :position)')
                ->execute(['club' => Auth::clubId(), 'view_id' => $viewId, 'type' => $type, 'config' => json_encode($config, JSON_UNESCAPED_UNICODE), 'position' => $position]);
            $widgetId = (int) $pdo->lastInsertId();
        }

        $pdo->prepare('INSERT INTO widget_versions (club_id, widget_id, config, source) VALUES (:club, :widget_id, :config, "manual")')
            ->execute(['club' => Auth::clubId(), 'widget_id' => $widgetId, 'config' => json_encode($config, JSON_UNESCAPED_UNICODE)]);

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
    // El id viene del cliente. Scope::require corta con 404 si el widget es de otro club: si no,
    // el SELECT lo leería y el INSERT clonaría un widget ajeno dentro de la vista ajena.
    $widget = Scope::require($pdo, 'widgets', $widgetId);
    // El clon entra en la MISMA vista que el original: si esa vista es del club, solo un admin.
    requireEditarVistaId($pdo, (int) $widget['view_id']);

    $posStmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM widgets WHERE view_id = :view_id AND club_id = :club');
    $posStmt->execute(['view_id' => $widget['view_id'], 'club' => Auth::clubId()]);
    $position = (int) $posStmt->fetchColumn();

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO widgets (club_id, view_id, type, config, position) VALUES (:club, :view_id, :type, :config, :position)')
            ->execute(['club' => Auth::clubId(), 'view_id' => $widget['view_id'], 'type' => $widget['type'], 'config' => $widget['config'], 'position' => $position]);
        $newId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO widget_versions (club_id, widget_id, config, source) VALUES (:club, :widget_id, :config, "manual")')
            ->execute(['club' => Auth::clubId(), 'widget_id' => $newId, 'config' => $widget['config']]);
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
    // Dos saltos hasta la raíz (widget_versions → widgets → views): validamos el widget, y las
    // versiones se filtran por club en el propio WHERE.
    $widget = Scope::require($pdo, 'widgets', $widgetId);
    // Deshacer es una escritura sobre el widget: mismo permiso que editarlo.
    requireEditarVistaId($pdo, (int) $widget['view_id']);

    $versions = $pdo->prepare('SELECT id, config FROM widget_versions WHERE widget_id = :widget_id AND club_id = :club ORDER BY id DESC LIMIT 2');
    $versions->execute(['widget_id' => $widgetId, 'club' => Auth::clubId()]);
    $rows = $versions->fetchAll();

    if (count($rows) < 2) {
        respondError(400, 'No hay versiones anteriores para deshacer.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM widget_versions WHERE id = :id AND club_id = :club')
            ->execute(['id' => $rows[0]['id'], 'club' => Auth::clubId()]);
        $pdo->prepare('UPDATE widgets SET config = :config WHERE id = :id AND club_id = :club')
            ->execute(['config' => $rows[1]['config'], 'id' => $widgetId, 'club' => Auth::clubId()]);
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
    if (!is_array($ids)) {
        respondError(400, 'Faltan los ids.');
    }
    // Aceptaba cualquier lista de ids: se podía reordenar (y por lo tanto detectar la existencia de)
    // widgets de otro club. requireAll falla en bloque si uno solo es ajeno.
    $ids = Scope::requireAll($pdo, 'widgets', $ids);
    // A diferencia del reorder de TABS (que es una preferencia por usuario en view_order),
    // `widgets.position` es una sola columna compartida: reacomodar la grilla de una vista del
    // club se la reacomoda a todo el club. Solo un admin.
    requireEditarVistasDeWidgets($pdo, $ids);

    $stmt = $pdo->prepare('UPDATE widgets SET position = :position WHERE id = :id AND club_id = :club');
    $pdo->beginTransaction();
    foreach ($ids as $i => $id) {
        $stmt->execute(['position' => $i, 'id' => (int) $id, 'club' => Auth::clubId()]);
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
    // El id viene del cliente: 404 si el widget es de otro club.
    $widget = Scope::require($pdo, 'widgets', $id);
    // Borrar un widget de una vista del club se lo borra a todos los miembros.
    requireEditarVistaId($pdo, (int) $widget['view_id']);
    $pdo->prepare('DELETE FROM widgets WHERE id = :id AND club_id = :club')
        ->execute(['id' => $id, 'club' => Auth::clubId()]);
    echo json_encode(['ok' => true]);
}

/**
 * Permiso de escritura sobre TODAS las vistas que tocan estos widgets, en una sola query en vez de
 * una por widget.
 *
 * Los ids ya vienen de Scope::requireAll(), así que la visibilidad está resuelta: acá solo falta
 * el gate de "vista del club → solo admin". Igual el JOIN compara club_id en el ON (regla de
 * CLAUDE.md) y el WHERE repite el club: esto decide un permiso, no vale apoyarse en el llamador.
 *
 * @param int[] $widgetIds
 */
function requireEditarVistasDeWidgets(PDO $pdo, array $widgetIds): void
{
    if ($widgetIds === []) {
        return;
    }
    $in = implode(',', array_fill(0, count($widgetIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT DISTINCT v.id, v.user_id FROM widgets w
         INNER JOIN views v ON v.id = w.view_id AND v.club_id = w.club_id
         WHERE w.id IN ($in) AND w.club_id = ?"
    );
    $stmt->execute([...$widgetIds, Auth::clubId()]);
    foreach ($stmt->fetchAll() as $view) {
        requireEditarVista($view);
    }
}

function pruneVersions(PDO $pdo, int $widgetId, int $keep = 10): void
{
    $stmt = $pdo->prepare('SELECT id FROM widget_versions WHERE widget_id = :widget_id AND club_id = :club ORDER BY id DESC LIMIT 1000 OFFSET :offset');
    $stmt->bindValue('widget_id', $widgetId, PDO::PARAM_INT);
    $stmt->bindValue('club', Auth::clubId(), PDO::PARAM_INT);
    $stmt->bindValue('offset', $keep, PDO::PARAM_INT);
    $stmt->execute();
    $toDelete = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($toDelete)) {
        $in = implode(',', array_map('intval', $toDelete));
        // Los ids salen del SELECT de arriba (ya filtrado por club), pero el club_id se repite acá
        // igual: si alguien cambia ese SELECT, este DELETE no se convierte en un borrado global.
        $pdo->prepare("DELETE FROM widget_versions WHERE id IN ($in) AND club_id = :club")
            ->execute(['club' => Auth::clubId()]);
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
    $stmt = $pdo->prepare("SELECT id, column_schema FROM datasets WHERE id IN ($placeholders) AND club_id = ?");
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
