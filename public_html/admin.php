<?php
/**
 * Solicitudes de acceso + habilitaciones por categoría.
 *
 * Dos secciones en una sola pantalla, y están juntas a propósito:
 *
 *   1. LA COLA — aprobar o rechazar altas. Al aprobar se eligen, EN EL MISMO FORMULARIO, las
 *      categorías de datos que la persona va a poder cargar. Si eso fuera un paso aparte, la
 *      realidad sería que se aprueba gente y nadie vuelve nunca a habilitarla: el kinesiólogo
 *      entra, no puede subir su planilla, y termina pidiendo que lo hagan administrador — que es
 *      exactamente lo que el eje de categorías vino a evitar.
 *
 *   2. MIEMBROS DEL CLUB — la misma decisión, editable después del alta. Sin esta lista la
 *      función solo existiría durante los treinta segundos de la aprobación: no habría forma de
 *      dársela al que ya está adentro, ni de quitársela a quien cambió de función.
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
require_once __DIR__ . '/app/Categorias.php';
require_once __DIR__ . '/app/CategoryPermission.php';
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
 * ¿Existe `user_categorias` en ESTA base?
 *
 * La migración que la crea (sql/migration_2026_07_kinesiologia.sql) se corre a mano por
 * phpMyAdmin, así que el deploy del PHP y el de la tabla no son el mismo evento y el orden no
 * está garantizado. Sin este sondeo, la ventana entre los dos sería una pantalla de administración
 * que responde 500: nadie podría aprobar a nadie hasta que alguien entrara a la base — y el que
 * tiene que entrar es justamente quien está mirando esta pantalla rota.
 *
 * Con el sondeo, la degradación es exactamente la que ya hace CategoryPermission::grants(): la
 * pantalla sigue aprobando y rechazando como antes de esta función, y lo único que desaparece son
 * los controles de habilitación, con el motivo escrito. Una consulta por request, sin JOIN.
 *
 * Se usa como condición de RENDER y de ESCRITURA: si las habilitaciones no se pueden guardar,
 * tampoco se ofrecen. Ofrecerlas y perderlas en silencio sería peor que no tenerlas.
 */
$grantsDisponibles = true;
try {
    $pdo->query('SELECT 1 FROM user_categorias LIMIT 1');
} catch (PDOException $e) {
    $grantsDisponibles = false;
}

if (!function_exists('adminCategoriasDelPost')) {
    /**
     * `categorias[]` del POST → lista de claves válidas, sin repetidos y en orden de presentación.
     *
     * Filtrar contra el catálogo acá no es cosmético: lo que se está por escribir es la LISTA
     * BLANCA que después decide permisos. Un valor de más en el POST tiene que desaparecer antes
     * del INSERT, no confiar en que el ENUM de MySQL lo rechace (rechazarlo abortaría la
     * transacción entera de la aprobación por culpa de un checkbox inventado).
     *
     * @return string[]
     */
    function adminCategoriasDelPost(): array
    {
        $crudas = $_POST['categorias'] ?? [];
        if (!is_array($crudas)) {
            return [];
        }

        $pedidas = [];
        foreach ($crudas as $c) {
            if (is_string($c) || is_int($c)) {
                $pedidas[(string) $c] = true;
            }
        }

        // Se recorre el catálogo y no el POST: así el orden es siempre el de presentación y las
        // claves inválidas quedan afuera por construcción.
        return array_values(array_filter(
            Categorias::todas(),
            static fn (string $c): bool => isset($pedidas[$c])
        ));
    }
}

if (!function_exists('adminGuardarGrants')) {
    /**
     * Deja las habilitaciones de un usuario EXACTAMENTE en $categorias.
     *
     * Se llama siempre dentro de una transacción abierta por quien llama: las habilitaciones y la
     * aprobación son una sola decisión, y media aplicada es un estado que nadie sabría reparar.
     *
     * DELETE de lo que sobra + INSERT IGNORE de lo que falta, en vez de "borrar todo y reinsertar":
     * así una fila que ya estaba conserva su `otorgada_por` y su `created_at` originales — quién
     * dio un permiso y cuándo es la única auditoría que hay de esta tabla, y reescribirla en cada
     * guardado la borraría. La PK compuesta (user_id, categoria) hace las dos operaciones
     * idempotentes: guardar dos veces lo mismo no cambia nada.
     *
     * NO valida permisos ni club: eso ya se decidió sobre la fila leída con FOR UPDATE.
     *
     * @param string[] $categorias Claves ya validadas contra el catálogo.
     */
    function adminGuardarGrants(PDO $pdo, int $userId, array $categorias, int $otorgante): void
    {
        if ($categorias === []) {
            $pdo->prepare('DELETE FROM user_categorias WHERE user_id = :u')->execute(['u' => $userId]);
            return;
        }

        // Placeholders por cantidad: la cadena interpolada son solo '?' y comas, nunca datos.
        $ph  = implode(',', array_fill(0, count($categorias), '?'));
        $del = $pdo->prepare("DELETE FROM user_categorias WHERE user_id = ? AND categoria NOT IN ($ph)");
        $del->execute(array_merge([$userId], $categorias));

        $ins = $pdo->prepare(
            'INSERT IGNORE INTO user_categorias (user_id, categoria, otorgada_por) VALUES (:u, :c, :by)'
        );
        foreach ($categorias as $c) {
            $ins->execute(['u' => $userId, 'c' => $c, 'by' => $otorgante]);
        }
    }
}

if (!function_exists('adminListaCategorias')) {
    /** ['fuerza','nutricion'] → "Fuerza y Nutrición". Para los mensajes de resultado. */
    function adminListaCategorias(array $categorias): string
    {
        $labels = array_map(static fn (string $c): string => Categorias::label($c), $categorias);
        if (count($labels) <= 1) {
            return (string) ($labels[0] ?? '');
        }

        $ultima = array_pop($labels);
        return implode(', ', $labels) . ' y ' . $ultima;
    }
}

/**
 * POST → escribir → redirect (PRG). La cola es una pantalla en la que se recarga y se vuelve
 * atrás todo el tiempo; si el render fuera la respuesta directa del POST, un F5 reintentaría la
 * aprobación. El resultado viaja en un flash de sesión.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = null;
    // Ancla del redirect. La lista de miembros vive al final de una pantalla larga: sin esto,
    // guardar una habilitación te devuelve arriba de todo y hay que volver a buscar la fila.
    $anchor = '';

    if (!Csrf::check(Csrf::fromRequest())) {
        $flash = ['tipo' => 'error', 'texto' => 'La página estuvo abierta demasiado tiempo. Recargá e intentá de nuevo.'];
    } elseif ((string) ($_POST['accion'] ?? '') === 'grants') {
        // ── Editar las habilitaciones de un miembro YA aprobado ──────────────────────────────
        $anchor   = '#miembros';
        $targetId = (int) ($_POST['user_id'] ?? 0);
        $cats     = adminCategoriasDelPost();

        if (!$grantsDisponibles) {
            $flash = ['tipo' => 'error', 'texto' => 'Las habilitaciones por categoría todavía no están activas en esta base.'];
        } elseif ($targetId <= 0) {
            $flash = ['tipo' => 'error', 'texto' => 'Acción no reconocida.'];
        } elseif ($targetId === $miId) {
            // Nadie se toca sus propias habilitaciones, ni siquiera el superadmin. Es la misma
            // regla que "nadie aprueba su propia solicitud": un permiso que uno se puede dar solo
            // no es un permiso. La UI ya no dibuja el formulario para uno mismo; esto es el
            // control, porque el user_id viaja en el POST.
            $flash = ['tipo' => 'error', 'texto' => 'No podés cambiar tus propias habilitaciones: eso lo hace otro administrador.'];
        } else {
            try {
                $pdo->beginTransaction();

                // FOR UPDATE por el mismo motivo que en la cola: dos administradores del club
                // editando a la misma persona a la vez es normal, y sin lock el segundo decide
                // sobre un `nivel` y un `club_id` que leyó antes de que el primero escribiera.
                $stmt = $pdo->prepare(
                    'SELECT id, nombre, club_id, nivel, status FROM users WHERE id = :id LIMIT 1 FOR UPDATE'
                );
                $stmt->execute(['id' => $targetId]);
                $target = $stmt->fetch();

                if (!$target) {
                    throw new RuntimeException('Ese usuario ya no existe.');
                }

                // IDOR — el control de acceso de esta pantalla. El listado de abajo ya filtra por
                // club, pero el filtro de un listado no autoriza nada: `user_id` viaja en el POST
                // y se cambia a mano. La decisión se toma acá, sobre el club REAL de la fila.
                if (!$esSuper && (int) $target['club_id'] !== $miClub) {
                    throw new RuntimeException('Ese usuario es de otro club.');
                }

                if ($target['status'] !== 'active') {
                    throw new RuntimeException('Esa cuenta todavía no está aprobada: las habilitaciones se asignan al aprobarla.');
                }

                // Un admin_club/superadmin puede todas las categorías por `nivel`, sin filas.
                // Escribirle grants sería guardar algo que no se lee nunca y sugerir, la próxima
                // vez que alguien mire, que tiene solo esas.
                if ($target['nivel'] !== 'miembro') {
                    throw new RuntimeException('Quien administra el club ya puede trabajar con todas las categorías: no hay nada que habilitarle.');
                }

                adminGuardarGrants($pdo, $targetId, $cats, $miId);

                $pdo->commit();
                $flash = ['tipo' => 'success', 'texto' => $cats === []
                    ? 'A ' . $target['nombre'] . ' no le queda ninguna categoría habilitada: sigue viendo todo lo del club, pero no puede cargar datos.'
                    : $target['nombre'] . ' queda habilitado en ' . adminListaCategorias($cats) . '.'];
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flash = ['tipo' => 'error', 'texto' => $e->getMessage()];
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $flash = ['tipo' => 'error', 'texto' => 'No pudimos guardar las habilitaciones. Probá de nuevo.'];
            }
        }
    } else {
        $accion = (string) ($_POST['accion'] ?? '');
        $vid    = (int) ($_POST['verificacion_id'] ?? 0);
        $motivo = trim((string) ($_POST['motivo'] ?? ''));
        $cats   = adminCategoriasDelPost();

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

                    // Las habilitaciones van en la MISMA transacción que la aprobación. Si se
                    // guardaran después, un fallo en el segundo paso dejaría a la persona adentro
                    // y en solo lectura sin que nadie se entere: el alta se vería exitosa y el
                    // problema aparecería recién el día que intente subir su primer CSV.
                    //
                    // Al promover a administrador no se escribe ninguna: puede todas por `nivel`
                    // (ver CategoryPermission) y el formulario ni siquiera las ofrece.
                    $catsOtorgadas = [];
                    if (!$promover && $grantsDisponibles && $cats !== []) {
                        adminGuardarGrants($pdo, (int) $sol['user_id'], $cats, $miId);
                        $catsOtorgadas = $cats;
                    }

                    if ($promover) {
                        $texto = $sol['nombre'] . ' quedó aprobado y es administrador de su club: desde ahora aprueba al resto y puede trabajar con todas las categorías.';
                    } elseif ($catsOtorgadas !== []) {
                        $texto = 'Aprobaste a ' . $sol['nombre'] . ', habilitado en ' . adminListaCategorias($catsOtorgadas) . '.';
                    } else {
                        $texto = 'Aprobaste a ' . $sol['nombre'] . '. Sin categorías habilitadas: ve todo lo del club pero no puede cargar datos. Se las podés dar cuando quieras en «Miembros del club», más abajo.';
                    }
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
    header('Location: admin.php' . $anchor);
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

// ── Miembros ya aprobados ────────────────────────────────────────────────────────────────────
//
// Solo `status='active'`: los 'pending' son la cola de arriba (todavía no hay a quién habilitar)
// y los 'rechazado' no entran a la app. FIELD() ordena por jerarquía real y no alfabéticamente:
// quien administra el club es el contexto de la lista y va primero.
//
// Mismo scoping que la cola: el admin_club ve SOLO su club. Es un listado de nombres y mails.
$sqlM = 'SELECT u.id, u.nombre, u.email, u.rol, u.nivel, u.club_id, c.nombre AS club_nombre
           FROM users u
           INNER JOIN clubs c ON c.id = u.club_id
          WHERE u.status = \'active\'';
$paramsM = [];
if (!$esSuper) {
    $sqlM .= ' AND u.club_id = :cid';
    $paramsM['cid'] = $miClub;
}
$sqlM .= " ORDER BY c.nombre, FIELD(u.nivel, 'superadmin', 'admin_club', 'miembro'), u.nombre, u.id";

$stmt = $pdo->prepare($sqlM);
$stmt->execute($paramsM);
$miembros = $stmt->fetchAll();

/**
 * Habilitaciones de TODOS los miembros listados, en una sola consulta.
 *
 * Una query por miembro sería N+1 sobre una pantalla que ya lista el club entero. El IN va sobre
 * ids que salieron del SELECT de arriba —ya scopeado por club—, no del cliente.
 *
 * @var array<int,array<string,true>> $grantsPorUsuario
 */
$grantsPorUsuario = [];
if ($grantsDisponibles && $miembros) {
    $idsM = array_map(static fn (array $m): int => (int) $m['id'], $miembros);
    $ph   = implode(',', array_fill(0, count($idsM), '?'));
    try {
        $q = $pdo->prepare("SELECT user_id, categoria FROM user_categorias WHERE user_id IN ($ph)");
        $q->execute($idsM);
        foreach ($q->fetchAll() as $row) {
            $grantsPorUsuario[(int) $row['user_id']][(string) $row['categoria']] = true;
        }
    } catch (PDOException $e) {
        // La tabla existía al sondearla y falló acá: mismo criterio que CategoryPermission, se
        // degrada a "no hay habilitaciones que mostrar" en vez de tirar la pantalla abajo.
        $grantsDisponibles = false;
    }
}

$gruposMiembros = [];
foreach ($miembros as $m) {
    $gruposMiembros[(int) $m['club_id']]['nombre'] = (string) $m['club_nombre'];
    $gruposMiembros[(int) $m['club_id']]['filas'][] = $m;
}

/** Etiquetas del catálogo, una sola vez para todos los formularios de la pantalla. */
$catLabels = Categorias::labels();

if (!function_exists('adminChipsCategorias')) {
    /**
     * Grupo de checkboxes de categorías.
     *
     * Reutiliza .category-picker / .category-chip del uploader de steps/datos.php sin tocarlas:
     * el CSS engancha por `input:checked + span`, que funciona igual para radio y para checkbox.
     * Que el control de "qué categorías te habilito" se vea idéntico al de "en qué categoría subo
     * este CSV" no es casualidad: son las dos caras del mismo permiso.
     *
     * `role="group"` + `aria-labelledby`: sin eso, un lector de pantalla anuncia seis casillas
     * sueltas sin decir de qué son.
     *
     * @param array<string,string> $labels    Catálogo clave => etiqueta.
     * @param array<string,true>   $marcadas  Habilitaciones actuales.
     */
    function adminChipsCategorias(array $labels, array $marcadas, string $idBase): string
    {
        $out = '<div class="category-picker" role="group" aria-labelledby="' . $idBase . '-label">';
        foreach ($labels as $key => $label) {
            $out .= '<label class="category-chip">'
                . '<input type="checkbox" name="categorias[]" value="' . htmlspecialchars($key, ENT_QUOTES) . '"'
                . (isset($marcadas[$key]) ? ' checked' : '') . '>'
                . '<span>' . htmlspecialchars($label) . '</span>'
                . '</label>';
        }

        return $out . '</div>';
    }
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
            <?php /* Atajo a la segunda sección: con la cola llena queda a varias pantallas de
                     scroll, y el caso "ya lo aprobé y ahora necesito habilitarlo" es el más
                     frecuente de los dos. */ ?>
            <a href="#miembros">Ir a Miembros del club ↓</a>
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
                        <?php /* Las categorías van DENTRO del formulario de aprobación, no en un
                                 paso posterior: un segundo formulario "y ahora habilitalo" se
                                 saltea, y el resultado es gente aprobada que no puede cargar nada.
                                 Nacen todas destildadas — el `rol` de arriba es texto que escribió
                                 el propio solicitante ("kinesiologo", "Kinesiólogo/a", "kine") y
                                 pre-tildar a partir de eso sería derivar un permiso de la
                                 ortografía de quien lo pide. Lo lee el administrador y decide. */ ?>
                        <form method="post" data-loading-form>
                            <?= Csrf::field() ?>
                            <input type="hidden" name="verificacion_id" value="<?= $vid ?>">
                            <input type="hidden" name="accion" value="<?= $promocion ? 'aprobar_admin' : 'aprobar' ?>">

                            <?php if ($promocion): ?>
                                <p class="dataset-empty-note">Como administrador del club va a poder trabajar con todas las categorías: no hace falta habilitárselas una por una.</p>
                            <?php elseif (!$grantsDisponibles): ?>
                                <p class="dataset-empty-note">Las habilitaciones por categoría todavía no están activas en esta base: por ahora se aprueba sin ellas.</p>
                            <?php else: ?>
                                <div class="field">
                                    <label id="cat-v<?= $vid ?>-label">Categorías que le habilitás</label>
                                    <?= adminChipsCategorias($catLabels, [], 'cat-v' . $vid) ?>
                                    <p class="field-hint">Con una categoría habilitada puede subir y editar esos datos sin ser administrador del club. Sin ninguna entra en modo lectura: ve todo, no carga nada. Se cambian después en «Miembros del club».</p>
                                </div>
                            <?php endif; ?>

                            <div class="btn-row">
                                <button class="btn btn-swap-label" type="submit">
                                    <span class="btn-spinner" aria-hidden="true"></span>
                                    <span class="btn-label"><?= $promocion ? 'Aprobar y hacer administrador del club' : 'Aprobar' ?></span>
                                    <span class="btn-loading-label">Aprobando…</span>
                                </button>
                            </div>
                        </form>

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

    <!-- ══ Miembros del club ═══════════════════════════════════════════════════════════════ -->
    <div class="page-header" id="miembros">
        <h2 class="page-title">Miembros del club</h2>
        <p class="page-sub">
            <?php if ($esSuper): ?>
                Cuentas activas de todos los clubes. Una categoría habilitada deja cargar y editar
                esos datos sin ser administrador: es lo que hace que el kinesiólogo suba su planilla
                sin llevarse puesto el resto del club.
            <?php else: ?>
                Cuentas activas de <?= htmlspecialchars($miClubNombre) ?>. Una categoría habilitada
                deja cargar y editar esos datos sin ser administrador: es lo que hace que el
                kinesiólogo suba su planilla sin llevarse puesto el resto del club.
            <?php endif; ?>
        </p>
    </div>

    <?php if (!$grantsDisponibles): ?>
        <div class="card">
            <div class="empty-state">
                Las habilitaciones por categoría todavía no están activas en esta base. Hasta que se
                corran las migraciones pendientes, un miembro ve todo lo de su club y solo un
                administrador puede cargar datos — que es como funcionaba hasta ahora.
            </div>
        </div>
    <?php elseif (!$gruposMiembros): ?>
        <div class="card">
            <div class="empty-state">Todavía no hay ninguna cuenta activa.</div>
        </div>
    <?php endif; ?>

    <?php if ($grantsDisponibles): ?>
        <?php foreach ($gruposMiembros as $clubIdM => $grupoM): ?>
            <div class="dataset-group">
                <div class="dataset-group-head">
                    <span class="dataset-group-title"><?= htmlspecialchars($grupoM['nombre']) ?></span>
                    <span class="dataset-group-count"><?= count($grupoM['filas']) ?></span>
                </div>

                <div class="card">
                    <?php foreach ($grupoM['filas'] as $m):
                        $mid       = (int) $m['id'];
                        $esAdminM  = $m['nivel'] !== 'miembro';
                        $soyYo     = $mid === $miId;
                        $misGrants = $grantsPorUsuario[$mid] ?? [];
                    ?>
                        <div class="recon-dataset">
                            <div class="recon-head">
                                <div>
                                    <div class="dataset-name">
                                        <?= htmlspecialchars($m['nombre']) ?>
                                        <?php if ($soyYo): ?><span class="tag">vos</span><?php endif; ?>
                                        <?php if (!empty($m['rol'])): ?><span class="tag"><?= htmlspecialchars($m['rol']) ?></span><?php endif; ?>
                                    </div>
                                    <div class="dataset-meta"><?= htmlspecialchars($m['email']) ?></div>
                                </div>
                                <?php /* `nivel` es el permiso real; `rol` de al lado del nombre es la
                                         etiqueta libre del perfil. Se distinguen por forma a propósito:
                                         .badge para el que decide, .tag para el que solo describe. */ ?>
                                <?php if ($m['nivel'] === 'superadmin'): ?>
                                    <span class="badge badge-forward">Superadmin</span>
                                <?php elseif ($m['nivel'] === 'admin_club'): ?>
                                    <span class="badge badge-back">Administra el club</span>
                                <?php endif; ?>
                            </div>

                            <?php if ($soyYo): ?>
                                <?php /* Sobre uno mismo no se dibuja el formulario. Hoy quien mira
                                         esta pantalla es siempre admin y caería igual en el caso de
                                         abajo, pero la regla que importa es "nadie se toca sus
                                         propias habilitaciones" y tiene que estar escrita como tal,
                                         no depender de que las dos condiciones coincidan. El POST
                                         lo rechaza igual: acá solo se evita ofrecerlo. */ ?>
                                <p class="dataset-empty-note">
                                    Sos vos: administrás el club, así que podés trabajar con todas las categorías.
                                    Tus propias habilitaciones te las cambia otro administrador.
                                </p>
                            <?php elseif ($esAdminM): ?>
                                <?php /* Se dice explícito que puede todas y no se muestran seis
                                         casillas vacías: un formulario en blanco al lado de un
                                         administrador se lee como "no tiene ningún permiso", que es
                                         exactamente lo contrario de la verdad. */ ?>
                                <p class="dataset-empty-note">
                                    Puede trabajar con todas las categorías por su nivel de acceso, sin habilitaciones.
                                </p>
                            <?php else: ?>
                                <form method="post" data-loading-form>
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="accion" value="grants">
                                    <input type="hidden" name="user_id" value="<?= $mid ?>">
                                    <div class="field">
                                        <label id="cat-u<?= $mid ?>-label">Categorías habilitadas</label>
                                        <?= adminChipsCategorias($catLabels, $misGrants, 'cat-u' . $mid) ?>
                                        <?php if (!$misGrants): ?>
                                            <p class="field-hint">Hoy no tiene ninguna: ve todos los datos del club y no puede cargar ni editar nada.</p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="btn-row">
                                        <button class="btn btn-sm btn-swap-label" type="submit">
                                            <span class="btn-spinner" aria-hidden="true"></span>
                                            <span class="btn-label">Guardar habilitaciones</span>
                                            <span class="btn-loading-label">Guardando…</span>
                                        </button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<script src="<?= asset('js/auth.js') ?>"></script>
</body>
</html>
