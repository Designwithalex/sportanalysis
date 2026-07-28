<?php

require __DIR__ . '/../app/bootstrap_api.php';
require __DIR__ . '/../app/CsvParser.php';

// Guard de sesión. Va antes de session_write_close() (lee $_SESSION) y antes de tocar la base.
// Además valida el token anti-CSRF en todo método que no sea GET/HEAD.
requireAuth();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = Database::get();

requireMethod(['GET', 'POST', 'DELETE']);

if ($method === 'GET') {
    $stmt = $pdo->prepare('SELECT id, nombre, familia, sub_familia, metadata FROM players WHERE club_id = :club ORDER BY nombre');
    $stmt->execute(['club' => Auth::clubId()]);
    $players = $stmt->fetchAll();
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
            // El id viene del cliente: 404 si no es de este club, antes de tocar nada.
            Scope::require($pdo, 'players', $id);
            $stmt = $pdo->prepare('UPDATE players SET nombre = :nombre, familia = :familia, sub_familia = :sub_familia WHERE id = :id AND club_id = :club');
            $stmt->execute(['nombre' => $nombre, 'familia' => $familia, 'sub_familia' => $subFamilia !== '' ? $subFamilia : null, 'id' => $id, 'club' => Auth::clubId()]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO players (club_id, nombre, familia, sub_familia) VALUES (:club, :nombre, :familia, :sub_familia)');
            $stmt->execute(['club' => Auth::clubId(), 'nombre' => $nombre, 'familia' => $familia, 'sub_familia' => $subFamilia !== '' ? $subFamilia : null]);
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
    // El id viene del cliente: 404 si es de otro club (el WHERE de abajo ya lo cubriría en
    // silencio, pero acá queremos el 404 explícito en vez de un "ok" que no borró nada).
    Scope::require($pdo, 'players', $id);
    $pdo->prepare('DELETE FROM players WHERE id = :id AND club_id = :club')
        ->execute(['id' => $id, 'club' => Auth::clubId()]);
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

    $clubId = Auth::clubId();

    // Cuántas vistas de jugador se van a perder por el CASCADE de abajo, para avisarlo en la
    // respuesta. Se cuenta ANTES del DELETE porque después ya no existen.
    $cascadeStmt = $pdo->prepare("SELECT COUNT(*) FROM views WHERE club_id = :club AND tipo = 'player'");
    $cascadeStmt->execute(['club' => $clubId]);
    $viewsBorradas = (int) $cascadeStmt->fetchColumn();

    $pdo->beginTransaction();
    try {
        // El WHERE club_id NO ES OPCIONAL: sin él este DELETE borra el plantel de TODOS los clubes.
        // Y arrastra más de lo que parece: views.player_id tiene ON DELETE CASCADE, así que se van
        // también todas las vistas tipo 'player' del club (con sus widgets). Es el comportamiento
        // esperado —reemplazar el plantel—, pero acotado a un club.
        $pdo->prepare('DELETE FROM players WHERE club_id = :club')->execute(['club' => $clubId]);
        $stmt = $pdo->prepare(
            'INSERT INTO players (club_id, nombre, familia, sub_familia, metadata) VALUES (:club_id, :nombre, :familia, :sub_familia, :metadata)'
        );
        foreach ($prepared as $p) {
            $stmt->execute($p + ['club_id' => $clubId]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        respondError(500, 'Error al guardar el plantel: ' . $e->getMessage());
    }

    echo json_encode([
        'ok' => true,
        'count' => count($prepared),
        'views_borradas' => $viewsBorradas,
        'warning' => $viewsBorradas > 0
            ? "Reemplazar el plantel eliminó $viewsBorradas vista(s) individual(es) de jugador (\"Overview — Jugador\") con sus widgets. Volvé a generarlas desde Vistas base."
            : null,
    ]);
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
