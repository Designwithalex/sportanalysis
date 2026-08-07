<?php
/**
 * Fechas que llegan como número de serie de Excel.
 *
 * EL PROBLEMA QUE RESUELVE. Los CSV que exporta el GPS traen la fecha de la sesión en una columna
 * `Date`, pero no como texto: como el número de serie de Excel (`46095`). ColumnTypeDetector la veía
 * numérica —lo es— y la fila quedaba guardada con el string "46095", que no significa nada para
 * nadie. Resultado: el sistema no tenía UNA sola fecha usable (cero columnas tipo `fecha` en los
 * nueve datasets cargados), y por eso ningún tablero podía ordenar por tiempo, filtrar "últimos 7
 * días" ni distinguir el día del entrenamiento del día en que alguien subió el archivo.
 *
 * LA SERIE. Excel cuenta días desde el 1900-01-01 = 1. Arrastra un bug histórico —cree que 1900 fue
 * bisiesto— que desplaza todo lo anterior al 1900-03-01, pero para cualquier fecha moderna la
 * conversión a epoch Unix es la constante 25569 (los días entre 1900-01-01 y 1970-01-01 ya con el
 * bug compensado). La parte decimal es la hora: 46095.627 es el 2026-03-14 a las 15:03.
 *
 * POR QUÉ HACE FALTA EL NOMBRE DE LA COLUMNA. Un número suelto es ambiguo: 46095 puede ser una
 * fecha o pueden ser metros. No hay forma de saberlo mirando el valor, así que convertir se decide
 * por el ENCABEZADO (`Date`, `Fecha`, `DATE INJ`) y el rango solo actúa de red de seguridad. Al
 * revés —convertir todo número en rango— rompería cualquier columna de distancia grande.
 */
final class ExcelDate
{
    /**
     * Días entre el 1900-01-01 de Excel y el 1970-01-01 de Unix, con el bug del año bisiesto ya
     * compensado. Válido para todo lo posterior al 1900-03-01, que es todo lo que ve esta app.
     */
    private const EPOCH_OFFSET = 25569;

    /**
     * Rango aceptable de una serie, en años reales: 1990-01-01 a 2100-01-01.
     *
     * Es una red de seguridad, no el criterio: el criterio es el encabezado. Sirve para el caso en
     * que una columna se llame "Fecha" pero traiga otra cosa (un número de fecha del torneo, un
     * "Fecha 3"), y para no convertir celdas vacías o ceros.
     */
    private const MIN_SERIAL = 32874;   // 1990-01-01
    private const MAX_SERIAL = 73051;   // 2100-01-01

    /** Palabras en el encabezado que habilitan la conversión. */
    private const HEADER_HINTS = ['date', 'fecha'];

    /**
     * ¿El nombre de esta columna dice que es una fecha?
     *
     * Coincidencia por substring a propósito: cubre `Date`, `DATE INJ`, `Fecha de sesión` y
     * `Session Date`. No cubre `Split Start Time` ni `Split End Time`, que también son series de
     * Excel pero son horas dentro del día y convertirlas a fecha las volvería todas iguales.
     */
    public static function headerLooksLikeDate(string $header): bool
    {
        $h = mb_strtolower(trim($header), 'UTF-8');
        $h = strtr($h, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);

        foreach (self::HEADER_HINTS as $hint) {
            if (str_contains($h, $hint)) {
                return true;
            }
        }

        return false;
    }

    /** ¿Este valor es una serie de Excel dentro del rango plausible? */
    public static function isSerial(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || !is_numeric($value)) {
            return false;
        }

        $n = (float) $value;

        return $n >= self::MIN_SERIAL && $n <= self::MAX_SERIAL;
    }

    /**
     * Serie de Excel -> 'YYYY-MM-DD'. Devuelve null si no es una serie plausible, para que quien
     * llama pueda dejar el valor crudo como estaba en vez de escribir una fecha inventada.
     */
    public static function toIso(string $value): ?string
    {
        if (!self::isSerial($value)) {
            return null;
        }

        // (int) trunca la parte decimal, que es la hora: nos quedamos con el día.
        return gmdate('Y-m-d', (int) ((((float) $value) - self::EPOCH_OFFSET) * 86400));
    }

    /**
     * Normaliza a 'YYYY-MM-DD' cualquier valor de fecha que sepamos leer: serie de Excel, ISO ya
     * formateado, o los formatos con barra/guión que usa la región (07/06/2026 = 7 de junio).
     *
     * Devuelve null si no se puede leer, que es la señal de "dejá el valor como está".
     */
    public static function normalize(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if ($iso = self::toIso($value)) {
            return $iso;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
        }

        // Día primero: es el orden de la región, y ningún exportador de acá manda mes primero.
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})$/', $value, $m)) {
            [$d, $mes, $anio] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($anio < 100) {
                $anio += 2000;
            }

            return checkdate($mes, $d, $anio) ? sprintf('%04d-%02d-%02d', $anio, $mes, $d) : null;
        }

        return null;
    }

    /**
     * Proporción de filas que tienen que compartir la misma fecha para que el archivo cuente como
     * UNA sesión. Ver fechaDeSesion().
     */
    private const UMBRAL_SESION_UNICA = 0.8;

    /**
     * La fecha de la sesión a partir de todos los valores de su columna de fecha.
     *
     * Toma la MODA (el valor más repetido), no el mínimo ni el primero: un CSV de partido es una
     * fila por jugador con la misma fecha en todas, y si alguna trae basura o quedó de otra sesión,
     * la moda la ignora sola. Empate: gana la más temprana, que es el arranque de la sesión.
     *
     * DEVUELVE NULL SI EL ARCHIVO NO ES UNA SESIÓN. No todo dataset es "un partido" o "un
     * entrenamiento": el registro de lesiones es un LOG del año entero, con una fecha distinta por
     * fila. Ponerle una `fecha_sesion` ahí sería afirmar que las 66 lesiones pasaron el mismo día,
     * y cualquier gráfico que use `__fecha` las apilaría todas en un punto. Para esos datasets el
     * eje temporal correcto es la columna de fecha de cada fila, no la del dataset.
     *
     * El criterio es mecánico: si la moda no cubre al menos el 80% de las filas legibles, esto no
     * es una sesión.
     *
     * @param  string[] $valores valores crudos de la columna de fecha
     * @return string|null 'YYYY-MM-DD', o null si no se pudo leer ninguno o el archivo es un log
     */
    public static function fechaDeSesion(array $valores): ?string
    {
        $conteo = [];
        foreach ($valores as $valor) {
            if ($iso = self::normalize((string) $valor)) {
                $conteo[$iso] = ($conteo[$iso] ?? 0) + 1;
            }
        }

        if (!$conteo) {
            return null;
        }

        // Orden: primero por frecuencia desc, y a igual frecuencia por fecha asc.
        // (Los sort de PHP son estables desde 8.0, así que el ksort previo sobrevive al arsort.)
        ksort($conteo);
        arsort($conteo);

        $moda   = (string) array_key_first($conteo);
        $total  = array_sum($conteo);

        return $conteo[$moda] / $total >= self::UMBRAL_SESION_UNICA ? $moda : null;
    }
}
