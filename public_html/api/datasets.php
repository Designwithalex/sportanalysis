<?php

define('PL_APP', true);
require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/CsvParser.php';
require __DIR__ . '/../app/ColumnTypeDetector.php';
require __DIR__ . '/../app/NameMatcher.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$pdo = Database::get();

if ($method === 'GET') {
    $datasets = $pdo->query(
        'SELECT d.id, d.nombre, d.categoria, d.original_filename, d.column_schema, d.player_column_name, d.uploaded_at,
                COUNT(r.id) AS row_count,
                SUM(CASE WHEN r.match_status = "matched" THEN 1 ELSE 0 END) AS matched_count,
                SUM(CASE WHEN r.match_status = "unmatched" THEN 1 ELSE 0 END) AS unmatched_count
         FROM datasets d
         LEFT JOIN dataset_rows r ON r.dataset_id = d.id
         GROUP BY d.id
         ORDER BY d.uploaded_at DESC'
    )->fetchAll();

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

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
exit;

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

    $players = $pdo->query('SELECT id, nombre FROM players')->fetchAll();
    $nameIndex = NameMatcher::buildIndex($players);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO datasets (nombre, categoria, original_filename, column_schema, player_column_name)
             VALUES (:nombre, :categoria, :original_filename, :column_schema, :player_column_name)'
        );
        $stmt->execute([
            'nombre' => $nombre,
            'categoria' => $categoria,
            'original_filename' => $originalFilename,
            'column_schema' => json_encode($columnSchema, JSON_UNESCAPED_UNICODE),
            'player_column_name' => $playerColumn,
        ]);
        $datasetId = (int) $pdo->lastInsertId();

        $rowStmt = $pdo->prepare(
            'INSERT INTO dataset_rows (dataset_id, player_id, raw_name, raw_data, match_status)
             VALUES (:dataset_id, :player_id, :raw_name, :raw_data, :match_status)'
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
    $stmt = $pdo->prepare('DELETE FROM datasets WHERE id = :id');
    $stmt->execute(['id' => $id]);
    echo json_encode(['ok' => true]);
}

function respondError(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}
