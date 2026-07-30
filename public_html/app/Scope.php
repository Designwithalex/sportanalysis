<?php
/**
 * Validación de pertenencia al club: la barrera contra el IDOR.
 *
 * El problema que resuelve: casi todos los endpoints de api/ reciben un id del cliente
 * (`?id=`, `view_id`, `widget_id`, `dataset_ids[]`). Sin validar, un usuario del club A puede
 * mandar el id de un widget del club B y leerlo, editarlo o borrarlo. Filtrar por `club_id` en
 * el WHERE cubre las lecturas, pero no alcanza cuando el id viaja a un INSERT o a un UPDATE que
 * apunta a otra tabla.
 *
 * Es la SEGUNDA de tres capas:
 *   1. Esquema  — FKs compuestas (id, club_id). Hacen imposible el cruce a nivel base.
 *   2. Scope    — este archivo. Aporta lo que la FK no da: un 404 legible en vez de un error de
 *                 constraint que sale como 500 y filtra el nombre de la constraint. Además cubre
 *                 los tres FK que InnoDB NO deja volver compuestos (dataset_rows.player_id y los
 *                 dos *_player_id de name_reconciliations, todos ON DELETE SET NULL).
 *   3. WHERE club_id en cada sentencia.
 *
 * Por qué 404 y no 403: un 403 confirma que el recurso existe. El 404 no distingue entre
 * "no existe" y "no es tuyo", que es justamente lo que no hay que revelar.
 *
 * Las funciones require*() cortan el request con respondError(), así que solo se pueden usar
 * donde Response.php esté cargado — es decir, desde bootstrap_api.php (endpoints JSON).
 * Desde una pantalla HTML usá find(), que no tiene efectos colaterales y devuelve null.
 *
 * ── SEGUNDO EJE: EL USUARIO DENTRO DEL CLUB ─────────────────────────────────────────────────
 *
 * El club sigue siendo la frontera dura. Adentro del club hay un segundo eje, más blando, que
 * es la propiedad de una VISTA:
 *
 *     views.user_id IS NULL  → vista base del club: la ven todos los miembros.
 *     views.user_id = X      → vista privada de X: solo la ve X.
 *
 * De ahí sale el predicado de visibilidad, que es la regla ÚNICA de todo el sistema y no se
 * escribe distinto en ningún lado:
 *
 *     club_id = :club AND (user_id IS NULL OR user_id = :user)
 *
 * `widgets`, `custom_metrics` y `view_filters` no tienen `user_id` propio: su visibilidad es la
 * de su vista padre, y se resuelve con un JOIN a `views`. A propósito no se denormaliza la
 * columna en las hijas — es la misma regla en un solo lugar, y el costo de equivocarse acá es
 * chico: una fuga entre usuarios del MISMO club expone el layout de un tablero, no datos que el
 * otro no pudiera ver igual (`players` y `datasets` son compartidos por diseño).
 *
 * `players`, `datasets`, `dataset_rows` y `name_reconciliations` son del club y no llevan el
 * segundo eje.
 */

/**
 * Tablas cuyo id puede llegar desde el cliente, con el mensaje de "no encontrado" de cada una.
 *
 * Es una LISTA BLANCA, no una comodidad: el nombre de tabla se interpola en el SQL (no se puede
 * bindear un identificador), así que si saliera de la request tendríamos una inyección. Cualquier
 * tabla fuera de esta lista es un error de programación y revienta en el acto.
 */
final class Scope
{
    private const TABLES = [
        'players'              => 'Jugador no encontrado.',
        'datasets'             => 'Dataset no encontrado.',
        'views'                => 'Vista no encontrada.',
        'widgets'              => 'Widget no encontrado.',
        'widget_versions'      => 'Versión no encontrada.',
        'custom_metrics'       => 'Métrica no encontrada.',
        'view_filters'         => 'Filtro no encontrado.',
        'name_reconciliations' => 'Conciliación no encontrada.',
        'dataset_rows'         => 'Fila no encontrada.',
    ];

    /**
     * Tablas que cuelgan de una vista por `view_id` y heredan su visibilidad.
     *
     * `widget_versions` NO está acá, y es una decisión, no un olvido: colgaría a dos saltos
     * (widget_versions → widgets → views) y hoy no tiene ni un call site donde el id llegue del
     * cliente. El único uso (el "deshacer" de api/widgets.php) entra por `widget_id` DESPUÉS de
     * un `Scope::require($pdo,'widgets',...)`, que ya aplica el predicado vía la vista padre: la
     * versión queda cubierta por transitividad. Si algún día aparece un endpoint que reciba un
     * `version_id` del cliente, hay que sumarla acá con el JOIN de dos saltos.
     */
    private const VIEW_OWNED = ['widgets', 'custom_metrics', 'view_filters'];

    /**
     * Devuelve la fila si es visible para la sesión (club + dueño de la vista), o null.
     * Sin efectos colaterales. Usala desde pantallas HTML (ej: validar `?view_id=` antes
     * de renderizar).
     */
    public static function find(PDO $pdo, string $table, int $id): ?array
    {
        self::assertTable($table);
        if ($id <= 0) {
            return null;
        }

        [$from, $cond, $needsUser] = self::scopeSql($table, ':club', ':user');

        // `t.*` y no `*`: con el JOIN a views, un `*` traería también las columnas de la vista y
        // (id, club_id, created_at, que existen en las dos) pisarían las de la fila pedida.
        $stmt = $pdo->prepare("SELECT t.* FROM $from WHERE t.id = :id AND $cond LIMIT 1");
        $stmt->execute(self::params(['id' => $id], $needsUser));
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Igual que find(), pero corta con 404 si el id no es visible. Solo para endpoints JSON.
     *
     * 404 también cuando la fila existe y es del club pero cuelga de una vista privada de otro
     * usuario: entre miembros del mismo club vale la misma regla que entre clubes — no se
     * confirma que el recurso exista.
     *
     * @return array La fila, garantizadamente visible para la sesión.
     */
    public static function require(PDO $pdo, string $table, int $id): array
    {
        self::assertTable($table);
        if ($id <= 0) {
            respondError(400, 'Falta el id de ' . self::label($table) . '.');
        }

        $row = self::find($pdo, $table, $id);
        if ($row === null) {
            respondError(404, self::TABLES[$table]);
        }

        return $row;
    }

    /**
     * Exige que TODOS los ids sean visibles para la sesión. Para los arrays que manda el cliente:
     * `dataset_ids[]` de un widget, el `ids[]` de un reorder, los datasets de una vista.
     *
     * Falla en bloque a propósito: si uno solo es ajeno, no se procesa ninguno. Aceptar los
     * válidos e ignorar el resto le confirmaría al atacante cuáles existen.
     *
     * @param  int[] $ids
     * @return int[] Los mismos ids, normalizados a int y sin duplicados.
     */
    public static function requireAll(PDO $pdo, string $table, array $ids): array
    {
        self::assertTable($table);

        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_values(array_filter($ids, static fn (int $i): bool => $i > 0));
        if ($ids === []) {
            return [];
        }

        [$from, $cond, $needsUser] = self::scopeSql($table, '?', '?');

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT t.id FROM $from WHERE t.id IN ($placeholders) AND $cond"
        );

        // Orden posicional: primero los ids del IN, después club y (si aplica) user, en el mismo
        // orden en que scopeSql() los escribe dentro de $cond.
        $args = [...$ids, Auth::clubId()];
        if ($needsUser) {
            $args[] = Auth::userId();
        }

        $stmt->execute($args);
        $found = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        if (count($found) !== count($ids)) {
            respondError(404, self::TABLES[$table]);
        }

        return $ids;
    }

    /**
     * ¿Existe al menos una fila visible de esta tabla? Para los "¿ya configuró algo?"
     * del router, sin traerse la fila entera.
     *
     * En `players` y `datasets` (los dos únicos usos hoy, desde panel.php) es "del club".
     * En `views` y sus hijas es "del club o mía", igual que todo lo demás.
     */
    public static function has(PDO $pdo, string $table): bool
    {
        self::assertTable($table);

        [$from, $cond, $needsUser] = self::scopeSql($table, ':club', ':user');

        $stmt = $pdo->prepare("SELECT 1 FROM $from WHERE $cond LIMIT 1");
        $stmt->execute(self::params([], $needsUser));

        return (bool) $stmt->fetchColumn();
    }

    /**
     * ¿Esta vista es del club (la ven todos) o privada del usuario?
     *
     * Para que las pantallas puedan mostrar la diferencia (un chip "Del club" vs "Mía") sin
     * repetir la interpretación de `user_id` en cada template.
     *
     * @param array $view Fila de `views` (la que devuelve find()/require()).
     */
    public static function esVistaDelClub(array $view): bool
    {
        // El ?? cubre la fila que venga de un SELECT que no haya traído la columna.
        return ($view['user_id'] ?? null) === null;
    }

    /**
     * Predicado de visibilidad de vistas, para las queries escritas a mano que no pasan por acá
     * (los listados de api/views.php, por ejemplo). Que exista un solo lugar donde está escrito.
     *
     * Devuelve, con $alias='v': "(v.user_id IS NULL OR v.user_id = :user)".
     * El `club_id` va aparte: eso no se negocia y ya está en toda sentencia.
     */
    public static function sqlVistaVisible(string $alias = 'v', string $userPlaceholder = ':user'): string
    {
        return "($alias.user_id IS NULL OR $alias.user_id = $userPlaceholder)";
    }

    /**
     * FROM + condición de visibilidad para una tabla de la lista blanca.
     *
     * La tabla pedida siempre se aliasa `t`; la vista padre, `v`.
     *
     * El `ON` compara `club_id` además de la FK: si un día un widget quedara colgado de una
     * vista de otro club, el JOIN no lo une igual (regla de CLAUDE.md — el club se compara en el
     * ON, no solo en el WHERE).
     *
     * @param  string $clubPh Placeholder para el club (':club' o '?').
     * @param  string $userPh Placeholder para el usuario (':user' o '?').
     * @return array{0:string,1:string,2:bool} [FROM, condición, si usa el placeholder de usuario]
     */
    private static function scopeSql(string $table, string $clubPh, string $userPh): array
    {
        $from = "`$table` t";
        $cond = "t.club_id = $clubPh";

        if ($table === 'views') {
            return [$from, $cond . ' AND ' . self::sqlVistaVisible('t', $userPh), true];
        }

        if (in_array($table, self::VIEW_OWNED, true)) {
            $from .= ' INNER JOIN `views` v ON v.id = t.view_id AND v.club_id = t.club_id';
            return [$from, $cond . ' AND ' . self::sqlVistaVisible('v', $userPh), true];
        }

        // Tablas del club, sin segundo eje.
        return [$from, $cond, false];
    }

    /**
     * Parámetros nombrados comunes. `user` solo se agrega si la query lo tiene: pasar un
     * placeholder que no aparece en el SQL es un error de PDO, no un extra inocuo.
     */
    private static function params(array $extra, bool $needsUser): array
    {
        $params = $extra + ['club' => Auth::clubId()];
        if ($needsUser) {
            $params['user'] = Auth::userId();
        }

        return $params;
    }

    /**
     * Corta el programa si la tabla no está en la lista blanca. No es un error de usuario:
     * es un bug, y tiene que explotar fuerte en vez de degradar a una query rara.
     */
    private static function assertTable(string $table): void
    {
        if (!isset(self::TABLES[$table])) {
            throw new InvalidArgumentException("Scope: tabla no permitida \"$table\".");
        }
    }

    /** Etiqueta corta para el mensaje de "falta el id". */
    private static function label(string $table): string
    {
        return rtrim(strtolower(self::TABLES[$table]), '.') === ''
            ? $table
            : strtolower(explode(' ', self::TABLES[$table])[0]);
    }
}
