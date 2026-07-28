<?php
/**
 * Alta de cuenta. Pantalla pública: NO lleva requireAuth().
 *
 * El club se elige de la lista de clubes activos con <input> + <datalist> (son 70: un <select>
 * obliga a scrollear, el datalist deja escribir para filtrar). El id del club NUNCA llega del
 * cliente: llega el texto y el servidor lo resuelve contra la tabla. Si no matchea, error.
 *
 * El rol es texto libre — una etiqueta descriptiva, no un permiso. El <datalist> sugiere los
 * habituales pero se acepta cualquier cosa.
 */
require __DIR__ . '/app/bootstrap_page.php';

$pageTitle   = 'Crear cuenta — SportAnalysis';
$assetPrefix = '';

if (Auth::check()) {
    header('Location: panel.php');
    exit;
}

$pdo = Database::get();
$clubs = $pdo->query('SELECT id, nombre, slug FROM clubs WHERE activo = 1 ORDER BY nombre')->fetchAll();

/**
 * Clave de comparación de nombres de club: minúsculas, sin tildes, espacios colapsados.
 * Así "Asociación Alumni", "asociacion alumni" y "  Asociacion   Alumni " son el mismo club.
 * Mapa explícito en vez de iconv//TRANSLIT: el resultado de TRANSLIT depende del locale del
 * servidor y en hosting compartido no se puede dar por sentado.
 */
function clubKey(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = strtr($s, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ñ' => 'n', 'ç' => 'c',
    ]);
    return (string) preg_replace('/\s+/u', ' ', $s);
}

$roles = [
    'Preparador físico', 'Entrenador', 'Head coach', 'Asistente técnico',
    'Médico', 'Kinesiólogo', 'Nutricionista', 'Analista de video', 'Manager',
];

$nombre = $emailIn = $clubIn = $rolIn = '';
$errors = [];
$formError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre   = trim((string) ($_POST['nombre'] ?? ''));
    $emailIn  = trim((string) ($_POST['email'] ?? ''));
    $clubIn   = trim((string) ($_POST['club'] ?? ''));
    $rolIn    = trim((string) ($_POST['rol'] ?? ''));
    $password = (string) ($_POST['pass'] ?? '');
    $password2 = (string) ($_POST['pass2'] ?? '');

    if (!Csrf::check(Csrf::fromRequest())) {
        $formError = 'La página estuvo abierta demasiado tiempo. Recargá e intentá de nuevo.';
    } else {
        if ($nombre === '') {
            $errors['nombre'] = 'Escribí tu nombre.';
        } elseif (mb_strlen($nombre) > 150) {
            $errors['nombre'] = 'El nombre no puede pasar de 150 caracteres.';
        }

        if ($emailIn === '') {
            $errors['email'] = 'Escribí tu email.';
        } elseif (!filter_var($emailIn, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Ese email no parece válido.';
        } elseif (mb_strlen($emailIn) > 190) {
            $errors['email'] = 'El email no puede pasar de 190 caracteres.';
        }

        if ($password === '') {
            $errors['pass'] = 'Elegí una contraseña.';
        } elseif (mb_strlen($password) < Auth::MIN_PASSWORD) {
            $errors['pass'] = 'La contraseña necesita al menos ' . Auth::MIN_PASSWORD . ' caracteres.';
        } elseif ($password !== $password2) {
            $errors['pass2'] = 'Las dos contraseñas no coinciden.';
        }

        if (mb_strlen($rolIn) > 80) {
            $errors['rol'] = 'El rol no puede pasar de 80 caracteres.';
        }

        // Resolución del club: texto → id, contra la lista de clubes activos.
        $clubId = 0;
        if ($clubIn === '') {
            $errors['club'] = 'Elegí tu club de la lista.';
        } else {
            $key = clubKey($clubIn);
            foreach ($clubs as $c) {
                if (clubKey($c['nombre']) === $key || mb_strtolower($c['slug']) === $key) {
                    $clubId = (int) $c['id'];
                    break;
                }
            }
            if ($clubId === 0) {
                $errors['club'] = 'No encontramos ese club. Elegilo de la lista que aparece al escribir.';
            }
        }

        // Chequeo previo del email para dar un mensaje claro. La constraint UNIQUE sigue siendo
        // la que manda: entre este SELECT y el INSERT puede colarse otro registro, y ese caso se
        // atrapa abajo por código de error en vez de dejar explotar la excepción.
        if (!$errors) {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => Auth::normalizeEmail($emailIn)]);
            if ($stmt->fetch()) {
                $errors['email'] = 'Ese email ya tiene una cuenta. Probá ingresar.';
            }
        }

        if (!$errors) {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO users (club_id, email, password_hash, nombre, rol, status)
                     VALUES (:club_id, :email, :hash, :nombre, :rol, "active")'
                );
                $stmt->execute([
                    'club_id' => $clubId,
                    'email'   => Auth::normalizeEmail($emailIn),
                    'hash'    => password_hash($password, PASSWORD_DEFAULT),
                    'nombre'  => $nombre,
                    'rol'     => $rolIn !== '' ? $rolIn : null,
                ]);

                Auth::login(['id' => (int) $pdo->lastInsertId()]);
                header('Location: panel.php');
                exit;
            } catch (PDOException $e) {
                // 1062 = duplicate entry. Es el único caso esperable acá: carrera contra otro alta
                // con el mismo email. Cualquier otro error se re-lanza.
                if (($e->errorInfo[1] ?? 0) === 1062) {
                    $errors['email'] = 'Ese email ya tiene una cuenta. Probá ingresar.';
                } else {
                    throw $e;
                }
            }
        }

        if ($errors && $formError === '') {
            $formError = 'Revisá los campos marcados.';
        }
    }
}

require __DIR__ . '/app/views/head.php';

$publicnavHome    = 'index.php';
$publicnavCurrent = 'registro';
$publicnavSticky  = false;
require __DIR__ . '/app/views/publicnav.php';
?>
<main class="auth-shell" id="contenido" tabindex="-1">
    <section class="auth-card">
        <p class="auth-eyebrow">Crear cuenta</p>
        <h1 class="auth-title">Sumate a tu club</h1>
        <p class="auth-sub">Elegí tu club de la lista y contanos qué hacés ahí. El rol es una etiqueta descriptiva, no cambia permisos.</p>

        <div class="form-status" role="status" aria-live="polite"><?php if ($formError !== ''): ?><div class="alert alert-error"><?= htmlspecialchars($formError) ?></div><?php endif; ?></div>

        <form class="auth-form" method="post" novalidate data-loading-form>
            <?= Csrf::field() ?>

            <div class="field<?= isset($errors['nombre']) ? ' has-error' : '' ?>">
                <label for="nombre">Nombre y apellido</label>
                <input type="text" id="nombre" name="nombre" autocomplete="name" required maxlength="150"
                       value="<?= htmlspecialchars($nombre, ENT_QUOTES) ?>"
                       <?= isset($errors['nombre']) ? 'aria-invalid="true" aria-describedby="nombre-err"' : '' ?>>
                <p class="field-error" id="nombre-err" role="alert"><?= htmlspecialchars($errors['nombre'] ?? '') ?></p>
            </div>

            <div class="field<?= isset($errors['email']) ? ' has-error' : '' ?>">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" autocomplete="email" required maxlength="190"
                       value="<?= htmlspecialchars($emailIn, ENT_QUOTES) ?>"
                       <?= isset($errors['email']) ? 'aria-invalid="true" aria-describedby="email-err"' : '' ?>>
                <p class="field-error" id="email-err" role="alert"><?= htmlspecialchars($errors['email'] ?? '') ?></p>
            </div>

            <div class="field<?= isset($errors['club']) ? ' has-error' : '' ?>">
                <label for="club">Club</label>
                <input type="text" id="club" name="club" list="clubs-list" required autocomplete="off"
                       placeholder="Empezá a escribir el nombre…"
                       value="<?= htmlspecialchars($clubIn, ENT_QUOTES) ?>"
                       <?= isset($errors['club']) ? 'aria-invalid="true" aria-describedby="club-err"' : '' ?>>
                <datalist id="clubs-list">
                    <?php foreach ($clubs as $c): ?>
                        <option value="<?= htmlspecialchars($c['nombre'], ENT_QUOTES) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <p class="field-error" id="club-err" role="alert"><?= htmlspecialchars($errors['club'] ?? '') ?></p>
            </div>

            <div class="field<?= isset($errors['rol']) ? ' has-error' : '' ?>">
                <label for="rol">Rol en el club <span class="numeric">(opcional)</span></label>
                <input type="text" id="rol" name="rol" list="roles-list" autocomplete="off" maxlength="80"
                       placeholder="Ej: preparador físico"
                       value="<?= htmlspecialchars($rolIn, ENT_QUOTES) ?>"
                       <?= isset($errors['rol']) ? 'aria-invalid="true" aria-describedby="rol-err"' : '' ?>>
                <datalist id="roles-list">
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= htmlspecialchars($r, ENT_QUOTES) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <p class="field-error" id="rol-err" role="alert"><?= htmlspecialchars($errors['rol'] ?? '') ?></p>
            </div>

            <div class="field<?= isset($errors['pass']) ? ' has-error' : '' ?>">
                <label for="pass">Contraseña</label>
                <div class="field-password">
                    <input type="password" id="pass" name="pass" autocomplete="new-password" required
                           minlength="<?= Auth::MIN_PASSWORD ?>" data-pw-meter="pw-meter"
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
                <div class="pw-meter" id="pw-meter" data-level="0">
                    <div class="pw-meter-track"><i></i><i></i><i></i></div>
                    <span class="pw-meter-text"></span>
                </div>
                <p class="field-error" id="pass-err" role="alert"><?= htmlspecialchars($errors['pass'] ?? '') ?></p>
            </div>

            <div class="field<?= isset($errors['pass2']) ? ' has-error' : '' ?>">
                <label for="pass2">Repetí la contraseña</label>
                <input type="password" id="pass2" name="pass2" autocomplete="new-password" required
                       <?= isset($errors['pass2']) ? 'aria-invalid="true" aria-describedby="pass2-err"' : '' ?>>
                <p class="field-error" id="pass2-err" role="alert"><?= htmlspecialchars($errors['pass2'] ?? '') ?></p>
            </div>

            <button class="btn auth-submit btn-swap-label" type="submit">
                <span class="btn-spinner" aria-hidden="true"></span>
                <span class="btn-label">Crear cuenta</span>
                <span class="btn-loading-label">Creando…</span>
            </button>
        </form>

        <p class="auth-legal">Al crear la cuenta vas a ver los datos cargados para tu club.</p>
        <p class="auth-alt">¿Ya tenés cuenta? <a href="login.php">Ingresá</a></p>
    </section>
</main>
<script src="<?= asset('js/auth.js') ?>"></script>
</body>
</html>
