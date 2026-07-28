<?php
/**
 * Cierre de sesión.
 *
 * Solo por POST con token CSRF válido. Si fuera un GET pelado, cualquier <img src="/logout.php">
 * en un mail o en otra página tiraría al usuario afuera de la app sin que lo pida. Como igual
 * hay que soportar que alguien escriba la URL a mano, el GET muestra una confirmación con el
 * mismo formulario POST.
 *
 * Pantalla pública en el sentido de que NO lleva requireAuth(): cerrar una sesión que ya no
 * existe tiene que terminar en la landing, no en un redirect a login.
 */
require __DIR__ . '/app/bootstrap_page.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Csrf::check(Csrf::fromRequest())) {
    Auth::logout();
    header('Location: index.php');
    exit;
}

// Sin sesión abierta no hay nada que confirmar.
if (!Auth::check()) {
    header('Location: index.php');
    exit;
}

$pageTitle   = 'Cerrar sesión — SportAnalysis';
$assetPrefix = '';
require __DIR__ . '/app/views/head.php';

$publicnavHome   = 'index.php';
$publicnavSticky = false;
require __DIR__ . '/app/views/publicnav.php';
?>
<main class="auth-shell" id="contenido" tabindex="-1">
    <section class="auth-card">
        <p class="auth-eyebrow">Sesión</p>
        <h1 class="auth-title">¿Cerrás sesión?</h1>
        <p class="auth-sub">Vas a volver a la pantalla de ingreso. Tus datos quedan como están.</p>

        <form class="auth-form" method="post">
            <?= Csrf::field() ?>
            <button class="btn auth-submit btn-danger" type="submit">Cerrar sesión</button>
        </form>

        <p class="auth-alt"><a href="panel.php">Volver a la app</a></p>
    </section>
</main>
</body>
</html>
