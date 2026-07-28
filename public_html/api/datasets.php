<?php

require __DIR__ . '/../app/bootstrap_api.php';
require __DIR__ . '/../app/CsvParser.php';
require __DIR__ . '/../app/ColumnTypeDetector.php';
require __DIR__ . '/../app/NameMatcher.php';

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
    handleUpload($pdo);
    exit;
}

if ($method === 'DELETE') {
    handleDelete($pdo);
    exit;
}

function handleUpload(PDO $pdo): void
{
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

    $categoriasValidas = ['partidos', 'entrenamientos', 'fuerza', 'nutricion', 'otros'];
    $categoria = $_POST['categoria'] ?? 'otros';
    if (!in_array($categoria, $categoriasValidas, true)) {
        $categoria = 'otros';
    }

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

function handleDelete(PDO $pdo): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        respondError(400, 'Falta el id del dataset a eliminar.');
    }
    // El id viene del cliente: 404 si el dataset es de otro club.
    Scope::require($pdo, 'datasets', $id);
    $stmt = $pdo->prepare('DELETE FROM datasets WHERE id = :id AND club_id = :club');
    $stmt->execute(['id' => $id, 'club' => Auth::clubId()]);
    echo json_encode(['ok' => true]);
}
