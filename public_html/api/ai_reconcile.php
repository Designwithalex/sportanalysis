<?php

require __DIR__ . '/../app/bootstrap_api.php';
require __DIR__ . '/../app/AnthropicClient.php';

// Guard de sesión. Va antes de session_write_close() (lee $_SESSION) y antes de tocar la base.
// Además valida el token anti-CSRF en todo método que no sea GET/HEAD.
requireAuth();

requireMethod('POST');

// PHP mantiene un lock exclusivo sobre el archivo de sesión mientras está abierta, lo que serializa
// todas las requests del mismo usuario. Con una llamada a la IA de ~60s por delante, eso congelaría
// la app entera. Cerramos la sesión para escritura acá; a partir de este punto no se toca $_SESSION.
session_write_close();

$pdo = Database::get();
$datasetId = (int) ($_POST['dataset_id'] ?? 0);
if ($datasetId <= 0) {
    respondError(400, 'Falta dataset_id.');
}

// dataset_id llega del cliente y todo lo que sale de él (nombres crudos, plantel) termina en el
// prompt de la IA. 404 antes de leer nada si el dataset no es de este club.
Scope::require($pdo, 'datasets', $datasetId);

// Se resuelve ANTES de la llamada a la IA: después de session_write_close() + ~60s de espera no
// queremos depender de volver a resolver la sesión. Auth lo tiene cacheado desde requireAuth().
$clubId = Auth::clubId();

// Nombres crudos que todavía no matchean (una sola vez cada uno).
$stmt = $pdo->prepare(
    "SELECT DISTINCT raw_name FROM dataset_rows
     WHERE dataset_id = :id AND club_id = :club AND match_status = 'unmatched' AND raw_name IS NOT NULL AND raw_name <> ''"
);
$stmt->execute(['id' => $datasetId, 'club' => $clubId]);
$rawNames = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($rawNames)) {
    echo json_encode(['ok' => true, 'suggested' => 0, 'message' => 'No hay nombres sin matchear en este dataset.']);
    exit;
}

// Solo el plantel de este club: la lista completa de nombres viaja al prompt de la IA.
$playersStmt = $pdo->prepare('SELECT id, nombre FROM players WHERE club_id = :club ORDER BY nombre');
$playersStmt->execute(['club' => $clubId]);
$players = $playersStmt->fetchAll();
if (empty($players)) {
    respondError(422, 'No hay plantel cargado para matchear.');
}

// La IA solo SUGIERE: nunca se aplica sola. El usuario confirma en la pantalla de reconciliación.
$systemPrompt = <<<PROMPT
Sos un asistente que empareja nombres de jugadores de rugby entre un plantel oficial y los nombres tal como aparecen en un CSV (que suelen venir sucios).

Te doy el PLANTEL como lista de "id: nombre" y una lista de NOMBRES CRUDOS del CSV. Para cada nombre crudo, encontrá el jugador del plantel que es la MISMA persona, tolerando:
- orden invertido (apellido/nombre)
- tildes y mayúsculas
- segundo nombre o inicial de más
- apodos comunes en español (Ale=Alejandro, Nacho=Ignacio, Colo, Pancho=Francisco, Santi=Santiago, etc.)
- iniciales o abreviaciones (A. Acosta = Alejandro Acosta)

Reglas:
- Usá SOLO los id que te doy. Nunca inventes un id.
- Si no hay un match razonablemente claro, devolvé id null para ese nombre (mejor no sugerir que sugerir mal).
- Respondé ÚNICAMENTE un array JSON, sin texto ni markdown, con la forma:
  [ { "raw": "<nombre crudo tal cual te lo di>", "id": <id del plantel o null> } ]
PROMPT;

$playerLines = [];
foreach ($players as $p) {
    $playerLines[] = "{$p['id']}: {$p['nombre']}";
}
$userPrompt = "PLANTEL:\n" . implode("\n", $playerLines)
    . "\n\nNOMBRES CRUDOS:\n" . implode("\n", $rawNames)
    . "\n\nDevolvé el array JSON ahora.";

try {
    $responseText = AnthropicClient::complete($systemPrompt, $userPrompt, 4000);
    $matches = AnthropicClient::extractJson($responseText);
} catch (RuntimeException $e) {
    respondError(502, 'Error al consultar la IA: ' . $e->getMessage());
}

if (!is_array($matches)) {
    respondError(502, 'La IA no devolvió una lista de matches.');
}

$validIds = array_column($players, 'id');
$rawSet = array_flip($rawNames);

// Aseguramos que exista una reconciliación pendiente por nombre, y le cargamos la sugerencia de la IA.
$ensureStmt = $pdo->prepare(
    'INSERT INTO name_reconciliations (club_id, dataset_id, raw_name, suggested_player_id, resolution)
     VALUES (:club, :dataset_id, :raw_name, :suggested_player_id, "pending")'
);
$existsStmt = $pdo->prepare('SELECT id, resolution FROM name_reconciliations WHERE dataset_id = :dataset_id AND club_id = :club AND raw_name = :raw_name');
$updateStmt = $pdo->prepare(
    'UPDATE name_reconciliations SET suggested_player_id = :sid
     WHERE dataset_id = :dataset_id AND club_id = :club AND raw_name = :raw_name AND resolution = "pending"'
);

$suggested = 0;
$pdo->beginTransaction();
try {
    foreach ($matches as $m) {
        $raw = $m['raw'] ?? null;
        $sid = isset($m['id']) && $m['id'] !== null ? (int) $m['id'] : null;

        if ($raw === null || !isset($rawSet[$raw])) {
            continue; // nombre que no estaba en la lista, se ignora
        }
        if ($sid !== null && !in_array($sid, $validIds, true)) {
            continue; // id inventado, se descarta
        }
        if ($sid === null) {
            continue; // sin match: no sugerimos nada
        }

        $existsStmt->execute(['dataset_id' => $datasetId, 'club' => $clubId, 'raw_name' => $raw]);
        $existing = $existsStmt->fetch();
        if (!$existing) {
            $ensureStmt->execute(['club' => $clubId, 'dataset_id' => $datasetId, 'raw_name' => $raw, 'suggested_player_id' => $sid]);
            $suggested++;
        } elseif ($existing['resolution'] === 'pending') {
            $updateStmt->execute(['sid' => $sid, 'dataset_id' => $datasetId, 'club' => $clubId, 'raw_name' => $raw]);
            $suggested++;
        }
    }
    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    respondError(500, 'Error al guardar las sugerencias: ' . $e->getMessage());
}

echo json_encode([
    'ok' => true,
    'suggested' => $suggested,
    'message' => "La IA sugirió $suggested match(es). Revisá y confirmá cada uno abajo.",
]);
exit;
