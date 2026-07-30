<?php

require __DIR__ . '/../app/bootstrap_api.php';
require __DIR__ . '/../app/NameMatcher.php';
require_once __DIR__ . '/../app/CategoryPermission.php';

// Guard de sesión. Va antes de session_write_close() (lee $_SESSION) y antes de tocar la base.
// Además valida el token anti-CSRF en todo método que no sea GET/HEAD.
requireAuth();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = Database::get();

requireMethod(['GET', 'POST']);

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

function handleList(PDO $pdo): void
{
    $dStmt = $pdo->prepare('SELECT id, nombre, column_schema, player_column_name FROM datasets WHERE club_id = :club ORDER BY nombre');
    $dStmt->execute(['club' => Auth::clubId()]);
    $datasets = $dStmt->fetchAll();

    $pStmt = $pdo->prepare('SELECT id, nombre FROM players WHERE club_id = :club');
    $pStmt->execute(['club' => Auth::clubId()]);
    $players = $pStmt->fetchAll();

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

        // El club_id va también en el ON del LEFT JOIN: sin eso, un suggested_player_id apuntando a
        // otro club traería el nombre de un jugador ajeno al listado.
        $stmt = $pdo->prepare(
            "SELECT nr.id, nr.raw_name, nr.suggested_player_id, p.nombre AS suggested_nombre
             FROM name_reconciliations nr
             LEFT JOIN players p ON p.id = nr.suggested_player_id AND p.club_id = nr.club_id
             WHERE nr.dataset_id = :dataset_id AND nr.club_id = :club AND nr.resolution = 'pending'
             ORDER BY nr.raw_name"
        );
        $stmt->execute(['dataset_id' => $datasetId, 'club' => Auth::clubId()]);
        $entry['pending'] = $stmt->fetchAll();

        $result[] = $entry;
    }

    $players = array_map(fn($p) => ['id' => (int) $p['id'], 'nombre' => $p['nombre']], $players);

    echo json_encode(['ok' => true, 'datasets' => $result, 'players' => $players]);
}

/** Crea reconciliaciones "pending" para raw_names sin matchear que todavía no tienen una fila. */
function ensureReconciliations(PDO $pdo, int $datasetId, array $players): void
{
    $clubId = Auth::clubId();

    $existing = $pdo->prepare('SELECT raw_name FROM name_reconciliations WHERE dataset_id = :dataset_id AND club_id = :club');
    $existing->execute(['dataset_id' => $datasetId, 'club' => $clubId]);
    $existingNames = array_flip($existing->fetchAll(PDO::FETCH_COLUMN));

    $unmatched = $pdo->prepare(
        "SELECT DISTINCT raw_name FROM dataset_rows
         WHERE dataset_id = :dataset_id AND club_id = :club AND match_status = 'unmatched' AND raw_name IS NOT NULL"
    );
    $unmatched->execute(['dataset_id' => $datasetId, 'club' => $clubId]);
    $rawNames = $unmatched->fetchAll(PDO::FETCH_COLUMN);

    $insert = $pdo->prepare(
        'INSERT INTO name_reconciliations (club_id, dataset_id, raw_name, suggested_player_id, resolution)
         VALUES (:club, :dataset_id, :raw_name, :suggested_player_id, "pending")'
    );

    foreach ($rawNames as $rawName) {
        if (isset($existingNames[$rawName])) {
            continue;
        }
        // $players ya viene filtrado por club desde handleList: la sugerencia no puede caer en otro club.
        $suggestion = NameMatcher::suggest($rawName, $players);
        $insert->execute([
            'club' => $clubId,
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

    // dataset_id viene del cliente: 404 si el dataset es de otro club (antes se leía sin filtrar).
    $row = Scope::require($pdo, 'datasets', $datasetId);

    // Reconciliar es ESCRITURA sobre los datos de una categoría: cambia el player_column_name del
    // dataset y reasigna las filas. Le corresponde la misma habilitación que subir el CSV, si no
    // el nutricionista podía re-mapear los nombres del dataset de GPS sin poder subirlo.
    CategoryPermission::requireCategoria($row['categoria']);

    $columns = array_keys(json_decode($row['column_schema'], true));
    if (!in_array($columnName, $columns, true)) {
        respondError(422, 'Esa columna no existe en el dataset.');
    }

    $clubId = Auth::clubId();

    $pStmt = $pdo->prepare('SELECT id, nombre FROM players WHERE club_id = :club');
    $pStmt->execute(['club' => $clubId]);
    $players = $pStmt->fetchAll();
    $nameIndex = NameMatcher::buildIndex($players);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE datasets SET player_column_name = :col WHERE id = :id AND club_id = :club')
            ->execute(['col' => $columnName, 'id' => $datasetId, 'club' => $clubId]);

        // Limpiamos reconciliaciones viejas: cambió la columna, los raw_name anteriores ya no aplican.
        $pdo->prepare('DELETE FROM name_reconciliations WHERE dataset_id = :id AND club_id = :club')
            ->execute(['id' => $datasetId, 'club' => $clubId]);

        $rows = $pdo->prepare('SELECT id, raw_data FROM dataset_rows WHERE dataset_id = :id AND club_id = :club');
        $rows->execute(['id' => $datasetId, 'club' => $clubId]);

        $updateStmt = $pdo->prepare(
            'UPDATE dataset_rows SET raw_name = :raw_name, player_id = :player_id, match_status = :match_status WHERE id = :id AND club_id = :club'
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
                'club' => $clubId,
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

    // El id de la reconciliación viene del cliente (cadena name_reconciliations → datasets): 404 si
    // no es de este club, antes de que su dataset_id viaje al UPDATE de dataset_rows de abajo.
    $row = Scope::require($pdo, 'name_reconciliations', $id);

    // Y la habilitación de la categoría del dataset al que pertenece: resolver o descartar una fila
    // reescribe a qué jugador se atribuye ese dato. Se lee la categoría del dataset padre, no de la
    // request, para que no se pueda pedir contra una categoría que sí se tiene habilitada.
    $catStmt = $pdo->prepare('SELECT categoria FROM datasets WHERE id = :id AND club_id = :club');
    $catStmt->execute(['id' => (int) $row['dataset_id'], 'club' => Auth::clubId()]);
    $categoria = (string) $catStmt->fetchColumn();
    if ($categoria === '') {
        respondError(404, 'Dataset no encontrado.');
    }
    CategoryPermission::requireCategoria($categoria);

    if ($resolution === 'confirmed') {
        $resolvedPlayerId = (int) $row['suggested_player_id'];
    }
    // 'manual': el jugador lo elige el cliente. Sin validar, se podía asignar una fila a un jugador
    // de otro club (fk_dataset_rows_player es ON DELETE SET NULL, no se puede volver compuesta).
    // 'confirmed' pasa por acá también: la sugerencia pudo quedar de antes del scoping.
    if ($resolvedPlayerId !== null) {
        Scope::require($pdo, 'players', $resolvedPlayerId);
    }

    $clubId = Auth::clubId();

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE name_reconciliations SET resolution = :resolution, resolved_player_id = :resolved_player_id, resolved_at = NOW() WHERE id = :id AND club_id = :club'
        )->execute([
            'resolution' => $resolution,
            'resolved_player_id' => $resolvedPlayerId,
            'id' => $id,
            'club' => $clubId,
        ]);

        $matchStatus = $resolution === 'discarded' ? 'discarded' : 'matched';
        $pdo->prepare(
            'UPDATE dataset_rows SET player_id = :player_id, match_status = :match_status
             WHERE dataset_id = :dataset_id AND club_id = :club AND raw_name = :raw_name'
        )->execute([
            'player_id' => $resolution === 'discarded' ? null : $resolvedPlayerId,
            'match_status' => $matchStatus,
            'dataset_id' => $row['dataset_id'],
            'club' => $clubId,
            'raw_name' => $row['raw_name'],
        ]);

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        respondError(500, 'Error al resolver: ' . $e->getMessage());
    }

    echo json_encode(['ok' => true]);
}
