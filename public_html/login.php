<?php
/**
 * Ingreso. Pantalla pública: NO lleva requireAuth().
 *
 * El formulario se postea a sí mismo (sin fetch): la validación, el mensaje y el redirect los
 * decide el servidor, y sin JS la pantalla sigue funcionando entera.
 */
require __DIR__ . '/app/bootstrap_page.php';

$pageTitle   = 'Ingresar — SportAnalysis';
$assetPrefix = '';

// A dónde volver después de entrar. Se valida SIEMPRE (llegue por GET o por POST): un `next`
// sin validar es un open redirect de manual. Ver Auth::safeNext().
$next = Auth::safeNext($_GET['next'] ?? $_POST['next'] ?? null);
$destino = $next ?? 'panel.php';

// Ya logueado: no tiene sentido mostrarle el formulario.
if (Auth::check()) {
    header('Location: ' . $destino);
    exit;
}

$email  = '';
$errors = [];   // campo => mensaje
$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['pass'] ?? '');

    if (!Csrf::check(Csrf::fromRequest())) {
        // Típicamente la sesión caducó con el formulario abierto. No es un ataque: se le pide
        // recargar en vez de tirarle un 403 en la cara.
        $formError = 'La página estuvo abierta demasiado tiempo. Recargá e intentá de nuevo.';
    } elseif ($email === '') {
        $errors['email'] = 'Escribí tu email.';
    } elseif ($password === '') {
        $errors['pass'] = 'Escribí tu contraseña.';
    } elseif (Auth::attempt($email, $password)) {
        header('Location: ' . $destino);
        exit;
    } else {
        // Mensaje deliberadamente genérico: decir "ese email no existe" es publicar la lista de
        // cuentas registradas del sistema.
        $formError = 'Email o contraseña incorrectos.';
    }
}

require __DIR__ . '/app/views/head.php';

$publicnavHome    = 'index.php';
$publicnavCurrent = 'login';
$publicnavSticky  = false;
require __DIR__ . '/app/views/publicnav.php';
?>
<main class="auth-shell" id="contenido" tabindex="-1">
    <section class="auth-card">
        <p class="auth-eyebrow">Ingresar</p>
        <h1 class="auth-title">Entrá a tu club</h1>
        <p class="auth-sub">Tus datasets, tus vistas y tus dashboards.</p>

        <div class="form-status" role="status" aria-live="polite"><?php if ($formError !== ''): ?><div class="alert alert-error"><?= htmlspecialchars($formError) ?></div><?php endif; ?></div>

        <form class="auth-form" method="post" novalidate data-loading-form>
            <?= Csrf::field() ?>
            <input type="hidden" name="next" value="<?= htmlspecialchars($next ?? '', ENT_QUOTES) ?>">

            <div class="field<?= isset($errors['email']) ? ' has-error' : '' ?>">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" autocomplete="email" required
                       value="<?= htmlspecialchars($email, ENT_QUOTES) ?>"
                       <?= isset($errors['email']) ? 'aria-invalid="true" aria-describedby="email-err"' : '' ?>>
                <p class="field-error" id="email-err" role="alert"><?= htmlspecialchars($errors['email'] ?? '') ?></p>
            </div>

            <div class="field<?= isset($errors['pass']) ? ' has-error' : '' ?>">
                <label for="pass">Contraseña</label>
                <div class="field-password">
                    <input type="password" id="pass" name="pass" autocomplete="current-password" required
                           <?= isset($errors['pass']) ? 'aria-invalid="true" aria-describedby="pass-err"' : '' ?>>
                    <button type="button" class="field-password-toggle"
                            aria-label="Mostrar contraseña" aria-pressed="false">
                        <svg class="icon-eye" viewBox="0 0 24 24" focusable="false">
                            <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>
                        </svg>
                        <svg class="icon-eye-off" viewBox="0 0 24 24" focusable="false">
                            <path d="M3 3l18 18M10.6 10.6a3 3 0 004.2 4.2M9.9 5.2A9.6 9.6 0 0112 5c6.4 0 10 7 10 7a17 17 0 01-3.2 4M6.3 6.4A17 17 0 002 12s3.6 7 10 7c1 0 2-.2 2.9-.5"/>
                        </svg>
                    </button>
                </div>
                <p class="field-error" id="pass-err" role="alert"><?= htmlspecialchars($errors['pass'] ?? '') ?></p>
            </div>

            <button class="btn auth-submit btn-swap-label" type="submit">
                <span class="btn-spinner" aria-hidden="true"></span>
                <span class="btn-label">Entrar</span>
                <span class="btn-loading-label">Entrando…</span>
            </button>
        </form>

        <div class="auth-meta">¿No podés entrar? Pedile al administrador de tu club que revise tu cuenta.</div>
        <p class="auth-alt">¿Todavía no tenés cuenta? <a href="registro.php">Creá una</a></p>
    </section>
</main>
<script src="<?= asset('js/auth.js') ?>"></script>
</body>
</html>
