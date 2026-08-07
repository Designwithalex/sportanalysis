<?php
/**
 * El orden de la camiseta: 1 a 15.
 *
 * POR QUÉ EXISTE. Ordenar jugadores alfabéticamente es cómodo para buscar un nombre y malo para
 * leer un plantel: un preparador lee el equipo por líneas y en el orden en que se paran —pilar
 * izquierdo, hooker, pilar derecho, las dos segundas, la tercera— y con orden alfabético el hooker
 * queda entre dos wines. Este archivo es la única definición de ese orden; sin él, cada tabla que
 * quisiera respetarlo tendría que traer su propia lista y las dos se irían separando.
 *
 * DOS GRANULARIDADES, LA MISMA ESCALA. `sub_familia` es la línea (Front Row, Locks…) y
 * `metadata.Posicion` es el puesto concreto (Pilar Izquierdo, Hooker…). Las dos se ordenan con el
 * mismo criterio —el número de camiseta— así que una línea vale lo que su primer puesto: Front Row
 * es 1 porque su primer puesto es el 1. Así una tabla ordenada por línea y otra por puesto quedan
 * en el mismo orden relativo.
 *
 * VALORES DESCONOCIDOS AL FINAL, NO AL PRINCIPIO. Un puesto que no está en estas listas —un club
 * que escriba "Wing izquierdo", o un jugador sin puesto cargado— devuelve un orden altísimo y cae
 * al fondo de la tabla. Mandarlo al principio (que es lo que pasa si se devuelve 0) pondría lo que
 * menos se sabe arriba de todo.
 */
final class Puestos
{
    /** Puesto concreto (players.metadata.Posicion) => número de camiseta. */
    private const PUESTOS = [
        'pilar izquierdo' => 1,
        'hooker'          => 2,
        'pilar derecho'   => 3,
        'pilar'           => 3,   // sin lado especificado: cae con los pilares, después del hooker
        'segunda linea'   => 4,
        'tercera linea'   => 6,
        'medio scrum'     => 9,
        'apertura'        => 10,
        'centro'          => 12,
        'wing'            => 14,
        'fullback'        => 15,
    ];

    /** Línea (players.sub_familia) => número de camiseta de su primer puesto. */
    private const LINEAS = [
        'front row'     => 1,
        'locks'         => 4,
        'back row'      => 6,
        'inside backs'  => 9,
        'outside backs' => 14,
    ];

    /** Lo que se le da a un valor que no reconocemos: al fondo, pero antes que lo vacío. */
    private const DESCONOCIDO = 90;
    private const VACIO       = 99;

    /**
     * Orden de un puesto o de una línea. Acepta las dos granularidades porque quien ordena una
     * tabla no sabe —ni tiene por qué— cuál de las dos le tocó en la columna.
     */
    public static function orden(?string $valor): int
    {
        $v = mb_strtolower(trim((string) $valor), 'UTF-8');
        $v = strtr($v, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);

        if ($v === '') {
            return self::VACIO;
        }

        return self::PUESTOS[$v] ?? self::LINEAS[$v] ?? self::DESCONOCIDO;
    }

    /** ¿Este valor es un puesto o una línea que sepamos ordenar? */
    public static function esConocido(?string $valor): bool
    {
        return self::orden($valor) < self::DESCONOCIDO;
    }

    /**
     * Comparador para usort/uasort. Devuelve null cuando NINGUNO de los dos valores es un puesto
     * conocido, que es la señal de "esto no es una columna de puestos, ordenalo como venías".
     *
     * Con uno solo conocido sí ordena: la mezcla aparece cuando el plantel tiene puestos a medio
     * cargar, y ahí querés los que sabés arriba y el resto abajo, no todo alfabético.
     */
    public static function comparar(?string $a, ?string $b): ?int
    {
        if (!self::esConocido($a) && !self::esConocido($b)) {
            return null;
        }

        // Mismo número (las dos segundas líneas, los tres tercera): alfabético para que el orden
        // sea estable y no dependa de cómo salieron de la base.
        return self::orden($a) <=> self::orden($b)
            ?: strcasecmp((string) $a, (string) $b);
    }
}
