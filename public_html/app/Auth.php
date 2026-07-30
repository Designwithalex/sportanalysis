<?php
/**
 * Sesión de usuario.
 *
 * Todo estático a propósito: los 13 endpoints de api/ están escritos como funciones sueltas
 * (`handleSave(PDO $pdo)`) que no ven el scope global, así que no hay dónde inyectar una
 * instancia. `Auth::clubId()` se puede llamar desde cualquier punto del request.
 *
 * En $_SESSION vive SOLO el id del usuario. El resto (club, nivel, nombre, status) se relee de
 * la base una vez por request y se cachea en memoria. Así, si a alguien le cambian el club o
 * le pasan el status a 'pending', la sesión abierta no queda con datos rancios: el cambio
 * pega en la request siguiente sin tener que invalidar sesiones a mano.
 *
 * DOS COLUMNAS QUE NO HAY QUE CONFUNDIR:
 *   - `users.nivel`  ('miembro' | 'admin_club' | 'superadmin') — permisos reales. Es lo único
 *                    que gatea algo. Se lee con nivel() / esAdminClub() / requireNivel().
 *   - `users.rol`    — etiqueta libre de texto del perfil ("Preparador físico", "DT"). NO gatea
 *                    nada. Nunca uses `rol` para decidir un permiso.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Csrf.php';

final class Auth
{
    /** Única clave de $_SESSION que administra esta clase (más el token de Csrf). */
    private const SESSION_KEY = 'user_id';

    /** Longitud mínima de contraseña. También la valida registro.php y perfil.php. */
    public const MIN_PASSWORD = 8;

    /**
     * Hash descartable contra el que se verifica cuando el email no existe.
     * Sin esto, un email inexistente responde en microsegundos y uno existente en ~100ms:
     * esa diferencia alcanza para enumerar qué cuentas están registradas. Es un bcrypt real
     * de una contraseña aleatoria; nunca va a matchear nada.
     */
    private const DUMMY_HASH = '$2y$10$usesomesillystringfore.J8mZ.Yf/pDhLu5Q9Vp5oGZbCJhqXq';

    /** Cache del usuario por request. null + $loaded=true significa "no hay sesión válida". */
    private static ?array $cache = null;
    private static bool $loaded = false;

    /**
     * Arranca la sesión si nadie la arrancó todavía.
     *
     * En web nunca hace nada: bootstrap.php ya la abre con la cookie endurecida. Existe para
     * los contextos donde no pasa por bootstrap (scripts de CLI, tests).
     */
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /** Los tres niveles de permiso válidos. Cualquier otro valor se degrada a 'miembro'. */
    private const NIVELES = ['miembro', 'admin_club', 'superadmin'];

    /**
     * Verifica credenciales y, si son válidas, abre la sesión.
     *
     * Devuelve false por email inexistente o contraseña incorrecta, sin distinguirlos: quien
     * llama solo puede mostrar el mensaje genérico.
     *
     * NO corta por `status`. Un usuario 'pending' tiene que poder entrar: si le bloqueáramos el
     * login no tendría forma de ver en qué estado quedó su solicitud ni de corregirla; y un
     * 'rechazado' necesita entrar para leer que lo rechazaron. Qué puede *hacer* cada uno lo
     * deciden los guards de bootstrap_page.php / bootstrap_api.php, no el login.
     */
    public static function attempt(string $email, string $password): bool
    {
        $email = self::normalizeEmail($email);
        if ($email === '' || $password === '') {
            return false;
        }

        $stmt = Database::get()->prepare(
            'SELECT id, club_id, email, password_hash, nombre, rol, status
             FROM users WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();

        if (!$row) {
            password_verify($password, self::DUMMY_HASH);
            return false;
        }

        if (!password_verify($password, (string) $row['password_hash'])) {
            return false;
        }

        // Si el algoritmo default de PHP subió de costo (o cambió), aprovechamos que tenemos la
        // contraseña en claro acá y solo acá para re-hashear. Falla silenciosa: no vale tirar
        // abajo un login válido porque un UPDATE no entró.
        if (password_needs_rehash((string) $row['password_hash'], PASSWORD_DEFAULT)) {
            try {
                $up = Database::get()->prepare('UPDATE users SET password_hash = :h WHERE id = :id');
                $up->execute([
                    'h'  => password_hash($password, PASSWORD_DEFAULT),
                    'id' => (int) $row['id'],
                ]);
            } catch (PDOException $e) {
                // ignorado a propósito
            }
        }

        self::login($row);
        return true;
    }

    /**
     * Marca la sesión como autenticada. Asume que las credenciales ya se verificaron.
     *
     * session_regenerate_id(true) es obligatorio: sin eso, un id de sesión que el atacante
     * conozca de antes del login sigue siendo válido después (session fixation).
     */
    public static function login(array $user): void
    {
        $id = (int) ($user['id'] ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException('Auth::login() necesita un usuario con id.');
        }

        self::startSession();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION[self::SESSION_KEY] = $id;

        // El token emitido para la sesión anónima no sigue valiendo para la autenticada.
        Csrf::rotate();

        // Forzar la relectura: el cache puede tener al usuario anterior.
        self::$cache = null;
        self::$loaded = false;

        try {
            $stmt = Database::get()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
            $stmt->execute(['id' => $id]);
        } catch (PDOException $e) {
            // El login no depende de poder escribir la marca de tiempo.
        }
    }

    /**
     * Cierra la sesión: vacía los datos, borra la cookie del navegador y destruye el archivo.
     *
     * session_destroy() solo no alcanza: deja la cookie viva en el navegador, que la sigue
     * mandando en cada request.
     */
    public static function logout(): void
    {
        self::$cache = null;
        self::$loaded = true; // "ya resuelto: no hay usuario"

        self::startSession();
        $_SESSION = [];

        if (ini_get('session.use_cookies') && session_status() === PHP_SESSION_ACTIVE) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'] ?: '/',
                'domain'   => $p['domain'] ?? '',
                'secure'   => (bool) ($p['secure'] ?? false),
                'httponly' => (bool) ($p['httponly'] ?? true),
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /**
     * ¿Hay una sesión válida, es decir, un usuario que existe en la base?
     *
     * OJO: true NO significa "puede usar la app". Un 'pending' y un 'rechazado' tienen sesión
     * válida (la necesitan para ver su pantalla de estado). El permiso de uso lo decide
     * status() — lo chequean los requireAuth() de bootstrap_page.php y bootstrap_api.php.
     */
    public static function check(): bool
    {
        return self::user() !== null;
    }

    /**
     * Usuario de la sesión actual, con los datos de su club adjuntos, o null.
     *
     * Claves: id, club_id, email, nombre, rol, nivel, status, created_at, last_login_at,
     *         club_nombre, club_slug, club_activo.
     *
     * Una sola consulta por request: el resultado queda cacheado en memoria.
     */
    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$cache;
        }
        self::$loaded = true;
        self::$cache = null;

        $id = isset($_SESSION[self::SESSION_KEY]) ? (int) $_SESSION[self::SESSION_KEY] : 0;
        if ($id <= 0) {
            return null;
        }

        try {
            $stmt = Database::get()->prepare(
                'SELECT u.id, u.club_id, u.email, u.nombre, u.rol, u.nivel, u.status,
                        u.created_at, u.last_login_at,
                        c.nombre AS club_nombre, c.slug AS club_slug, c.activo AS club_activo
                 FROM users u
                 INNER JOIN clubs c ON c.id = u.club_id
                 WHERE u.id = :id
                 LIMIT 1'
            );
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
        } catch (PDOException $e) {
            // Base caída: no cacheamos el fallo, así un Database::ping() posterior puede recuperar.
            self::$loaded = false;
            return null;
        }

        // El usuario ya no existe (o le borraron el club): la sesión no vale nada.
        if (!$row) {
            unset($_SESSION[self::SESSION_KEY]);
            return null;
        }

        $row['id']          = (int) $row['id'];
        $row['club_id']     = (int) $row['club_id'];
        $row['club_activo'] = (int) $row['club_activo'];
        $row['status']      = (string) $row['status'];

        // Nivel y status se releen en CADA request, no solo al loguearse: cambiarle el nivel a
        // alguien, aprobarlo o rechazarlo tiene que pegar en la request siguiente sin tener que
        // salir a invalidar sesiones a mano.
        // Un nivel desconocido (columna nueva sin migrar, valor a mano en la base, typo) se
        // degrada a 'miembro': ante la duda, el permiso más bajo, nunca el más alto.
        $nivel = (string) ($row['nivel'] ?? '');
        $row['nivel'] = in_array($nivel, self::NIVELES, true) ? $nivel : 'miembro';

        self::$cache = $row;
        return $row;
    }

    /**
     * Club del usuario logueado. 0 si no hay sesión.
     *
     * Este es el método que va a usar el scoping por club (Etapa 4) dentro de cada handler:
     * siempre detrás de requireAuth(), así que ahí nunca puede dar 0.
     */
    public static function clubId(): int
    {
        $u = self::user();
        return $u ? (int) $u['club_id'] : 0;
    }

    /**
     * Descarta el cache y vuelve a leer el usuario de la base.
     *
     * Solo hace falta cuando la MISMA request modificó la fila del usuario (perfil.php): sin
     * esto, lo que se renderiza después del UPDATE sigue siendo la copia vieja.
     */
    public static function refresh(): ?array
    {
        self::$cache = null;
        self::$loaded = false;
        return self::user();
    }

    /** Club del usuario logueado: ['id', 'nombre', 'slug', 'activo'] o null. */
    public static function club(): ?array
    {
        $u = self::user();
        if (!$u) {
            return null;
        }
        return [
            'id'     => (int) $u['club_id'],
            'nombre' => (string) $u['club_nombre'],
            'slug'   => (string) $u['club_slug'],
            'activo' => (int) $u['club_activo'],
        ];
    }

    /** Id del usuario logueado, o 0. */
    public static function userId(): int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : 0;
    }

    // ── Permisos (users.nivel) ───────────────────────────────────────────────────────────────
    // Recordá: `users.rol` es una etiqueta de texto del perfil y NO decide permisos. Es `nivel`.

    /**
     * Nivel de permiso del usuario logueado.
     *
     * @return string 'miembro' | 'admin_club' | 'superadmin', o '' si no hay sesión.
     *                El '' es deliberado: nunca matchea una lista de requireNivel(), así que un
     *                request sin sesión se cae por el lado seguro sin necesitar un chequeo extra.
     */
    public static function nivel(): string
    {
        $u = self::user();
        return $u ? (string) $u['nivel'] : '';
    }

    /**
     * ¿Puede administrar su club? Incluye al superadmin: es admin de todos los clubes.
     * Este es el chequeo que quieren casi todas las pantallas de administración.
     */
    public static function esAdminClub(): bool
    {
        $n = self::nivel();
        return $n === 'admin_club' || $n === 'superadmin';
    }

    /** ¿Es superadmin (dueño de la plataforma, cruza clubes)? */
    public static function esSuperadmin(): bool
    {
        return self::nivel() === 'superadmin';
    }

    /**
     * Estado de la cuenta.
     *
     * @return string 'pending' | 'active' | 'rechazado', o '' si no hay sesión.
     */
    public static function status(): string
    {
        $u = self::user();
        return $u ? (string) $u['status'] : '';
    }

    /**
     * Corta el request si el nivel del usuario no está en la lista.
     *
     * La comparación es EXACTA, sin jerarquía implícita: `requireNivel('admin_club')` deja
     * afuera al superadmin. Si querés que también pase (que es lo habitual), listalo:
     *
     *     Auth::requireNivel('admin_club', 'superadmin');   // equivale a Auth::esAdminClub()
     *     Auth::requireNivel('superadmin');                 // solo plataforma
     *
     * Se hace así a propósito: que el permiso quede escrito en el call site y no dependa de una
     * regla de escalada invisible metida acá adentro.
     *
     * Cómo corta, según el contexto (ver isJsonContext()):
     *   - endpoint JSON → 403 con {"ok":false,...}, que es lo que el fetch() sabe leer.
     *   - pantalla HTML → 404, NO 403. Para alguien sin permiso, el panel de administración no
     *     tiene que existir: un 403 confirma que la pantalla está ahí y a quién le pertenece.
     */
    public static function requireNivel(string ...$niveles): void
    {
        foreach ($niveles as $n) {
            if (!in_array($n, self::NIVELES, true)) {
                // Bug de programación (typo, nivel inventado), no error de usuario: revienta
                // fuerte en vez de degradar a un guard que no deja pasar nunca a nadie.
                throw new InvalidArgumentException("Auth::requireNivel(): nivel desconocido \"$n\".");
            }
        }

        if (in_array(self::nivel(), $niveles, true)) {
            return;
        }

        if (self::isJsonContext()) {
            respondError(403, 'No tenés permisos para hacer esto.');
        }

        self::notFoundHtml();
    }

    /**
     * ¿Estamos respondiendo un endpoint JSON o una pantalla HTML?
     *
     * Dos señales, y hacen falta las dos:
     *   1. Response.php cargado (existe respondError()) — sin eso no hay con qué responder JSON.
     *   2. El Content-Type ya emitido es JSON — bootstrap_api.php lo manda arriba de todo, antes
     *      de que corra una sola línea del endpoint.
     *
     * No se mira la URL: que un archivo esté en /api/ no dice nada, y una pantalla que por
     * cualquier motivo cargue Response.php seguiría siendo HTML. Si las señales no coinciden,
     * gana el camino HTML, que es el que niega más (404 en vez de 403).
     */
    private static function isJsonContext(): bool
    {
        if (!function_exists('respondError')) {
            return false;
        }

        foreach (headers_list() as $header) {
            if (stripos($header, 'content-type:') === 0 && stripos($header, 'json') !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 404 mínimo y final para pantallas HTML. Sin layout, sin nav, sin nombre de la sección:
     * todo lo que se renderice acá es información sobre algo que el usuario no debería saber
     * que existe.
     */
    private static function notFoundHtml(): void
    {
        if (!headers_sent()) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
        }

        echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width, initial-scale=1">'
           . '<title>404</title></head><body><h1>404</h1>'
           . '<p>No encontramos esa página.</p></body></html>';
        exit;
    }

    /** Normaliza el email tal como se guarda: sin espacios y en minúsculas. */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Valida un destino de redirect interno (el `?next=` de login.php).
     *
     * Lista blanca por forma, no lista negra: solo pasa una ruta relativa al directorio raíz
     * público, terminada en .php, con un query string de caracteres inocuos. Eso descarta de
     * entrada `//evil.com`, `https://evil.com`, `http:evil`, `\\evil.com`, `..%2f..` y
     * cualquier URL absoluta — que es exactamente lo que convierte un `next` en open redirect.
     *
     * @return string|null Ruta segura, o null si no valida (quien llama usa su default).
     */
    public static function safeNext(?string $next): ?string
    {
        if (!is_string($next) || $next === '') {
            return null;
        }
        // Explícito además de la regex: si algo cambia en la regex, esto sigue cortando.
        if (str_starts_with($next, '/') || str_starts_with($next, '\\') || str_contains($next, '\\')) {
            return null;
        }
        if (str_contains($next, ':') || str_contains($next, '..') || str_contains($next, '//')) {
            return null;
        }
        if (!preg_match('#^[A-Za-z0-9_\-]+(?:/[A-Za-z0-9_\-]+)*\.php(?:\?[A-Za-z0-9_\-=&%.]*)?$#', $next)) {
            return null;
        }
        return $next;
    }

    /**
     * Iniciales para el avatar mono: "Alejandro Acosta" → "AA".
     */
    public static function initials(?string $nombre): string
    {
        $nombre = trim((string) $nombre);
        if ($nombre === '') {
            return '··';
        }
        $parts = preg_split('/\s+/u', $nombre) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last  = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';
        return mb_strtoupper($first . $last);
    }
}
