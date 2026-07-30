<?php
/**
 * Verificación de pertenencia al club — pantalla del solicitante.
 *
 * El alta de registro.php deja al usuario en status 'pending'. Acá deja la evidencia de que
 * pertenece al club que eligió, y espera a que la revise el administrador de ese club (o el
 * superadmin, si el club todavía no tiene administrador).
 *
 * La evidencia es SIEMPRE un dato de texto o un LINK, nunca un archivo subido: el proyecto no
 * escribe archivos a disco y no queremos abrir esa superficie (upload = validación de tipo,
 * cuota, path traversal, ejecución). Un link lo hostea el solicitante donde quiera.
 *
 * Esta pantalla es la única privada accesible con status 'pending' o 'rechazado': el guard de
 * sesión la exceptúa a propósito, porque si no el usuario quedaría encerrado sin poder mandar
 * la evidencia que necesita para dejar de estar pendiente.
 *
 * Una sola fila por usuario en `verificaciones`: mientras está pendiente puede corregirla y
 * reenviarla (UPDATE), y si se la rechazaron el reenvío la devuelve a 'pendiente' limpiando el
 * motivo. Nunca se acumulan filas: la cola del administrador tiene que tener una entrada por
 * persona, no un historial.
 */
require __DIR__ . '/app/bootstrap_page.php';
requireAuth();

$pageTitle   = 'Verificá tu cuenta — SportAnalysis';
$assetPrefix = '';

// Cuenta ya aprobada: no tiene nada que hacer acá.
if (Auth::status() === 'active') {
    header('Location: panel.php');
    exit;
}

/**
 * Topes de longitud. Van del lado del servidor además del maxlength del input: el maxlength es
 * una comodidad del navegador, no una validación (un POST a mano lo ignora) y una cadena más
 * larga que la columna termina en truncado silencioso o en excepción de MySQL.
 */
const VERIF_MAX_INSTAGRAM = 120;   // = verificaciones.instagram VARCHAR(120)
const VERIF_MAX_SOCIO     = 60;    // = verificaciones.numero_socio VARCHAR(60)
const VERIF_MAX_URL       = 500;   // = verificaciones.foto_url VARCHAR(500)
const VERIF_MAX_NOTA      = 500;   // la columna es TEXT: este tope es de producto, no de esquema

if (!function_exists('verifUrlHttp')) {
    /**
     * ¿Es una URL http/https usable como enlace?
     *
     * El chequeo del esquema es lo importante y va explícito: FILTER_VALIDATE_URL por sí solo
     * acepta `javascript://algo%0Aalert(1)` y `data:text/html,...`, que como href son XSS
     * directo sobre la pantalla del administrador. Lista blanca de esquemas, no lista negra.
     */
    function verifUrlHttp(string $url): bool
    {
        if ($url === '' || mb_strlen($url) > VERIF_MAX_URL) {
            return false;
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }
        // Sin host no hay a dónde ir: descarta cosas como "http:///x".
        return (string) parse_url($url, PHP_URL_HOST) !== '';
    }
}

if (!function_exists('verifInstagramUrl')) {
    /**
     * Normaliza lo que el usuario escribió en el campo Instagram a una URL segura, o null.
     * Acepta las dos formas naturales: el usuario ("@juan.perez" o "juan.perez") o el link
     * completo al perfil. Cualquier otra cosa no se convierte en enlace.
     */
    function verifInstagramUrl(string $valor): ?string
    {
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $valor)) {
            return verifUrlHttp($valor) ? $valor : null;
        }
        $handle = ltrim($valor, '@');
        if (preg_match('/^[A-Za-z0-9._]{1,30}$/', $handle)) {
            return 'https://www.instagram.com/' . $handle . '/';
        }
        return null;
    }
}

$pdo    = Database::get();
$user   = Auth::user();
$userId = (int) $user['id'];
$clubId = (int) $user['club_id'];
$club   = Auth::club();

$sel = 'SELECT id, instagram, numero_socio, foto_url, nota, estado, motivo_rechazo, created_at
          FROM verificaciones WHERE user_id = :uid ORDER BY id DESC LIMIT 1';
$stmt = $pdo->prepare($sel);
$stmt->execute(['uid' => $userId]);
$solicitud = $stmt->fetch() ?: null;

$instagram   = (string) ($solicitud['instagram'] ?? '');
$numeroSocio = (string) ($solicitud['numero_socio'] ?? '');
$fotoUrl     = (string) ($solicitud['foto_url'] ?? '');
$nota        = (string) ($solicitud['nota'] ?? '');

$errors    = [];
$formError = '';
$formOk    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $instagram   = trim((string) ($_POST['instagram'] ?? ''));
    $numeroSocio = trim((string) ($_POST['numero_socio'] ?? ''));
    $fotoUrl     = trim((string) ($_POST['foto_url'] ?? ''));
    $nota        = trim((string) ($_POST['nota'] ?? ''));

    if (!Csrf::check(Csrf::fromRequest())) {
        $formError = 'La página estuvo abierta demasiado tiempo. Recargá e intentá de nuevo.';
    } else {
        if (mb_strlen($instagram) > VERIF_MAX_INSTAGRAM) {
            $errors['instagram'] = 'El usuario de Instagram no puede pasar de ' . VERIF_MAX_INSTAGRAM . ' caracteres.';
        } elseif ($instagram !== '' && verifInstagramUrl($instagram) === null) {
            $errors['instagram'] = 'Poné tu usuario (por ejemplo @juan.perez) o el link completo a tu perfil.';
        }

        if (mb_strlen($numeroSocio) > VERIF_MAX_SOCIO) {
            $errors['numero_socio'] = 'El número de socio no puede pasar de ' . VERIF_MAX_SOCIO . ' caracteres.';
        }

        if ($fotoUrl !== '') {
            if (mb_strlen($fotoUrl) > VERIF_MAX_URL) {
                $errors['foto_url'] = 'El link no puede pasar de ' . VERIF_MAX_URL . ' caracteres.';
            } elseif (!verifUrlHttp($fotoUrl)) {
                $errors['foto_url'] = 'Tiene que ser un link que empiece con http:// o https://.';
            }
        }

        if (mb_strlen($nota) > VERIF_MAX_NOTA) {
            $errors['nota'] = 'La nota no puede pasar de ' . VERIF_MAX_NOTA . ' caracteres.';
        }

        // Los cuatro campos son opcionales por separado, pero mandar los cuatro vacíos deja al
        // administrador sin nada que revisar: la solicitud sería un pedido de confianza ciega.
        if (!$errors && $instagram === '' && $numeroSocio === '' && $fotoUrl === '' && $nota === '') {
            $errors['instagram'] = 'Completá al menos uno de los cuatro campos: sin evidencia no hay nada que revisar.';
        }

        if (!$errors) {
            $datos = [
                'instagram' => $instagram !== '' ? $instagram : null,
                'socio'     => $numeroSocio !== '' ? $numeroSocio : null,
                'foto'      => $fotoUrl !== '' ? $fotoUrl : null,
                'nota'      => $nota !== '' ? $nota : null,
            ];

            if ($solicitud) {
                // Reenvío: vuelve a la cola en 'pendiente' y se borra el rastro de la revisión
                // anterior (motivo, quién y cuándo), que ya no describe lo que el admin ve.
                // El AND estado <> 'aprobada' es un cinturón por si quedara una fila aprobada
                // con el usuario todavía no activo: una aprobación no se revierte desde acá.
                $up = $pdo->prepare(
                    "UPDATE verificaciones
                        SET instagram = :instagram, numero_socio = :socio, foto_url = :foto, nota = :nota,
                            estado = 'pendiente', motivo_rechazo = NULL, revisada_por = NULL, revisada_at = NULL
                      WHERE id = :id AND user_id = :uid AND estado <> 'aprobada'"
                );
                $up->execute($datos + ['id' => (int) $solicitud['id'], 'uid' => $userId]);
            } else {
                $ins = $pdo->prepare(
                    "INSERT INTO verificaciones (user_id, club_id, instagram, numero_socio, foto_url, nota, estado)
                     VALUES (:uid, :cid, :instagram, :socio, :foto, :nota, 'pendiente')"
                );
                $ins->execute($datos + ['uid' => $userId, 'cid' => $clubId]);
            }

            // Se relee la fila para que la banda de estado muestre lo que quedó guardado, no lo
            // que se creyó guardar.
            $stmt = $pdo->prepare($sel);
            $stmt->execute(['uid' => $userId]);
            $solicitud = $stmt->fetch() ?: null;

            $formOk = 'Listo, mandamos tu solicitud. Volvé a esta pantalla para ver cómo viene.';
        } else {
            $formError = 'Revisá los campos marcados.';
        }
    }
}

$estado = (string) ($solicitud['estado'] ?? '');
$motivo = (string) ($solicitud['motivo_rechazo'] ?? '');

require __DIR__ . '/app/views/head.php';
?>
<main class="auth-shell" id="contenido" tabindex="-1">
    <section class="auth-card">
        <p class="auth-eyebrow">Cuenta pendiente</p>
        <h1 class="auth-title">Verificá que sos del club</h1>
        <p class="auth-sub">
            Tu cuenta está creada para <strong><?= htmlspecialchars($club['nombre'] ?? '') ?></strong>, pero
            todavía no puede ver los datos. Dejanos algo con lo que confirmar que pertenecés al club y
            alguien del club lo revisa.
        </p>

        <div class="form-status" role="status" aria-live="polite">
            <?php if ($formOk !== ''): ?>
                <div class="alert alert-success"><?= htmlspecialchars($formOk) ?></div>
            <?php endif; ?>
            <?php if ($formError !== ''): ?>
                <div class="alert alert-error"><?= htmlspecialchars($formError) ?></div>
            <?php endif; ?>
        </div>

        <?php if ($estado === 'rechazada'): ?>
            <?php /* .alert tiene white-space: pre-line, así que los saltos de línea del motivo se
                     respetan y no hace falta armarlo con <br>. El markup va compacto a propósito:
                     un salto de línea del código fuente también se renderiza. */ ?>
            <div class="alert alert-error"><strong>Tu solicitud fue rechazada.</strong><?= $motivo !== '' ? "\n" . htmlspecialchars($motivo) : '' ?><?= "\n" ?>Corregí lo que haga falta y volvé a mandarla.</div>
        <?php elseif ($estado === 'pendiente'): ?>
            <p><span class="tag">Pendiente de revisión</span></p>
            <p class="field-hint">
                Ya recibimos tu solicitud<?php if (!empty($solicitud['created_at'])):
                    $fecha = date_create((string) $solicitud['created_at']); ?>
                    <?= $fecha ? ' del ' . htmlspecialchars($fecha->format('d/m/Y')) : '' ?>
                <?php endif; ?>. Mientras nadie la revise podés editarla y volver a enviarla.
            </p>
        <?php endif; ?>

        <form class="auth-form" method="post" novalidate data-loading-form>
            <?= Csrf::field() ?>

            <div class="field<?= isset($errors['instagram']) ? ' has-error' : '' ?>">
                <label for="instagram">Instagram <span class="numeric">(opcional)</span></label>
                <input type="text" id="instagram" name="instagram" autocomplete="off"
                       maxlength="<?= VERIF_MAX_INSTAGRAM ?>" placeholder="@tuusuario"
                       value="<?= htmlspecialchars($instagram, ENT_QUOTES) ?>"
                       <?= isset($errors['instagram']) ? 'aria-invalid="true" aria-describedby="instagram-err"' : '' ?>>
                <p class="field-hint">Tu usuario o el link a tu perfil. Sirve si ahí se te ve con el club.</p>
                <p class="field-error" id="instagram-err" role="alert"><?= htmlspecialchars($errors['instagram'] ?? '') ?></p>
            </div>

            <div class="field<?= isset($errors['numero_socio']) ? ' has-error' : '' ?>">
                <label for="numero_socio">Número de socio <span class="numeric">(opcional)</span></label>
                <input type="text" id="numero_socio" name="numero_socio" autocomplete="off"
                       maxlength="<?= VERIF_MAX_SOCIO ?>"
                       value="<?= htmlspecialchars($numeroSocio, ENT_QUOTES) ?>"
                       <?= isset($errors['numero_socio']) ? 'aria-invalid="true" aria-describedby="numero_socio-err"' : '' ?>>
                <p class="field-error" id="numero_socio-err" role="alert"><?= htmlspecialchars($errors['numero_socio'] ?? '') ?></p>
            </div>

            <div class="field<?= isset($errors['foto_url']) ? ' has-error' : '' ?>">
                <label for="foto_url">Link a una foto <span class="numeric">(opcional)</span></label>
                <input type="url" id="foto_url" name="foto_url" autocomplete="off"
                       maxlength="<?= VERIF_MAX_URL ?>" placeholder="https://…"
                       value="<?= htmlspecialchars($fotoUrl, ENT_QUOTES) ?>"
                       <?= isset($errors['foto_url']) ? 'aria-invalid="true" aria-describedby="foto_url-err"' : '' ?>>
                <p class="field-hint">Una foto tuya con la camiseta, subida a donde uses (Drive, Instagram, lo que sea). Acá no se suben archivos: pegá el link.</p>
                <p class="field-error" id="foto_url-err" role="alert"><?= htmlspecialchars($errors['foto_url'] ?? '') ?></p>
            </div>

            <div class="field<?= isset($errors['nota']) ? ' has-error' : '' ?>">
                <label for="nota">Nota para quien revise <span class="numeric">(opcional)</span></label>
                <textarea id="nota" name="nota" maxlength="<?= VERIF_MAX_NOTA ?>"
                          placeholder="Ej: soy el PF del plantel superior, me conoce Martín."
                          <?= isset($errors['nota']) ? 'aria-invalid="true" aria-describedby="nota-err"' : '' ?>><?= htmlspecialchars($nota) ?></textarea>
                <p class="field-error" id="nota-err" role="alert"><?= htmlspecialchars($errors['nota'] ?? '') ?></p>
            </div>

            <button class="btn auth-submit btn-swap-label" type="submit">
                <span class="btn-spinner" aria-hidden="true"></span>
                <span class="btn-label"><?= $solicitud ? 'Volver a enviar' : 'Enviar solicitud' ?></span>
                <span class="btn-loading-label">Enviando…</span>
            </button>
        </form>

        <p class="auth-legal">
            Mientras tanto no vas a ver datos de ningún club. Si te equivocaste de club, avisalo en la nota:
            quien revise te lo va a rechazar y podés volver a empezar.
        </p>

        <?php /* Cerrar sesión va por POST con token: un logout por GET lo dispara cualquier
                 <img src="/logout.php"> de un tercero. */ ?>
        <div class="auth-meta">
            <span><?= htmlspecialchars($user['email']) ?></span>
            <form method="post" action="logout.php">
                <?= Csrf::field() ?>
                <button class="btn btn-secondary btn-sm" type="submit">Cerrar sesión</button>
            </form>
        </div>
    </section>
</main>
<script src="<?= asset('js/auth.js') ?>"></script>
</body>
</html>
