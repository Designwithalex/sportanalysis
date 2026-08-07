<?php
/**
 * Catálogo de categorías de datos: LA fuente única.
 *
 * Una categoría es el bucket del Paso Datos: cada CSV (un partido, una sesión, un test) se sube
 * dentro de una. El valor vive en dos ENUM de la base — `datasets.categoria` y `views.categoria` —
 * y se usaba, escrito a mano, en seis lugares distintos:
 *
 *     api/datasets.php · api/manual_dataset.php   → array de validación
 *     app/BaseViewGenerator.php                   → CATEGORIA_LABELS
 *     steps/datos.php · steps/analysis.php · steps/carga_manual.php → etiquetas de la UI
 *
 * Seis copias significa que agregar una categoría es tocar seis archivos y que olvidarse de uno
 * no rompe nada visible: simplemente esa pantalla deja de ofrecerla, o ese endpoint la rechaza.
 * Peor: los dos arrays de validación son la LISTA BLANCA de un valor que después decide permisos,
 * así que una copia desactualizada es un agujero, no un detalle cosmético.
 *
 * Todo lo que sepa de categorías sale de acá. La única duplicación que queda —inevitable— son los
 * dos ENUM de MySQL: si agregás una clave en DEFS, hay que agregarla también con un ALTER TABLE
 * en las dos columnas, o el INSERT falla.
 *
 * NO ES UNA TABLA. Es una constante del producto, no un dato del club: no hay categorías por club
 * ni las crea el usuario. Vive en código para que sea grepeable y para que el ENUM de la base
 * pueda seguir siendo la segunda barrera.
 */

final class Categorias
{
    /**
     * Categorías válidas, EN ORDEN DE PRESENTACIÓN. El orden del array es el orden en que se
     * muestran en toda la app (chips del uploader, grupos de datos.php, modal de vistas base):
     * el flujo de la semana primero (partidos, entrenamientos), después las especialidades
     * (fuerza, kinesiología, nutrición) y `otros` último, que es el bolsón.
     *
     * Cada entrada:
     *   label       → nombre legible. Es lo que ve el usuario y lo que se le manda a la IA.
     *   vista_base  → si BaseViewGenerator puede generarle una vista base del club (ver más abajo).
     *   columnas    → columnas sugeridas de la grilla de carga a mano (steps/carga_manual.php).
     *                 Sugerencia editable, no un esquema: el usuario agrega y saca las que quiera.
     *
     * Ninguna entrada se BORRA de acá aunque parezca en desuso: los valores están en el ENUM de la
     * base y hay datasets de producción que los usan. Sacar una clave haría que esos datasets
     * dejaran de listarse y que su categoría fuera rechazada como inválida.
     */
    private const DEFS = [
        'partidos' => [
            'label'      => 'Partidos',
            'vista_base' => true,
            'columnas'   => ['Distancia (m)', 'Sprints', 'Distancia sprint (m)', 'Vel. máx (km/h)', 'Player Load', 'Minutos'],
        ],
        'entrenamientos' => [
            'label'      => 'Entrenamientos',
            'vista_base' => true,
            'columnas'   => ['Duración (min)', 'RPE', 'Carga', 'Distancia (m)', 'Player Load'],
        ],
        'fuerza' => [
            'label'      => 'Fuerza',
            'vista_base' => true,
            'columnas'   => ['Peso corporal (kg)', 'Press plano (kg)', 'Sentadilla (kg)', 'Peso muerto (kg)', 'Dominadas (reps)'],
        ],
        'kinesiologia' => [
            'label'      => 'Kinesiología',
            'vista_base' => true,
            // Dos ejes en una sola grilla, por pedido explícito: estado de la lesión (¿con quién
            // cuento el sábado?) y trabajo diario (qué se le hizo hoy).
            //
            // Son 9 columnas y la grilla mide 133px por columna: a 1280px —el ancho máximo de la
            // app— entran 8, y en una tablet vertical entran 5. Se scrollea (.grid-scroll) y el
            // nombre del jugador queda fijo (.sticky-col), así que funciona; pero "Observaciones"
            // queda siempre fuera de pantalla y su input mide 90px en monoespaciada. Si molesta,
            // sacarla de acá es una línea: el resto son numéricas y entran mucho mejor.
            'columnas'   => [
                'Zona', 'Tipo', 'Estado', 'Días de baja',
                'Dolor (0-10)', 'Sesiones',
                'Minutos', 'Tipo de trabajo', 'Observaciones',
            ],
        ],
        'nutricion' => [
            'label'      => 'Nutrición',
            'vista_base' => true,
            'columnas'   => ['Peso (kg)', '% graso', 'Masa muscular (kg)', 'Hidratación (L)'],
        ],
        'otros' => [
            'label'      => 'Otros datos',
            'vista_base' => false,
            'columnas'   => ['Valor 1', 'Valor 2'],
        ],
    ];

    /**
     * Categoría por defecto: el bolsón para lo que no encaja en ninguna otra.
     *
     * Es también el fallback de normalizar(). Ojo con eso: degradar a `otros` es aceptable cuando
     * NO hay categoría (un cliente viejo que no manda el campo), pero NO cuando el usuario eligió
     * una y no vale — ahí hay que cortar con 422, porque desde que la categoría decide permisos,
     * degradar en silencio es escribir en un bucket que el usuario no pidió y que quizá sí podía
     * escribir. Por eso normalizar() distingue null de string inválido.
     */
    public const DEFAULT = 'otros';

    /** @return string[] Todas las claves válidas, en orden de presentación. */
    public static function todas(): array
    {
        return array_keys(self::DEFS);
    }

    /**
     * Mapa clave => etiqueta, en orden de presentación. Este es el reemplazo directo de los
     * arrays literales de steps/ y de BaseViewGenerator::CATEGORIA_LABELS:
     *
     *     foreach (Categorias::labels() as $key => $label) { ... }
     *
     * @return array<string,string>
     */
    public static function labels(): array
    {
        return array_map(static fn (array $d): string => $d['label'], self::DEFS);
    }

    /**
     * Etiqueta legible de una categoría.
     *
     * Una clave desconocida devuelve la clave tal cual en vez de reventar: esto se usa en mensajes
     * de error y en pantallas, y una fila vieja con un valor que ya no existe tiene que poder
     * mostrarse. Para decidir algo está esValida(), no esto.
     */
    public static function label(string $categoria): string
    {
        return self::DEFS[$categoria]['label'] ?? $categoria;
    }

    /** ¿Es una categoría del catálogo? Único chequeo válido antes de escribir el valor. */
    public static function esValida(string $categoria): bool
    {
        return isset(self::DEFS[$categoria]);
    }

    /**
     * Normaliza lo que llega del cliente.
     *
     * @param  string|null $categoria Valor crudo de la request (null o '' = no vino).
     * @return string|null La categoría válida; DEFAULT si no vino ninguna; null si vino una que
     *                     no existe. El null es la señal de "cortá con 422": quien llama NO debe
     *                     convertirlo en DEFAULT — ver el comentario de self::DEFAULT.
     */
    public static function normalizar(?string $categoria): ?string
    {
        $categoria = trim((string) $categoria);
        if ($categoria === '') {
            return self::DEFAULT;
        }

        return self::esValida($categoria) ? $categoria : null;
    }

    // ── Vistas base del club ─────────────────────────────────────────────────────────────────

    /**
     * ¿Esta categoría tiene vista base del club (el tablero `views.tipo='cluster'` que arma
     * BaseViewGenerator)?
     *
     * Cinco la tienen: partidos, entrenamientos, fuerza, kinesiología y nutrición — el flujo de la
     * semana más las especialidades, cada una con un responsable concreto adentro del club, que es
     * también el eje de los permisos por categoría (ver CategoryPermission).
     *
     * `otros` no: es el bolsón de lo que no encaja, y un tablero autogenerado sobre datasets que no
     * comparten ni columnas ni sentido no dice nada. Sigue siendo una categoría válida para subir
     * datos y para armar vistas a mano.
     */
    public static function tieneVistaBase(string $categoria): bool
    {
        return (bool) (self::DEFS[$categoria]['vista_base'] ?? false);
    }

    /**
     * Las categorías con vista base, clave => etiqueta, en orden de presentación.
     * @return array<string,string>
     */
    public static function conVistaBase(): array
    {
        $out = [];
        foreach (self::DEFS as $key => $def) {
            if ($def['vista_base']) {
                $out[$key] = $def['label'];
            }
        }

        return $out;
    }

    // ── Ayudas de UI ─────────────────────────────────────────────────────────────────────────

    /**
     * Columnas sugeridas para la grilla de carga a mano de una categoría.
     * Categoría desconocida → las de `otros`, que sirven para cualquier cosa.
     *
     * @return string[]
     */
    public static function columnasSugeridas(string $categoria): array
    {
        return self::DEFS[$categoria]['columnas'] ?? self::DEFS[self::DEFAULT]['columnas'];
    }
}
