<?php
/**
 * Partial: wizard stepper.
 * Espera $currentStep (1-5) definido por quien lo incluye.
 */
$steps = [
    1 => ['label' => 'Plantel', 'href' => 'plantel.php'],
    2 => ['label' => 'Datos', 'href' => 'datos.php'],
    3 => ['label' => 'Vistas', 'href' => 'vistas.php'],
    4 => ['label' => 'Validación', 'href' => 'validacion.php'],
    5 => ['label' => 'Dashboard', 'href' => 'dashboard.php'],
];
?>
<nav class="stepper" aria-label="Progreso del wizard">
    <?php foreach ($steps as $num => $step): ?>
        <?php
        $state = $num < $currentStep ? 'done' : ($num === $currentStep ? 'active' : '');
        ?>
        <a class="step <?= $state ?>" href="<?= htmlspecialchars($step['href']) ?>">
            <span class="step-badge"><?= $num < $currentStep ? '&#10003;' : $num ?></span>
            <span class="step-name"><?= htmlspecialchars($step['label']) ?></span>
        </a>
        <?php if ($num < count($steps)): ?>
            <span class="step-trace <?= $state ?>"></span>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>
