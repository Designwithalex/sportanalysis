<?php

require __DIR__ . '/../app/bootstrap_api.php';
require __DIR__ . '/../app/CsvParser.php';
require __DIR__ . '/../app/ColumnTypeDetector.php';
require __DIR__ . '/../app/NameMatcher.php';
require __DIR__ . '/../app/Categorias.php';
require __DIR__ . '/../app/CategoryPermission.php';

// Guard de sesión. Va antes de session_write_close() (lee $_SESSION) y antes de tocar la base.
// Además valida el token anti-CSRF en todo método que no sea GET/HEAD.
requireAuth();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = Database::get();

requireMethod(['GET', 'POST', 'DELETE']);

if ($method === 'GET') {
    // El club_id va en el ON del LEFT JOIN además del WHERE: si solo estuviera en el WHERE, las
    // filas del join seguirían viniendo de cualquier club e inflarían los contadores.
    $listStmt = $pdo->prepare(
        'SELECT d.id, d.nombre, d.categoria, d.original_filename, d.column_schema, d.player_column_name, d.uploaded_at,
                COUNT(r.id) AS row_count,
                SUM(CASE WHEN r.match_status = "matched" THEN 1 ELSE 0 END) AS matched_count,
                SUM(CASE WHEN r.match_status = "unmatched" THEN 1 ELSE 0 END) AS unmatched_count
         FROM datasets d
         LEFT JOIN dataset_rows r ON r.dataset_id = d.id AND r.club_id = d.club_id
         WHERE d.club_id = :club
         GROUP BY d.id
         ORDER BY d.uploaded_at DESC'
    );
    $listStmt->execute(['club' => Auth::clubId()]);
    $datasets = $listStmt->fetchAll();

    foreach ($datasets as &$d) {
        $d['column_schema'] = json_decode($d['column_schema'], true);
        $d['row_count'] = (int) $d['row_count'];
        $d['matched_count'] = (int) $d['matched_count'];
        $d['unmatched_count'] = (int) $d['unmatched_count'];
    }

    echo json_encode(['ok' => true, 'datasets' => $datasets]);
    exit;
}

if ($method === 'POST') {
    // Sin `action` es una subida, que es como nació este endpoint y como lo sigue llamando
    // steps/datos.php. `action=update` es el rename / cambio de categoría.
    if (($_POST['action'] ?? '') === 'update') {
        handleUpdate($pdo);
    } else {
        handleUpload($pdo);
    }
    exit;
}

if ($method === 'DELETE') {
    handleDelete($pdo);
    exit;
}

/**
 * Resuelve la categoría que manda el cliente, o corta.
 *
 * NO degrada en silencio a 'otros'. Antes lo hacía, y desde que la categoría decide un permiso
 * eso es un bug con forma de comodidad: alguien que elige "kinesiología" sin tenerla habilitada
 * terminaría subiendo su planilla médica a "Otros datos" —donde quizá tampoco puede escribir—
 * y viendo un mensaje de éxito. Un valor que no existe es 422; que falte el campo, en cambio, es
 * un cliente viejo y ahí sí vale el default.
 */
function categoriaDeRequest(?string $raw): string
{
    $categoria = Categorias::normalizar($raw);
    if ($categoria === null) {
        respondError(422, 'Categoría inválida.');
    }

    return $categoria;
}

function handleUpload(PDO $pdo): void
{
    // La categoría y su permiso se resuelven ANTES de parsear el archivo: si el usuario no puede
    // escribir ese bucket, no tiene sentido leerle un CSV entero ni detectarle los tipos.
    $categoria = categoriaDeRequest($_POST['categoria'] ?? null);
    CategoryPermission::requireCategoria($categoria);

    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        respondError(400, 'No se recibió ningún archivo CSV.');
    }

    try {
        $parsed = CsvParser::parse($_FILES['csv']['tmp_name']);
    } catch (RuntimeException $e) {
        respondError(400, $e->getMessage());
    }

    $headers = $parsed['headers'];
    $rows = $parsed['rows'];

    if (empty($rows)) {
        respondError(422, 'El CSV no tiene filas de datos.');
    }

    $columnSchema = ColumnTypeDetector::detect($headers, $rows);
    $playerColumn = ColumnTypeDetector::guessPlayerColumn($headers);

    $originalFilename = $_FILES['csv']['name'];
    $nombre = trim($_POST['nombre'] ?? '') ?: pathinfo($originalFilename, PATHINFO_FILENAME);

    $clubId = Auth::clubId();

    // Solo el plantel de este club: si entraran jugadores de otro, el matcher les asignaría filas.
    $playersStmt = $pdo->prepare('SELECT id, nombre FROM players WHERE club_id = :club');
    $playersStmt->execute(['club' => $clubId]);
    $players = $playersStmt->fetchAll();
    $nameIndex = NameMatcher::buildIndex($players);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO datasets (club_id, nombre, categoria, original_filename, column_schema, player_column_name)
             VALUES (:club_id, :nombre, :categoria, :original_filename, :column_schema, :player_column_name)'
        );
        $stmt->execute([
            'club_id' => $clubId,
            'nombre' => $nombre,
            'categoria' => $categoria,
            'original_filename' => $originalFilename,
            'column_schema' => json_encode($columnSchema, JSON_UNESCAPED_UNICODE),
            'player_column_name' => $playerColumn,
        ]);
        $datasetId = (int) $pdo->lastInsertId();

        $rowStmt = $pdo->prepare(
            'INSERT INTO dataset_rows (club_id, dataset_id, player_id, raw_name, raw_data, match_status)
             VALUES (:club_id, :dataset_id, :player_id, :raw_name, :raw_data, :match_status)'
        );

        $insertedCount = 0;
        $unmatchedCount = 0;
        foreach ($rows as $row) {
            $rawName = $playerColumn !== null ? trim($row[$playerColumn] ?? '') : '';

            // Si hay columna de jugador identificada y esta fila no trae nombre, es una fila en blanco
            // o de relleno (común en planillas de fuerza): no es un registro, la ignoramos.
            if ($playerColumn !== null && $rawName === '') {
                continue;
            }

            $playerId = $rawName !== '' ? NameMatcher::findExact($rawName, $nameIndex) : null;
            if ($playerId === null) {
                $unmatchedCount++;
            }

            $rowStmt->execute([
                'club_id' => $clubId,
                'dataset_id' => $datasetId,
                'player_id' => $playerId,
                'raw_name' => $rawName !== '' ? $rawName : null,
                'raw_data' => json_encode($row, JSON_UNESCAPED_UNICODE),
                'match_status' => $playerId !== null ? 'matched' : 'unmatched',
            ]);
            $insertedCount++;
        }

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        respondError(500, 'Error al guardar el dataset: ' . $e->getMessage());
    }

    echo json_encode([
        'ok' => true,
        'dataset_id' => $datasetId,
        'nombre' => $nombre,
        'categoria' => $categoria,
        'row_count' => $insertedCount,
        'unmatched_count' => $unmatchedCount,
        'player_column_name' => $playerColumn,
        'column_schema' => $columnSchema,
    ]);
}

/**
 * Renombra un dataset y/o lo mueve de categoría.
 *
 * DOS PERMISOS, NO UNO. Mover un dataset de `fuerza` a `kinesiologia` es sacarlo de un bucket y
 * meterlo en otro: hace falta poder escribir los dos. Con solo el destino, el kinesiólogo podría
 * vaciarle la categoría al PF; con solo el origen, podría meter cualquier cosa adentro de
 * nutrición. Se piden los dos aunque en el caso normal (renombrar sin mover) sean el mismo.
 */
function handleUpdate(PDO $pdo): void
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        respondError(400, 'Falta el id del dataset.');
    }

    // El id viene del cliente: 404 si el dataset es de otro club. Y la fila trae la categoría de
    // ORIGEN, que no puede venir de la request: el cliente podría declarar una que sí puede
    // escribir para sacar un dataset de una que no.
    $dataset = Scope::require($pdo, 'datasets', $id);
    $origen  = (string) $dataset['categoria'];

    CategoryPermission::requireCategoria($origen);

    // Un `categoria` vacío es "no lo mando", no "mové esto a Otros datos": categoriaDeRequest()
    // devuelve el default cuando falta el campo, y acá ese default sería un movimiento silencioso.
    $destino = $origen;
    if (trim((string) ($_POST['categoria'] ?? '')) !== '') {
        $destino = categoriaDeRequest((string) $_POST['categoria']);
        if ($destino !== $origen) {
            CategoryPermission::requireCategoria($destino);
        }
    }

    $nombre = $dataset['nombre'];
    if (isset($_POST['nombre'])) {
        $nombre = trim((string) $_POST['nombre']);
        if ($nombre === '') {
            respondError(422, 'El nombre del dataset no puede quedar vacío.');
        }
        // `datasets.nombre` es VARCHAR(150): recortar acá evita que MySQL trunque en silencio.
        $nombre = mb_substr($nombre, 0, 150);
    }

    $stmt = $pdo->prepare(
        'UPDATE datasets SET nombre = :nombre, categoria = :categoria WHERE id = :id AND club_id = :club'
    );
    $stmt->execute([
        'nombre'    => $nombre,
        'categoria' => $destino,
        'id'        => $id,
        'club'      => Auth::clubId(),
    ]);

    echo json_encode(['ok' => true, 'id' => $id, 'nombre' => $nombre, 'categoria' => $destino]);
}

function handleDelete(PDO $pdo): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        respondError(400, 'Falta el id del dataset a eliminar.');
    }
    // El id viene del cliente: 404 si el dataset es de otro club.
    $dataset = Scope::require($pdo, 'datasets', $id);

    // Borrar es la escritura más destructiva que hay sobre una categoría (arrastra dataset_rows,
    // name_reconciliations y view_datasets por cascada), así que pide el mismo permiso que subir.
    // La categoría sale de la FILA, no de la request.
    CategoryPermission::requireCategoria((string) $dataset['categoria']);

    $stmt = $pdo->prepare('DELETE FROM datasets WHERE id = :id AND club_id = :club');
    $stmt->execute(['id' => $id, 'club' => Auth::clubId()]);
    echo json_encode(['ok' => true]);
}
