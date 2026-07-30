<?php
/**
 * Cola de solicitudes de acceso.
 *
 * Una sola pantalla que se scopea sola según quién la mire:
 *   - superadmin  → ve las pendientes de TODOS los clubes, agrupadas por club. Para un club que
 *                   todavía no tiene administrador, aprobar significa además designarlo: alguien
 *                   tiene que poder aprobar al resto, y ese alguien no puede ser el superadmin
 *                   para siempre.
 *   - admin_club  → ve SOLO las de su club, y no puede designar administradores.
 *   - miembro     → 404 (Auth::requireNivel).
 *
 * `users.rol` ("preparador físico") es una etiqueta descriptiva del perfil y no gatea nada. Lo
 * que decide permisos es `users.nivel`. Acá se muestra el rol como dato de contexto para la
 * decisión, nada más.
 *
 * Sobre la evidencia: la foto y el Instagram se muestran como ENLACE, nunca embebidos en un
 * <img>. Si se embebieran, el navegador del administrador pegaría un request automático a un
 * host que controla el solicitante, filtrándole la IP del admin y confirmándole el momento
 * exacto en que su solicitud fue mirada. Con un enlace, el request lo decide el administrador.
 * target="_blank" + rel="noopener noreferrer" para que la pestaña destino no acceda a
 * window.opener ni reciba el Referer de esta pantalla.
 */
require __DIR__ . '/app/bootstrap_page.php';
requireAuth();

// Primero el nivel: para un miembro esta URL tiene que ser un 404, no un redirect que le
// confirme que la pantalla existe.
Auth::requireNivel('admin_club', 'superadmin');

// Redundante con requireAuth() (que ya manda a verificacion.php a toda cuenta no aprobada), pero
// se deja explícito: `nivel` y `status` son dos ejes distintos y requireNivel solo mira el
// primero. Si mañana el guard cambia, una cuenta con nivel 'admin_club' pero suspendida sigue sin
// poder aprobar a nadie.
if (Auth::status() !== 'active') {
    header('Location: verificacion.php');
    exit;
}

$pageTitle   = 'Solicitudes de acceso — SportAnalysis';
$assetPrefix = '';

/** Tope del motivo de rechazo. Lo ve el solicitante en verificacion.php. */
const ADMIN_MAX_MOTIVO = 300;

if (!function_exists('verifUrlHttp')) {
    /**
     * ¿Es una URL http/https usable como href?
     *
     * Lista blanca de esquemas, explícita. FILTER_VALIDATE_URL por sí solo acepta
     * `javascript://x%0Aalert(1)` y `data:text/html,…`: emitir eso como href sería XSS
     * ejecutándose con la sesión del administrador, que es la cuenta más cara del sistema.
     * verificacion.php valida lo mismo al guardar; se revalida acá porque una fila puede venir
     * de antes de esa validación y el punto de emisión es el que importa.
     */
    function verifUrlHttp(string $url): bool
    {
        if ($url === '' || mb_strlen($url) > 500) {
            return false;
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }
        return (string) parse_url($url, PHP_URL_HOST) !== '';
    }
}

if (!function_exists('verifInstagramUrl')) {
    /** Usuario o link de Instagram → URL segura, o null si no se puede enlazar con confianza. */
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

if (!function_exists('adminFechaCorta')) {
    function adminFechaCorta(?string $ts): string
    {
        $ts = trim((string) $ts);
        if ($ts === '') {
            return '';
        }
        $d = date_create($ts);
        return $d ? $d->format('d/m/Y H:i') : '';
    }
}

$pdo     = Database::get();
$esSuper = Auth::esSuperadmin();
$miClub  = Auth::clubId();
$miId    = Auth::userId();

/**
 * POST → escribir → redirect (PRG). La cola es una pantalla en la que se recarga y se vuelve
 * atrás todo el tiempo; si el render fuera la respuesta directa del POST, un F5 reintentaría la
 * aprobación. El resultado viaja en un flash de sesión.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = null;

    if (!Csrf::check(Csrf::fromRequest())) {
        $flash = ['tipo' => 'error', 'texto' => 'La página estuvo abierta demasiado tiempo. Recargá e intentá de nuevo.'];
    } else {
        $accion = (string) ($_POST['accion'] ?? '');
        $vid    = (int) ($_POST['verificacion_id'] ?? 0);
        $motivo = trim((string) ($_POST['motivo'] ?? ''));

        if (!in_array($accion, ['aprobar', 'aprobar_admin', 'rechazar'], true) || $vid <= 0) {
            $flash = ['tipo' => 'error', 'texto' => 'Acción no reconocida.'];
        } elseif ($accion === 'rechazar' && $motivo === '') {
            $flash = ['tipo' => 'error', 'texto' => 'Para rechazar hace falta escribir el motivo: el solicitante lo va a leer y necesita saber qué corregir.'];
        } elseif ($accion === 'rechazar' && mb_strlen($motivo) > ADMIN_MAX_MOTIVO) {
            $flash = ['tipo' => 'error', 'texto' => 'El motivo no puede pasar de ' . ADMIN_MAX_MOTIVO . ' caracteres.'];
        } else {
            try {
                // Las dos escrituras (users + verificaciones) van juntas o no va ninguna: una
                // cuenta activa sin solicitud aprobada, o una solicitud aprobada con la cuenta
                // todavía pendiente, son dos estados que nadie sabría reparar después.
                $pdo->beginTransaction();

                // FOR UPDATE: dos administradores del mismo club mirando la cola a la vez es el
                // caso normal, no el raro. Sin el lock, los dos leen 'pendiente' y los dos
                // escriben, y el segundo pisa la decisión del primero.
                $stmt = $pdo->prepare(
                    'SELECT v.id, v.user_id, v.club_id, v.estado, u.nombre
                       FROM verificaciones v
                       INNER JOIN users u ON u.id = v.user_id
                      WHERE v.id = :id
                      LIMIT 1
                      FOR UPDATE'
                );
                $stmt->execute(['id' => $vid]);
                $sol = $stmt->fetch();

                if (!$sol) {
                    throw new RuntimeException('Esa solicitud ya no existe.');
                }
                if ($sol['estado'] !== 'pendiente') {
                    throw new RuntimeException('Esa solicitud ya fue revisada.');
                }

                // IDOR. El listado ya filtra por club, pero el filtro del listado no es un
                // control de acceso: el id de la solicitud viaja en el POST y se puede cambiar a
                // mano. La autorización se decide acá, sobre el club REAL de la fila leída.
                if (!$esSuper && (int) $sol['club_id'] !== $miClub) {
                    throw new RuntimeException('Esa solicitud es de otro club.');
                }

                // Nadie se aprueba a sí mismo, ni siquiera el superadmin.
                if ((int) $sol['user_id'] === $miId) {
                    throw new RuntimeException('No podés revisar tu propia solicitud.');
                }

                $promover = false;
                if ($accion === 'aprobar_admin') {
                    if (!$esSuper) {
                        throw new RuntimeException('Solo el superadmin puede designar administradores de club.');
                    }
                    // El "este club no tiene administrador" se recalcula del lado del servidor.
                    // Lo que decidió qué botón se dibujó fue un SELECT de hace un rato.
                    $q = $pdo->prepare(
                        "SELECT COUNT(*) FROM users
                          WHERE club_id = :cid AND nivel = 'admin_club' AND status = 'active'"
                    );
                    $q->execute(['cid' => (int) $sol['club_id']]);
                    if ((int) $q->fetchColumn() > 0) {
                        throw new RuntimeException('Ese club ya tiene administrador. Recargá la pantalla y aprobala como miembro.');
                    }
                    $promover = true;
                }

                if ($accion === 'rechazar') {
                    $up = $pdo->prepare("UPDATE users SET status = 'rechazado' WHERE id = :uid");
                    $up->execute(['uid' => (int) $sol['user_id']]);

                    $up = $pdo->prepare(
                        "UPDATE verificaciones
                            SET estado = 'rechazada', motivo_rechazo = :motivo,
                                revisada_por = :me, revisada_at = NOW()
                          WHERE id = :id"
                    );
                    $up->execute(['motivo' => $motivo, 'me' => $miId, 'id' => $vid]);

                    $texto = 'Rechazaste la solicitud de ' . $sol['nombre'] . '.';
                } else {
                    $up = $pdo->prepare(
                        $promover
                            ? "UPDATE users SET status = 'active', nivel = 'admin_club' WHERE id = :uid"
                            : "UPDATE users SET status = 'active' WHERE id = :uid"
                    );
                    $up->execute(['uid' => (int) $sol['user_id']]);

                    $up = $pdo->prepare(
                        "UPDATE verificaciones
                            SET estado = 'aprobada', motivo_rechazo = NULL,
                                revisada_por = :me, revisada_at = NOW()
                          WHERE id = :id"
                    );
                    $up->execute(['me' => $miId, 'id' => $vid]);

                    $texto = $promover
                        ? $sol['nombre'] . ' quedó aprobado y es administrador de su club: desde ahora aprueba al resto.'
                        : 'Aprobaste a ' . $sol['nombre'] . '.';
                }

                $pdo->commit();
                $flash = ['tipo' => 'success', 'texto' => $texto];
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flash = ['tipo' => 'error', 'texto' => $e->getMessage()];
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                // El detalle del error de base no se le muestra a nadie.
                $flash = ['tipo' => 'error', 'texto' => 'No pudimos guardar el cambio. Probá de nuevo.'];
            }
        }
    }

    $_SESSION['admin_flash'] = $flash;
    header('Location: admin.php');
    exit;
}

$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

$sql = 'SELECT v.id, v.user_id, v.club_id, v.instagram, v.numero_socio, v.foto_url, v.nota,
               v.created_at,
               u.nombre, u.email, u.rol,
               c.nombre AS club_nombre
          FROM verificaciones v
          INNER JOIN users u ON u.id = v.user_id
          INNER JOIN clubs c ON c.id = v.club_id
         WHERE v.estado = \'pendiente\'';
$params = [];
if (!$esSuper) {
    // Scoping del listado. La autorización de verdad está en el POST; esto es para no mostrarle
    // a un admin de club nombres y mails de gente de otros clubes.
    $sql .= ' AND v.club_id = :cid';
    $params['cid'] = $miClub;
}
$sql .= ' ORDER BY c.nombre, v.created_at, v.id';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$solicitudes = $stmt->fetchAll();

// Clubes de la cola que todavía no tienen administrador activo. Solo le sirve al superadmin:
// es el único que puede designar.
$sinAdmin = [];
if ($esSuper && $solicitudes) {
    $ids = array_values(array_unique(array_map(static fn(array $r): int => (int) $r['club_id'], $solicitudes)));
    // Placeholders generados por cantidad: la cadena interpolada son solo '?' separados por
    // comas, nunca datos.
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $q  = $pdo->prepare(
        "SELECT club_id FROM users
          WHERE nivel = 'admin_club' AND status = 'active' AND club_id IN ($ph)
          GROUP BY club_id"
    );
    $q->execute($ids);
    $conAdmin = array_map('intval', array_column($q->fetchAll(), 'club_id'));
    foreach ($ids as $id) {
        if (!in_array($id, $conAdmin, true)) {
            $sinAdmin[$id] = true;
        }
    }
}

$miClubNombre = (string) (Auth::club()['nombre'] ?? 'tu club');

// Agrupado por club preservando el orden del ORDER BY.
$grupos = [];
foreach ($solicitudes as $s) {
    $grupos[(int) $s['club_id']]['nombre'] = (string) $s['club_nombre'];
    $grupos[(int) $s['club_id']]['filas'][] = $s;
}

require __DIR__ . '/app/views/head.php';
$appbarAction = ['href' => 'steps/analysis.php', 'label' => 'Ir a SportAnalysis', 'icon' => '→', 'primary' => true];
require __DIR__ . '/app/views/appbar.php';
?>
<div class="page page-wide">
    <div class="page-header">
        <h1 class="page-title">Solicitudes de acceso</h1>
        <p class="page-sub">
            <?php if ($esSuper): ?>
                Cuentas pendientes de todos los clubes. En un club sin administrador, aprobar también
                lo designa: de ahí en adelante las solicitudes de ese club las revisa esa persona.
            <?php else: ?>
                Cuentas pendientes de <?= htmlspecialchars($miClubNombre) ?>.
                Aprobás a quien reconocés; si no reconocés a alguien, rechazalo explicando por qué.
            <?php endif; ?>
        </p>
    </div>

    <div class="form-status" role="status" aria-live="polite">
        <?php if (is_array($flash) && !empty($flash['texto'])): ?>
            <div class="alert alert-<?= ($flash['tipo'] ?? '') === 'success' ? 'success' : 'error' ?>"><?= htmlspecialchars((string) $flash['texto']) ?></div>
        <?php endif; ?>
    </div>

    <?php if (!$grupos): ?>
        <div class="card">
            <div class="empty-state">No hay solicitudes pendientes.</div>
        </div>
    <?php endif; ?>

    <?php foreach ($grupos as $clubIdG => $grupo): ?>
        <div class="dataset-group">
            <div class="dataset-group-head">
                <span class="dataset-group-title"><?= htmlspecialchars($grupo['nombre']) ?></span>
                <span class="dataset-group-count"><?= count($grupo['filas']) ?></span>
            </div>

            <?php if ($esSuper && isset($sinAdmin[$clubIdG])): ?>
                <p class="dataset-empty-note">Este club todavía no tiene administrador. A quien apruebes acá le queda ese rol.</p>
            <?php endif; ?>

            <?php foreach ($grupo['filas'] as $s):
                $vid       = (int) $s['id'];
                $igUrl     = verifInstagramUrl((string) ($s['instagram'] ?? ''));
                $fotoRaw   = (string) ($s['foto_url'] ?? '');
                $fotoOk    = $fotoRaw !== '' && verifUrlHttp($fotoRaw);
                $promocion = $esSuper && isset($sinAdmin[$clubIdG]);
                $propia    = (int) $s['user_id'] === $miId;
            ?>
                <div class="card">
                    <div class="card-title">
                        <?= htmlspecialchars($s['nombre']) ?>
                        <?php if (!empty($s['rol'])): ?><span class="tag"><?= htmlspecialchars($s['rol']) ?></span><?php endif; ?>
                    </div>
                    <div class="card-sub">
                        <?= htmlspecialchars($s['email']) ?> · <?= htmlspecialchars($grupo['nombre']) ?>
                        · solicitó el <?= htmlspecialchars(adminFechaCorta($s['created_at'])) ?>
                    </div>

                    <div class="table-scroll">
                        <table class="data-table">
                            <tbody>
                                <tr>
                                    <th scope="row">Instagram</th>
                                    <td>
                                        <?php if ($igUrl !== null): ?>
                                            <a href="<?= htmlspecialchars($igUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($s['instagram']) ?></a>
                                        <?php elseif (!empty($s['instagram'])): ?>
                                            <?= htmlspecialchars($s['instagram']) ?> <span class="badge badge-unmatched">sin enlace</span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Nº de socio</th>
                                    <td><?= !empty($s['numero_socio']) ? htmlspecialchars($s['numero_socio']) : '—' ?></td>
                                </tr>
                                <tr>
                                    <th scope="row">Foto</th>
                                    <td>
                                        <?php if ($fotoOk): ?>
                                            <?php /* Enlace, no <img>: embeberla filtraría tu IP al host del solicitante
                                                     y le confirmaría el momento en que miraste su solicitud. */ ?>
                                            <a href="<?= htmlspecialchars($fotoRaw, ENT_QUOTES) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($fotoRaw) ?></a>
                                        <?php elseif ($fotoRaw !== ''): ?>
                                            <?= htmlspecialchars($fotoRaw) ?> <span class="badge badge-unmatched">link no válido</span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">Nota</th>
                                    <td><?= !empty($s['nota']) ? nl2br(htmlspecialchars($s['nota'])) : '—' ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($propia): ?>
                        <p class="dataset-empty-note">Esta es tu propia solicitud: la tiene que revisar otra persona.</p>
                    <?php else: ?>
                        <div class="btn-row">
                            <form method="post" data-loading-form>
                                <?= Csrf::field() ?>
                                <input type="hidden" name="verificacion_id" value="<?= $vid ?>">
                                <input type="hidden" name="accion" value="<?= $promocion ? 'aprobar_admin' : 'aprobar' ?>">
                                <button class="btn btn-swap-label" type="submit">
                                    <span class="btn-spinner" aria-hidden="true"></span>
                                    <span class="btn-label"><?= $promocion ? 'Aprobar y hacer administrador del club' : 'Aprobar' ?></span>
                                    <span class="btn-loading-label">Aprobando…</span>
                                </button>
                            </form>
                        </div>

                        <form method="post" novalidate data-loading-form>
                            <?= Csrf::field() ?>
                            <input type="hidden" name="verificacion_id" value="<?= $vid ?>">
                            <input type="hidden" name="accion" value="rechazar">
                            <div class="field">
                                <label for="motivo-<?= $vid ?>">Motivo del rechazo</label>
                                <input type="text" id="motivo-<?= $vid ?>" name="motivo" maxlength="<?= ADMIN_MAX_MOTIVO ?>"
                                       autocomplete="off" placeholder="Ej: no te reconocemos en el plantel.">
                                <p class="field-hint">Obligatorio: es lo único que el solicitante va a leer.</p>
                            </div>
                            <div class="btn-row">
                                <button class="btn btn-danger btn-sm" type="submit">Rechazar</button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div>
<script src="<?= asset('js/auth.js') ?>"></script>
</body>
</html>
