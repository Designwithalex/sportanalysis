<?php
/**
 * Perfil: editar nombre y rol, y cambiar la contraseña.
 *
 * El club NO se edita acá: cambiar de club cambia qué datos ve la cuenta, así que no puede ser
 * un campo de texto en el perfil. Se muestra como dato de solo lectura.
 */
require __DIR__ . '/app/bootstrap_page.php';
requireAuth();

$pageTitle   = 'Mi perfil — SportAnalysis';
$assetPrefix = '';

$pdo  = Database::get();
$user = Auth::user();

$roles = [
    'Preparador físico', 'Entrenador', 'Head coach', 'Asistente técnico',
    'Médico', 'Kinesiólogo', 'Nutricionista', 'Analista de video', 'Manager',
];

$nombre = (string) $user['nombre'];
$rol    = (string) ($user['rol'] ?? '');

$datosErrors = $passErrors = [];
$datosOk = $passOk = '';
$datosError = $passError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');

    if (!Csrf::check(Csrf::fromRequest())) {
        $datosError = $passError = 'La página estuvo abierta demasiado tiempo. Recargá e intentá de nuevo.';
    } elseif ($accion === 'datos') {
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $rol    = trim((string) ($_POST['rol'] ?? ''));

        if ($nombre === '') {
            $datosErrors['nombre'] = 'El nombre no puede quedar vacío.';
        } elseif (mb_strlen($nombre) > 150) {
            $datosErrors['nombre'] = 'El nombre no puede pasar de 150 caracteres.';
        }
        if (mb_strlen($rol) > 80) {
            $datosErrors['rol'] = 'El rol no puede pasar de 80 caracteres.';
        }

        if (!$datosErrors) {
            $stmt = $pdo->prepare('UPDATE users SET nombre = :nombre, rol = :rol WHERE id = :id');
            $stmt->execute([
                'nombre' => $nombre,
                'rol'    => $rol !== '' ? $rol : null,
                'id'     => (int) $user['id'],
            ]);
            $datosOk = 'Listo, guardamos tus datos.';
            // Auth cachea el usuario por request: hay que releerlo para que el appbar y la
            // cabecera de esta misma pantalla muestren el nombre nuevo, no el viejo.
            $user = Auth::refresh();
            $nombre = (string) $user['nombre'];
            $rol    = (string) ($user['rol'] ?? '');
        } else {
            $datosError = 'Revisá los campos marcados.';
        }
    } elseif ($accion === 'password') {
        $actual = (string) ($_POST['actual'] ?? '');
        $nueva  = (string) ($_POST['nueva'] ?? '');
        $nueva2 = (string) ($_POST['nueva2'] ?? '');

        // Se relee el hash de la base: Auth::user() no lo expone a propósito, para que ningún
        // render lo tenga a mano por accidente.
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $user['id']]);
        $hash = (string) ($stmt->fetchColumn() ?: '');

        if ($actual === '' || !password_verify($actual, $hash)) {
            $passErrors['actual'] = 'La contraseña actual no es correcta.';
        } elseif (mb_strlen($nueva) < Auth::MIN_PASSWORD) {
            $passErrors['nueva'] = 'La nueva contraseña necesita al menos ' . Auth::MIN_PASSWORD . ' caracteres.';
        } elseif ($nueva !== $nueva2) {
            $passErrors['nueva2'] = 'Las dos contraseñas no coinciden.';
        } elseif ($nueva === $actual) {
            $passErrors['nueva'] = 'La nueva contraseña tiene que ser distinta de la actual.';
        }

        if (!$passErrors) {
            $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
            $stmt->execute([
                'hash' => password_hash($nueva, PASSWORD_DEFAULT),
                'id'   => (int) $user['id'],
            ]);
            // Id de sesión nuevo tras cambiar la credencial: si alguien tenía el id viejo, deja
            // de servirle.
            session_regenerate_id(true);
            $passOk = 'Contraseña actualizada.';
        } else {
            $passError = 'Revisá los campos marcados.';
        }
    }
}

$club = Auth::club();

require __DIR__ . '/app/views/head.php';
$appbarAction = ['href' => 'steps/analysis.php', 'label' => 'Ir a SportAnalysis', 'icon' => '→', 'primary' => true];
require __DIR__ . '/app/views/appbar.php';
?>
<div class="page">
    <div class="profile-head">
        <div class="profile-avatar" aria-hidden="true"><?= htmlspecialchars(Auth::initials($user['nombre'])) ?></div>
        <div>
            <h1 class="profile-name"><?= htmlspecialchars($user['nombre']) ?></h1>
            <p class="profile-meta"><?= htmlspecialchars($user['email']) ?> · <?= htmlspecialchars($club['nombre'] ?? '') ?></p>
        </div>
    </div>

    <div class="card">
        <div class="card-title">Datos de la cuenta</div>
        <div class="card-sub">El club no se cambia desde acá: define qué datos ve tu cuenta. Si te lo asignaron mal, avisale a quien administra la instalación.</div>

        <div class="form-status" role="status" aria-live="polite">
            <?php if ($datosOk !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($datosOk) ?></div><?php endif; ?>
            <?php if ($datosError !== ''): ?><div class="alert alert-error"><?= htmlspecialchars($datosError) ?></div><?php endif; ?>
        </div>

        <form method="post" novalidate data-loading-form>
            <?= Csrf::field() ?>
            <input type="hidden" name="accion" value="datos">

            <div class="field-row">
                <div class="field<?= isset($datosErrors['nombre']) ? ' has-error' : '' ?>">
                    <label for="pf-nombre">Nombre y apellido</label>
                    <input type="text" id="pf-nombre" name="nombre" required maxlength="150"
                           value="<?= htmlspecialchars($nombre, ENT_QUOTES) ?>"
                           <?= isset($datosErrors['nombre']) ? 'aria-invalid="true" aria-describedby="pf-nombre-err"' : '' ?>>
                    <p class="field-error" id="pf-nombre-err" role="alert"><?= htmlspecialchars($datosErrors['nombre'] ?? '') ?></p>
                </div>
                <div class="field<?= isset($datosErrors['rol']) ? ' has-error' : '' ?>">
                    <label for="pf-rol">Rol en el club</label>
                    <input type="text" id="pf-rol" name="rol" list="roles-list" autocomplete="off" maxlength="80"
                           placeholder="Ej: preparador físico"
                           value="<?= htmlspecialchars($rol, ENT_QUOTES) ?>"
                           <?= isset($datosErrors['rol']) ? 'aria-invalid="true" aria-describedby="pf-rol-err"' : '' ?>>
                    <datalist id="roles-list">
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= htmlspecialchars($r, ENT_QUOTES) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                    <p class="field-error" id="pf-rol-err" role="alert"><?= htmlspecialchars($datosErrors['rol'] ?? '') ?></p>
                </div>
            </div>

            <div class="field-row">
                <div class="field">
                    <label for="pf-email">Email</label>
                    <input type="email" id="pf-email" value="<?= htmlspecialchars($user['email'], ENT_QUOTES) ?>" disabled>
                </div>
                <div class="field">
                    <label for="pf-club">Club</label>
                    <input type="text" id="pf-club" value="<?= htmlspecialchars($club['nombre'] ?? '', ENT_QUOTES) ?>" disabled>
                </div>
            </div>

            <div class="btn-row">
                <button class="btn btn-swap-label" type="submit">
                    <span class="btn-spinner" aria-hidden="true"></span>
                    <span class="btn-label">Guardar cambios</span>
                    <span class="btn-loading-label">Guardando…</span>
                </button>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-title">Contraseña</div>
        <div class="card-sub">Para cambiarla hace falta la actual. Mínimo <?= Auth::MIN_PASSWORD ?> caracteres.</div>

        <div class="form-status" role="status" aria-live="polite">
            <?php if ($passOk !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($passOk) ?></div><?php endif; ?>
            <?php if ($passError !== ''): ?><div class="alert alert-error"><?= htmlspecialchars($passError) ?></div><?php endif; ?>
        </div>

        <form method="post" novalidate data-loading-form>
            <?= Csrf::field() ?>
            <input type="hidden" name="accion" value="password">

            <div class="field<?= isset($passErrors['actual']) ? ' has-error' : '' ?>">
                <label for="pw-actual">Contraseña actual</label>
                <div class="field-password">
                    <input type="password" id="pw-actual" name="actual" autocomplete="current-password" required
                           <?= isset($passErrors['actual']) ? 'aria-invalid="true" aria-describedby="pw-actual-err"' : '' ?>>
                    <button type="button" class="field-password-toggle" aria-label="Mostrar contraseña" aria-pressed="false">
                        <svg class="icon-eye" viewBox="0 0 24 24" focusable="false">
                            <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>
                        </svg>
                        <svg class="icon-eye-off" viewBox="0 0 24 24" focusable="false">
                            <path d="M3 3l18 18M10.6 10.6a3 3 0 004.2 4.2M9.9 5.2A9.6 9.6 0 0112 5c6.4 0 10 7 10 7a17 17 0 01-3.2 4M6.3 6.4A17 17 0 002 12s3.6 7 10 7c1 0 2-.2 2.9-.5"/>
                        </svg>
                    </button>
                </div>
                <p class="field-error" id="pw-actual-err" role="alert"><?= htmlspecialchars($passErrors['actual'] ?? '') ?></p>
            </div>

            <div class="field<?= isset($passErrors['nueva']) ? ' has-error' : '' ?>">
                <label for="pw-nueva">Contraseña nueva</label>
                <div class="field-password">
                    <input type="password" id="pw-nueva" name="nueva" autocomplete="new-password" required
                           minlength="<?= Auth::MIN_PASSWORD ?>" data-pw-meter="pw-meter"
                           <?= isset($passErrors['nueva']) ? 'aria-invalid="true" aria-describedby="pw-nueva-err"' : '' ?>>
                    <button type="button" class="field-password-toggle" aria-label="Mostrar contraseña" aria-pressed="false">
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
                <p class="field-error" id="pw-nueva-err" role="alert"><?= htmlspecialchars($passErrors['nueva'] ?? '') ?></p>
            </div>

            <div class="field<?= isset($passErrors['nueva2']) ? ' has-error' : '' ?>">
                <label for="pw-nueva2">Repetí la contraseña nueva</label>
                <input type="password" id="pw-nueva2" name="nueva2" autocomplete="new-password" required
                       <?= isset($passErrors['nueva2']) ? 'aria-invalid="true" aria-describedby="pw-nueva2-err"' : '' ?>>
                <p class="field-error" id="pw-nueva2-err" role="alert"><?= htmlspecialchars($passErrors['nueva2'] ?? '') ?></p>
            </div>

            <div class="btn-row">
                <button class="btn btn-swap-label" type="submit">
                    <span class="btn-spinner" aria-hidden="true"></span>
                    <span class="btn-label">Cambiar contraseña</span>
                    <span class="btn-loading-label">Cambiando…</span>
                </button>
            </div>
        </form>
    </div>
</div>
<script src="<?= asset('js/auth.js') ?>"></script>
</body>
</html>
