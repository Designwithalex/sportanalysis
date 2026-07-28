<?php
/**
 * Partial: <head> compartido.
 *
 * Variables que espera (todas opcionales salvo $pageTitle):
 *
 *   $pageTitle       string  Título de la pestaña. Default 'SportAnalysis'.
 *   $pageDescription string  <meta name="description">. Solo se emite si viene con valor
 *                            (lo usa la landing; las pantallas internas no lo necesitan).
 *   $assetPrefix     string  Prefijo de ruta desde la página hasta la raíz pública.
 *                              '../'  → DEFAULT. Páginas en public_html/steps/.
 *                                       Ninguna pantalla existente cambia de comportamiento.
 *                              ''     → Páginas en la raíz de public_html/
 *                                       (landing, login.php, registro.php, perfil.php).
 *   $useLandingCss   bool    true agrega css/landing.css. Solo la landing debe pedirlo:
 *                            es CSS de una superficie única, no del sistema compartido.
 *   $csrfToken       string  Token CSRF. El <meta name="csrf"> se emite SIEMPRE (aunque venga
 *                            vacío) para que el JS pueda leerlo sin chequear existencia:
 *                              document.querySelector('meta[name="csrf"]').content
 *                            El agente de auth solo tiene que setear la variable antes del require.
 *
 * Ejemplo desde la raíz:
 *     $pageTitle     = 'SportAnalysis — análisis de rendimiento para tu club';
 *     $assetPrefix   = '';
 *     $useLandingCss = true;
 *     require __DIR__ . '/app/views/head.php';
 */
require_once __DIR__ . '/../assets.php';

// Default '../' → las páginas de steps/ siguen funcionando sin tocar una línea.
$assetPrefix = $assetPrefix ?? '../';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php /* El sistema tiene dark mode completo por prefers-color-scheme. Declarárselo al navegador
         hace que los controles nativos (select, scrollbar, autofill, date picker) acompañen el
         tema en vez de renderizar claros sobre superficies oscuras. */ ?>
<meta name="color-scheme" content="light dark">
<meta name="csrf" content="<?= htmlspecialchars($csrfToken ?? '', ENT_QUOTES) ?>">
<?php if (!empty($pageDescription)): ?>
<meta name="description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES) ?>">
<?php endif; ?>
<title><?= htmlspecialchars($pageTitle ?? 'SportAnalysis') ?></title>
<link rel="stylesheet" href="<?= asset($assetPrefix . 'css/tokens.css') ?>">
<link rel="stylesheet" href="<?= asset($assetPrefix . 'css/base.css') ?>">
<link rel="stylesheet" href="<?= asset($assetPrefix . 'css/components.css') ?>">
<?php if (!empty($useLandingCss)): ?>
<link rel="stylesheet" href="<?= asset($assetPrefix . 'css/landing.css') ?>">
<?php endif; ?>
</head>
<body>
