<?php
/**
 * Partial: appbar-instrumento compartido por todas las pantallas.
 * Marca condensada + chip mono "IA" + baseline signal.
 * $appbarAction (opcional) define la acción de la derecha:
 *   ['href' => ..., 'label' => ..., 'icon' => ..., 'primary' => bool]
 * Default: enlace a Configuración (para la superficie de análisis).
 */
$action = $appbarAction ?? ['href' => 'datos.php', 'label' => 'Configuración', 'icon' => '⚙'];
$linkClass = 'appbar-link' . (!empty($action['primary']) ? ' appbar-link-primary' : '');
?>
<div class="appbar">
    <div class="appbar-brand">SportAnalysis <span class="appbar-sub">IA</span></div>
    <a class="<?= $linkClass ?>" href="<?= htmlspecialchars($action['href']) ?>"><span class="appbar-link-icon" aria-hidden="true"><?= htmlspecialchars($action['icon'] ?? '') ?></span> <?= htmlspecialchars($action['label']) ?></a>
</div>
