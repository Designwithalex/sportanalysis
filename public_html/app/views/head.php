<?php
/** Partial: <head> compartido. Espera $pageTitle. */
require_once __DIR__ . '/../assets.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle ?? 'SportAnalysis') ?></title>
<link rel="stylesheet" href="<?= asset('../css/tokens.css') ?>">
<link rel="stylesheet" href="<?= asset('../css/base.css') ?>">
<link rel="stylesheet" href="<?= asset('../css/components.css') ?>">
</head>
<body>
