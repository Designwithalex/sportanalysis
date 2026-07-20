<?php

define('PL_APP', true);
require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/Database.php';
require __DIR__ . '/../app/ViewGenerator.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}

$viewId = (int) ($_POST['view_id'] ?? 0);
if ($viewId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Falta view_id.']);
    exit;
}

$pdo = Database::get();

try {
    $generator = new ViewGenerator($pdo);
    $result = $generator->generate($viewId);
    echo json_encode(['ok' => true] + $result);
} catch (RuntimeException $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
