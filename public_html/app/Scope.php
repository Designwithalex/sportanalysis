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
     * Devuelve la fila si pertenece al club de la sesión, o null. Sin efectos colaterales.
     * Usala desde pantallas HTML (ej: validar `?view_id=` antes de renderizar).
     */
    public static function find(PDO $pdo, string $table, int $id): ?array
    {
        self::assertTable($table);
        if ($id <= 0) {
            return null;
        }

        $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE id = :id AND club_id = :club LIMIT 1");
        $stmt->execute(['id' => $id, 'club' => Auth::clubId()]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Igual que find(), pero corta con 404 si el id no es del club. Solo para endpoints JSON.
     *
     * @return array La fila, garantizadamente del club de la sesión.
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
     * Exige que TODOS los ids sean del club. Para los arrays que manda el cliente:
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

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT id FROM `$table` WHERE id IN ($placeholders) AND club_id = ?"
        );
        $stmt->execute([...$ids, Auth::clubId()]);
        $found = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        if (count($found) !== count($ids)) {
            respondError(404, self::TABLES[$table]);
        }

        return $ids;
    }

    /**
     * ¿Existe al menos una fila de esta tabla en el club? Para los "¿ya configuró algo?"
     * del router, sin traerse la fila entera.
     */
    public static function has(PDO $pdo, string $table): bool
    {
        self::assertTable($table);
        $stmt = $pdo->prepare("SELECT 1 FROM `$table` WHERE club_id = :club LIMIT 1");
        $stmt->execute(['club' => Auth::clubId()]);

        return (bool) $stmt->fetchColumn();
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
