<?php
/**
 * Partial: nav de las superficies públicas (landing, login, registro, recuperar contraseña).
 *
 * Arrastra los dos elementos de firma del appbar interno para que la puerta de entrada y el
 * producto se lean como la misma cosa:
 *   1. el tick vertical de 3×19px antes del wordmark (.publicnav-brand::before)
 *   2. el segmento signal de 44×2px apoyado sobre la hairline inferior (background-image)
 * Se diferencia del appbar en que las acciones de la derecha son de sesión, no de navegación
 * de la app.
 *
 * Variables (todas opcionales):
 *   $publicnavHome         string  href de la marca. Default '/'.
 *   $publicnavCurrent      string  'login' | 'registro' | null. Marca la pantalla actual con
 *                                  aria-current y degrada su acción a estado inerte: no se
 *                                  ofrece ir a donde ya estás.
 *   $publicnavLoginHref    string  Default 'login.php'.
 *   $publicnavRegistroHref string  Default 'registro.php'.
 *   $publicnavSticky       bool    Default true. La landing lo quiere pegajoso (CTA siempre
 *                                  visible); login/registro pueden pasarlo en false.
 *   $publicnavSkipTarget   string  id del <main> para el skip-link. Default 'contenido'.
 *                                  La página DEBE tener <main id="contenido" tabindex="-1">.
 */
$navHome     = $publicnavHome         ?? '/';
$navLogin    = $publicnavLoginHref    ?? 'login.php';
$navRegistro = $publicnavRegistroHref ?? 'registro.php';
$navCurrent  = $publicnavCurrent      ?? null;
$navSticky   = $publicnavSticky       ?? true;
$navSkip     = $publicnavSkipTarget   ?? 'contenido';
?>
<a class="publicnav-skip" href="#<?= htmlspecialchars($navSkip) ?>">Saltar al contenido</a>
<header class="publicnav<?= $navSticky ? ' publicnav-sticky' : '' ?>">
    <a class="publicnav-brand" href="<?= htmlspecialchars($navHome) ?>">
        <span class="publicnav-word">SportAnalysis</span>
        <span class="publicnav-sub">IA</span>
    </a>
    <nav class="publicnav-actions" aria-label="Sesión">
        <?php if ($navCurrent === 'login'): ?>
            <span class="publicnav-here" aria-current="page">Ingresar</span>
        <?php else: ?>
            <a class="publicnav-link" href="<?= htmlspecialchars($navLogin) ?>">Ingresar</a>
        <?php endif; ?>

        <?php if ($navCurrent === 'registro'): ?>
            <span class="publicnav-here" aria-current="page">Crear cuenta</span>
        <?php else: ?>
            <a class="publicnav-cta" href="<?= htmlspecialchars($navRegistro) ?>">Crear cuenta</a>
        <?php endif; ?>
    </nav>
</header>
