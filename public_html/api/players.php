<?php

define('PL_APP', true);
require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/CsvParser.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$pdo = Database::get();

if ($method === 'GET') {
    $players = $pdo->query('SELECT id, nombre, familia, sub_familia, metadata FROM players ORDER BY nombre')->fetchAll();
    foreach ($players as &$p) {
        $p['metadata'] = $p['metadata'] ? json_decode($p['metadata'], true) : null;
    }
    echo json_encode(['ok' => true, 'players' => $players]);
    exit;
}

if ($method === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        handlePlayerSave($pdo);        // alta/edición de un jugador individual (sin CSV)
    } else {
        handleUpload($pdo);            // carga masiva por CSV (reemplaza el plantel)
    }
    exit;
}

if ($method === 'DELETE') {
    handlePlayerDelete($pdo);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
exit;

function handlePlayerSave(PDO $pdo): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    $familia = strtolower(trim($_POST['familia'] ?? ''));
    $subFamilia = trim($_POST['sub_familia'] ?? '');

    if ($nombre === '') {
        respondError(422, 'El nombre no puede estar vacío.');
    }
    if (!in_array($familia, ['back', 'forward'], true)) {
        respondError(422, 'La familia debe ser "back" o "forward".');
    }

    try {
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE players SET nombre = :nombre, familia = :familia, sub_familia = :sub_familia WHERE id = :id');
            $stmt->execute(['nombre' => $nombre, 'familia' => $familia, 'sub_familia' => $subFamilia !== '' ? $subFamilia : null, 'id' => $id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO players (nombre, familia, sub_familia) VALUES (:nombre, :familia, :sub_familia)');
            $stmt->execute(['nombre' => $nombre, 'familia' => $familia, 'sub_familia' => $subFamilia !== '' ? $subFamilia : null]);
            $id = (int) $pdo->lastInsertId();
        }
    } catch (PDOException $e) {
        respondError(500, 'Error al guardar el jugador: ' . $e->getMessage());
    }

    echo json_encode(['ok' => true, 'id' => $id]);
}

function handlePlayerDelete(PDO $pdo): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        respondError(400, 'Falta el id del jugador.');
    }
    $pdo->prepare('DELETE FROM players WHERE id = :id')->execute(['id' => $id]);
    echo json_encode(['ok' => true]);
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

    $nombreCol = findColumn($headers, ['nombre']);
    $familiaCol = findColumn($headers, ['familia']);
    $subFamiliaCol = findColumn($headers, ['sub_familia', 'sub-familia', 'subfamilia']);

    $missing = [];
    if ($nombreCol === null) $missing[] = 'Nombre';
    if ($familiaCol === null) $missing[] = 'Familia';
    if ($subFamiliaCol === null) $missing[] = 'Sub-familia';
    if (!empty($missing)) {
        respondError(422, 'Faltan columnas obligatorias en el CSV: ' . implode(', ', $missing) . '.');
    }

    $extraCols = array_values(array_diff($headers, [$nombreCol, $familiaCol, $subFamiliaCol]));

    $invalidRows = [];
    $prepared = [];
    foreach ($rows as $i => $row) {
        $rowNum = $i + 2; // +1 header, +1 to be 1-indexed
        $nombre = trim($row[$nombreCol]);
        $familia = strtolower(trim($row[$familiaCol]));
        $subFamilia = trim($row[$subFamiliaCol]);

        if ($nombre === '') {
            continue; // fila vacía, se ignora
        }
        if (!in_array($familia, ['back', 'forward'], true)) {
            $invalidRows[] = "Fila $rowNum ($nombre): familia \"$familia\" inválida, debe ser \"back\" o \"forward\".";
            continue;
        }

        $metadata = [];
        foreach ($extraCols as $col) {
            if (isset($row[$col]) && $row[$col] !== '') {
                $metadata[$col] = $row[$col];
            }
        }

        $prepared[] = [
            'nombre' => $nombre,
            'familia' => $familia,
            'sub_familia' => $subFamilia !== '' ? $subFamilia : null,
            'metadata' => empty($metadata) ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE),
        ];
    }

    if (!empty($invalidRows)) {
        respondError(422, "Se encontraron filas inválidas:\n" . implode("\n", $invalidRows));
    }

    if (empty($prepared)) {
        respondError(422, 'El CSV no tiene filas válidas para cargar.');
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM players');
        $stmt = $pdo->prepare(
            'INSERT INTO players (nombre, familia, sub_familia, metadata) VALUES (:nombre, :familia, :sub_familia, :metadata)'
        );
        foreach ($prepared as $p) {
            $stmt->execute($p);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        respondError(500, 'Error al guardar el plantel: ' . $e->getMessage());
    }

    echo json_encode(['ok' => true, 'count' => count($prepared)]);
}

/** @param string[] $haystackHeaders @param string[] $candidates */
function findColumn(array $haystackHeaders, array $candidates): ?string
{
    foreach ($haystackHeaders as $header) {
        $normalized = normalizeHeader($header);
        foreach ($candidates as $candidate) {
            if ($normalized === normalizeHeader($candidate)) {
                return $header;
            }
        }
    }
    return null;
}

function normalizeHeader(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
    return preg_replace('/[\s_-]+/', '', $value);
}

function respondError(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}
