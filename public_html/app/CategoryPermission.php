<?php
/**
 * Permiso de ESCRITURA por categoría de datos (el eje "especialidad").
 *
 * Hay tres ejes de permiso en el sistema y no se mezclan:
 *
 *   1. CLUB      — Scope.php. Frontera dura entre tenants. Corta con 404.
 *   2. VISTA     — ViewPermission.php. Del club vs. privada. Corta con 403 o 404 según el caso.
 *   3. CATEGORÍA — este archivo. Adentro del club: quién puede tocar los datos de fuerza, de
 *                  partidos, de kinesiología, de nutrición. Corta con 403.
 *
 * EL PROBLEMA QUE RESUELVE. Hasta acá, escribir datos era binario: o eras `admin_club` o eras
 * de lectura. Eso obliga a hacer administrador del club al kinesiólogo para que pueda subir su
 * planilla — y con eso se lleva puestos los usuarios, las verificaciones y las vistas de todos.
 * El eje de categoría le da exactamente lo suyo: el kinesiólogo sube y edita kinesiología, y no
 * toca nada más.
 *
 * ── HABILITACIONES EXPLÍCITAS, NUNCA DERIVADAS ───────────────────────────────────────────────
 *
 * Las habilitaciones viven en `user_categorias (user_id, categoria)` y las asigna un admin_club
 * al aprobar el alta. NADIE SE AUTO-HABILITA.
 *
 * En particular NO se derivan de `users.rol`, que es texto libre que escribe el propio usuario en
 * su perfil ("Preparador físico", "kinesiologo", "Kinesiólogo/a", "PF"). Cualquier intento de
 * mapear ese campo a un permiso depende de tildes, mayúsculas y de cómo se le ocurrió escribirlo:
 * daría acceso o lo negaría en silencio según la ortografía. `rol` se muestra, no autoriza. Es la
 * misma regla que ya está escrita en Auth.php para `rol` vs. `nivel`.
 *
 * ── QUIÉN PUEDE QUÉ ──────────────────────────────────────────────────────────────────────────
 *
 *   superadmin / admin_club  → TODAS las categorías, sin necesitar ninguna fila en la tabla.
 *                              Administrar el club incluye sus datos; pedirle grants a quien los
 *                              reparte sería un candado con la llave puesta al lado.
 *   miembro con grant de X   → escribe X. SIN ser admin: ese es el punto de toda la función.
 *   miembro sin grants       → lectura. Ve todo lo de su club y no escribe nada.
 *
 * Un usuario sin habilitaciones NO ve la app rota: sigue viendo el plantel, los datasets y todas
 * las vistas. Lo único que no puede es escribir. Todo guard de acá es de escritura.
 *
 * ── POR QUÉ 403 Y NO 404 ─────────────────────────────────────────────────────────────────────
 *
 * Al revés que Scope. Scope oculta la EXISTENCIA de un recurso de otro club, y ahí un 403
 * confirmaría que existe. Acá no hay nada que ocultar: la categoría existe, el usuario la ve
 * listada y ve sus datasets adentro. Lo que le falta es autorización, y eso hay que decírselo —
 * un 404 le mentiría diciéndole que la categoría que está mirando no existe.
 *
 * Como todo lo que corta con respondError(), requireCategoria() solo se puede usar desde
 * endpoints JSON (bootstrap_api.php ya carga Response.php). Desde una pantalla HTML usá
 * puedeEditarCategoria() / categoriasEditables(), que no tienen efectos colaterales.
 */

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Categorias.php';
require_once __DIR__ . '/Database.php';

final class CategoryPermission
{
    /**
     * Cache por request de las habilitaciones explícitas del usuario de la sesión, como
     * array<string,true> para poder chequear con isset().
     *
     * Mismo patrón que Auth::user(): la consulta se hace UNA vez por request y no en cada
     * chequeo. Un solo upload de CSV llama a puedeEditarCategoria() varias veces (endpoint,
     * pantalla, mensaje de error) y no vale una query por llamada.
     *
     * $loaded distingue "todavía no consulté" de "consulté y no tiene ninguna".
     */
    private static array $cache = [];
    private static bool $loaded = false;

    /**
     * ¿Puede el usuario de la sesión ESCRIBIR datos de esta categoría?
     *
     * Sin sesión → false. Categoría fuera del catálogo → false: no se autoriza un valor que no
     * existe, aunque venga con un grant (una fila vieja de una categoría eliminada no revive).
     */
    public static function puedeEditarCategoria(string $categoria): bool
    {
        if (!Categorias::esValida($categoria)) {
            return false;
        }

        if (Auth::userId() <= 0) {
            return false;
        }

        // esAdminClub() y NO requireNivel('admin_club'): requireNivel compara EXACTO, sin
        // jerarquía, y dejaría afuera al superadmin.
        if (Auth::esAdminClub()) {
            return true;
        }

        return isset(self::grants()[$categoria]);
    }

    /**
     * Categorías que este usuario puede escribir, en el orden de presentación de Categorias.
     *
     * Para la UI: qué chips del uploader se habilitan, qué grupos de datos.php muestran el botón
     * de cargar a mano, si conviene mostrar la sección de escritura o no. El servidor valida
     * igual con requireCategoria(): esto es para no ofrecer botones que van a dar 403.
     *
     * @return string[] Claves de categoría. Vacío = usuario de solo lectura.
     */
    public static function categoriasEditables(): array
    {
        if (Auth::userId() <= 0) {
            return [];
        }

        if (Auth::esAdminClub()) {
            return Categorias::todas();
        }

        $grants = self::grants();

        // Se recorre el catálogo, no los grants: así el orden es el de presentación y una fila
        // con una categoría que ya no existe en el catálogo queda descartada de paso.
        return array_values(array_filter(
            Categorias::todas(),
            static fn (string $c): bool => isset($grants[$c])
        ));
    }

    /**
     * Corta el request con 403 si el usuario no puede escribir esta categoría.
     *
     * Una categoría fuera del catálogo corta con 422 y no con 403: no es un problema de permisos
     * sino de un valor que no existe, y responder 403 haría pensar que existe y le falta un
     * grant. Igual quien llama debería validar antes con Categorias::esValida(); esto es la red.
     */
    public static function requireCategoria(string $categoria): void
    {
        if (!Categorias::esValida($categoria)) {
            respondError(422, 'Categoría inválida.');
        }

        if (self::puedeEditarCategoria($categoria)) {
            return;
        }

        respondError(403, 'No tenés habilitada la categoría ' . Categorias::label($categoria)
            . '. Pedile a un administrador de tu club que te la habilite.');
    }

    /**
     * Descarta el cache. Solo hace falta si la MISMA request cambió las habilitaciones del
     * usuario logueado (no pasa hoy: los grants los edita un admin sobre OTRO usuario, y la
     * request siguiente de ese otro ya relee).
     */
    public static function refresh(): void
    {
        self::$cache = [];
        self::$loaded = false;
    }

    /**
     * Habilitaciones explícitas de CUALQUIER usuario, para la pantalla donde el admin las
     * reparte. No pasa por el cache (es de la sesión) ni aplica la regla de admin: devuelve las
     * filas tal cual están en la tabla, que es lo que hay que mostrar tildado en el formulario.
     *
     * Acotado por club a propósito: el user_id llega del cliente en admin.php y un admin_club no
     * puede leer —ni editar— los grants de un usuario de otro club. Se pasa el club explícito en
     * vez de tomar Auth::clubId() adentro para que el superadmin pueda administrar cualquiera.
     *
     * @return string[] Claves de categoría, en orden de presentación.
     */
    public static function categoriasDeUsuario(PDO $pdo, int $userId, int $clubId): array
    {
        if ($userId <= 0 || $clubId <= 0) {
            return [];
        }

        $stmt = $pdo->prepare(
            'SELECT uc.categoria
             FROM user_categorias uc
             INNER JOIN users u ON u.id = uc.user_id
             WHERE uc.user_id = :user AND u.club_id = :club'
        );
        $stmt->execute(['user' => $userId, 'club' => $clubId]);

        $grants = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

        return array_values(array_filter(
            Categorias::todas(),
            static fn (string $c): bool => isset($grants[$c])
        ));
    }

    /**
     * Habilitaciones explícitas del usuario de la sesión, cacheadas por request.
     *
     * FILTRO POR user_id SOLO, a propósito: el usuario sale de la sesión (no del cliente), y su
     * club sale de él. No hay nada que un club_id en el WHERE pueda agregar acá, y agregarlo
     * ataría esta clase a que la tabla tenga esa columna.
     *
     * SI LA CONSULTA FALLA, se degrada a "ninguna habilitación" en vez de tirar el request abajo.
     * Es el lado seguro (deny) y además es el estado real mientras la migración que crea
     * `user_categorias` no esté corrida en producción: sin esto, el día del deploy la app entera
     * responde 500 hasta que alguien entre a phpMyAdmin. Con esto, los admin_club siguen
     * trabajando (nunca llegan a esta query) y los miembros quedan en solo lectura, que es
     * exactamente lo que eran antes de esta función.
     *
     * El fallo NO se cachea, igual que en Auth::user(): así un Database::ping() posterior —los
     * endpoints de IA reconectan a mitad de request— puede recuperar en la misma request.
     *
     * @return array<string,true>
     */
    private static function grants(): array
    {
        if (self::$loaded) {
            return self::$cache;
        }

        $userId = Auth::userId();
        if ($userId <= 0) {
            self::$loaded = true;
            self::$cache = [];
            return self::$cache;
        }

        try {
            $stmt = Database::get()->prepare(
                'SELECT categoria FROM user_categorias WHERE user_id = :user'
            );
            $stmt->execute(['user' => $userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            return [];
        }

        $grants = [];
        foreach ($rows as $categoria) {
            $categoria = (string) $categoria;
            // Una fila de una categoría que ya no está en el catálogo no habilita nada.
            if (Categorias::esValida($categoria)) {
                $grants[$categoria] = true;
            }
        }

        self::$cache = $grants;
        self::$loaded = true;

        return self::$cache;
    }
}
