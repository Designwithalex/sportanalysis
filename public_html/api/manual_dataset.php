<?php

define('PL_APP', true);
require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/ColumnTypeDetector.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}

$pdo = Database::get();

$payload = json_decode($_POST['payload'] ?? '', true);
if (!is_array($payload)) {
    respondError(400, 'Payload inválido.');
}

$categoriasValidas = ['partidos', 'entrenamientos', 'fuerza', 'nutricion', 'otros'];
$categoria = $payload['categoria'] ?? 'otros';
if (!in_array($categoria, $categoriasValidas, true)) {
    respondError(422, 'Categoría inválida.');
}

$nombre = trim($payload['nombre'] ?? '');
if ($nombre === '') {
    respondError(422, 'Poné un nombre para el dataset.');
}

// Columnas de métrica (más allá de "Jugador"). Se limpian y desduplican.
$columns = [];
foreach ($payload['columns'] ?? [] as $c) {
    $c = trim((string) $c);
    if ($c !== '' && strcasecmp($c, 'Jugador') !== 0 && !in_array($c, $columns, true)) {
        $columns[] = $c;
    }
}
if (empty($columns)) {
    respondError(422, 'Definí al menos una columna de datos.');
}

$rowsInput = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
if (empty($rowsInput)) {
    respondError(422, 'No hay filas para cargar.');
}

// Nombres reales del plantel (no confiamos en el nombre que venga del cliente).
$players = [];
foreach ($pdo->query('SELECT id, nombre FROM players')->fetchAll() as $p) {
    $players[(int) $p['id']] = $p['nombre'];
}

// Armamos las filas: una por jugador que tenga al menos un valor cargado.
$assembled = [];
foreach ($rowsInput as $row) {
    $playerId = (int) ($row['player_id'] ?? 0);
    if (!isset($players[$playerId])) {
        continue;
    }
    $values = is_array($row['values'] ?? null) ? $row['values'] : [];

    $hasValue = false;
    $data = ['Jugador' => $players[$playerId]];
    foreach ($columns as $col) {
        $v = trim((string) ($values[$col] ?? ''));
        $data[$col] = $v;
        if ($v !== '') {
            $hasValue = true;
        }
    }
    if (!$hasValue) {
        continue; // jugador sin ningún dato cargado: no lo guardamos
    }
    $assembled[] = ['player_id' => $playerId, 'data' => $data];
}

if (empty($assembled)) {
    respondError(422, 'Cargá al menos un valor para algún jugador.');
}

$headers = array_merge(['Jugador'], $columns);
$columnSchema = ColumnTypeDetector::detect($headers, array_map(fn($r) => $r['data'], $assembled));

$pdo->beginTransaction();
try {
    $pdo->prepare(
        'INSERT INTO datasets (nombre, categoria, original_filename, column_schema, player_column_name)
         VALUES (:nombre, :categoria, NULL, :column_schema, :player_column_name)'
    )->execute([
        'nombre' => $nombre,
        'categoria' => $categoria,
        'column_schema' => json_encode($columnSchema, JSON_UNESCAPED_UNICODE),
        'player_column_name' => 'Jugador',
    ]);
    $datasetId = (int) $pdo->lastInsertId();

    $rowStmt = $pdo->prepare(
        "INSERT INTO dataset_rows (dataset_id, player_id, raw_name, raw_data, match_status)
         VALUES (:dataset_id, :player_id, :raw_name, :raw_data, 'matched')"
    );
    foreach ($assembled as $r) {
        $rowStmt->execute([
            'dataset_id' => $datasetId,
            'player_id' => $r['player_id'],
            'raw_name' => $r['data']['Jugador'],
            'raw_data' => json_encode($r['data'], JSON_UNESCAPED_UNICODE),
        ]);
    }

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    respondError(500, 'Error al guardar la carga manual: ' . $e->getMessage());
}

echo json_encode(['ok' => true, 'dataset_id' => $datasetId, 'row_count' => count($assembled)]);
exit;

function respondError(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}
