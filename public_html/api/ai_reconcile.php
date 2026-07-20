<?php

define('PL_APP', true);
require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/AnthropicClient.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}

$pdo = Database::get();
$datasetId = (int) ($_POST['dataset_id'] ?? 0);
if ($datasetId <= 0) {
    respondError(400, 'Falta dataset_id.');
}

// Nombres crudos que todavía no matchean (una sola vez cada uno).
$stmt = $pdo->prepare(
    "SELECT DISTINCT raw_name FROM dataset_rows
     WHERE dataset_id = :id AND match_status = 'unmatched' AND raw_name IS NOT NULL AND raw_name <> ''"
);
$stmt->execute(['id' => $datasetId]);
$rawNames = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($rawNames)) {
    echo json_encode(['ok' => true, 'suggested' => 0, 'message' => 'No hay nombres sin matchear en este dataset.']);
    exit;
}

$players = $pdo->query('SELECT id, nombre FROM players ORDER BY nombre')->fetchAll();
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
    'INSERT INTO name_reconciliations (dataset_id, raw_name, suggested_player_id, resolution)
     VALUES (:dataset_id, :raw_name, :suggested_player_id, "pending")'
);
$existsStmt = $pdo->prepare('SELECT id, resolution FROM name_reconciliations WHERE dataset_id = :dataset_id AND raw_name = :raw_name');
$updateStmt = $pdo->prepare(
    'UPDATE name_reconciliations SET suggested_player_id = :sid
     WHERE dataset_id = :dataset_id AND raw_name = :raw_name AND resolution = "pending"'
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

        $existsStmt->execute(['dataset_id' => $datasetId, 'raw_name' => $raw]);
        $existing = $existsStmt->fetch();
        if (!$existing) {
            $ensureStmt->execute(['dataset_id' => $datasetId, 'raw_name' => $raw, 'suggested_player_id' => $sid]);
            $suggested++;
        } elseif ($existing['resolution'] === 'pending') {
            $updateStmt->execute(['sid' => $sid, 'dataset_id' => $datasetId, 'raw_name' => $raw]);
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

function respondError(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}
