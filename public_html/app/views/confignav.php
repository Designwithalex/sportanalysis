<?php
/**
 * Partial: barra de Configuración (Plantel → Datos). Reemplaza al viejo stepper de 5 pasos.
 * Configuración es la fase que se hace una vez; después el usuario vive en SportAnalysis.
 * Espera $currentStep (1 = Plantel, 2 = Datos).
 */
$steps = [
    1 => ['label' => 'Plantel', 'href' => 'plantel.php'],
    2 => ['label' => 'Datos', 'href' => 'datos.php'],
];
?>
<nav class="confignav" aria-label="Configuración">
    <div class="confignav-steps">
        <span class="confignav-label">Configuración</span>
        <?php foreach ($steps as $num => $step): ?>
            <?php $state = $num === $currentStep ? 'active' : ''; ?>
            <a class="confignav-step <?= $state ?>" href="<?= htmlspecialchars($step['href']) ?>">
                <span class="confignav-num"><?= $num ?></span>
                <span class="confignav-name"><?= htmlspecialchars($step['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</nav>
