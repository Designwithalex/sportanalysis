<?php

define('PL_APP', true);
require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/NameMatcher.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$pdo = Database::get();

if ($method === 'GET') {
    handleList($pdo);
    exit;
}

if ($method === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'set_player_column') {
        handleSetPlayerColumn($pdo);
    } elseif ($action === 'resolve') {
        handleResolve($pdo);
    } else {
        respondError(400, 'Acción desconocida.');
    }
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
exit;

function handleList(PDO $pdo): void
{
    $datasets = $pdo->query('SELECT id, nombre, column_schema, player_column_name FROM datasets ORDER BY nombre')->fetchAll();
    $players = $pdo->query('SELECT id, nombre FROM players')->fetchAll();

    $result = [];
    foreach ($datasets as $dataset) {
        $datasetId = $dataset['id'];
        $entry = [
            'dataset_id' => $datasetId,
            'nombre' => $dataset['nombre'],
            'columns' => array_keys(json_decode($dataset['column_schema'], true)),
            'player_column_name' => $dataset['player_column_name'],
            'pending' => [],
        ];

        if ($dataset['player_column_name'] === null) {
            $result[] = $entry;
            continue;
        }

        ensureReconciliations($pdo, $datasetId, $players);

        $stmt = $pdo->prepare(
            "SELECT nr.id, nr.raw_name, nr.suggested_player_id, p.nombre AS suggested_nombre
             FROM name_reconciliations nr
             LEFT JOIN players p ON p.id = nr.suggested_player_id
             WHERE nr.dataset_id = :dataset_id AND nr.resolution = 'pending'
             ORDER BY nr.raw_name"
        );
        $stmt->execute(['dataset_id' => $datasetId]);
        $entry['pending'] = $stmt->fetchAll();

        $result[] = $entry;
    }

    $players = array_map(fn($p) => ['id' => (int) $p['id'], 'nombre' => $p['nombre']], $players);

    echo json_encode(['ok' => true, 'datasets' => $result, 'players' => $players]);
}

/** Crea reconciliaciones "pending" para raw_names sin matchear que todavía no tienen una fila. */
function ensureReconciliations(PDO $pdo, int $datasetId, array $players): void
{
    $existing = $pdo->prepare('SELECT raw_name FROM name_reconciliations WHERE dataset_id = :dataset_id');
    $existing->execute(['dataset_id' => $datasetId]);
    $existingNames = array_flip($existing->fetchAll(PDO::FETCH_COLUMN));

    $unmatched = $pdo->prepare(
        "SELECT DISTINCT raw_name FROM dataset_rows
         WHERE dataset_id = :dataset_id AND match_status = 'unmatched' AND raw_name IS NOT NULL"
    );
    $unmatched->execute(['dataset_id' => $datasetId]);
    $rawNames = $unmatched->fetchAll(PDO::FETCH_COLUMN);

    $insert = $pdo->prepare(
        'INSERT INTO name_reconciliations (dataset_id, raw_name, suggested_player_id, resolution)
         VALUES (:dataset_id, :raw_name, :suggested_player_id, "pending")'
    );

    foreach ($rawNames as $rawName) {
        if (isset($existingNames[$rawName])) {
            continue;
        }
        $suggestion = NameMatcher::suggest($rawName, $players);
        $insert->execute([
            'dataset_id' => $datasetId,
            'raw_name' => $rawName,
            'suggested_player_id' => $suggestion['player_id'] ?? null,
        ]);
    }
}

function handleSetPlayerColumn(PDO $pdo): void
{
    $datasetId = (int) ($_POST['dataset_id'] ?? 0);
    $columnName = trim($_POST['column_name'] ?? '');

    if ($datasetId <= 0 || $columnName === '') {
        respondError(400, 'Faltan datos.');
    }

    $dataset = $pdo->prepare('SELECT column_schema FROM datasets WHERE id = :id');
    $dataset->execute(['id' => $datasetId]);
    $row = $dataset->fetch();
    if (!$row) {
        respondError(404, 'Dataset no encontrado.');
    }
    $columns = array_keys(json_decode($row['column_schema'], true));
    if (!in_array($columnName, $columns, true)) {
        respondError(422, 'Esa columna no existe en el dataset.');
    }

    $players = $pdo->query('SELECT id, nombre FROM players')->fetchAll();
    $nameIndex = NameMatcher::buildIndex($players);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE datasets SET player_column_name = :col WHERE id = :id')
            ->execute(['col' => $columnName, 'id' => $datasetId]);

        // Limpiamos reconciliaciones viejas: cambió la columna, los raw_name anteriores ya no aplican.
        $pdo->prepare('DELETE FROM name_reconciliations WHERE dataset_id = :id')->execute(['id' => $datasetId]);

        $rows = $pdo->prepare('SELECT id, raw_data FROM dataset_rows WHERE dataset_id = :id');
        $rows->execute(['id' => $datasetId]);

        $updateStmt = $pdo->prepare(
            'UPDATE dataset_rows SET raw_name = :raw_name, player_id = :player_id, match_status = :match_status WHERE id = :id'
        );

        foreach ($rows->fetchAll() as $row) {
            $data = json_decode($row['raw_data'], true);
            $rawName = trim($data[$columnName] ?? '');
            $playerId = $rawName !== '' ? NameMatcher::findExact($rawName, $nameIndex) : null;

            $updateStmt->execute([
                'raw_name' => $rawName !== '' ? $rawName : null,
                'player_id' => $playerId,
                'match_status' => $playerId !== null ? 'matched' : 'unmatched',
                'id' => $row['id'],
            ]);
        }

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        respondError(500, 'Error al actualizar la columna de jugador: ' . $e->getMessage());
    }

    echo json_encode(['ok' => true]);
}

function handleResolve(PDO $pdo): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $resolution = $_POST['resolution'] ?? '';
    $resolvedPlayerId = isset($_POST['resolved_player_id']) && $_POST['resolved_player_id'] !== ''
        ? (int) $_POST['resolved_player_id']
        : null;

    if ($id <= 0 || !in_array($resolution, ['confirmed', 'manual', 'discarded'], true)) {
        respondError(400, 'Datos inválidos.');
    }
    if (in_array($resolution, ['confirmed', 'manual'], true) && $resolvedPlayerId === null) {
        respondError(400, 'Falta el jugador a asignar.');
    }

    $recon = $pdo->prepare('SELECT * FROM name_reconciliations WHERE id = :id');
    $recon->execute(['id' => $id]);
    $row = $recon->fetch();
    if (!$row) {
        respondError(404, 'Reconciliación no encontrada.');
    }

    if ($resolution === 'confirmed') {
        $resolvedPlayerId = (int) $row['suggested_player_id'];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE name_reconciliations SET resolution = :resolution, resolved_player_id = :resolved_player_id, resolved_at = NOW() WHERE id = :id'
        )->execute([
            'resolution' => $resolution,
            'resolved_player_id' => $resolvedPlayerId,
            'id' => $id,
        ]);

        $matchStatus = $resolution === 'discarded' ? 'discarded' : 'matched';
        $pdo->prepare(
            'UPDATE dataset_rows SET player_id = :player_id, match_status = :match_status
             WHERE dataset_id = :dataset_id AND raw_name = :raw_name'
        )->execute([
            'player_id' => $resolution === 'discarded' ? null : $resolvedPlayerId,
            'match_status' => $matchStatus,
            'dataset_id' => $row['dataset_id'],
            'raw_name' => $row['raw_name'],
        ]);

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        respondError(500, 'Error al resolver: ' . $e->getMessage());
    }

    echo json_encode(['ok' => true]);
}

function respondError(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}
