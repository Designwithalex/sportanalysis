<?php

class NameMatcher
{
    /**
     * Normaliza un nombre para comparación: minúsculas, sin tildes, sin puntuación,
     * y tokens ordenados alfabéticamente (para no depender del orden nombre/apellido).
     */
    public static function normalize(string $name): string
    {
        $name = mb_strtolower(trim($name), 'UTF-8');
        $name = strtr($name, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u',
        ]);
        $name = preg_replace('/[^\p{L}\s]/u', ' ', $name);
        $tokens = array_values(array_filter(preg_split('/\s+/', trim($name))));
        sort($tokens);
        return implode(' ', $tokens);
    }

    /**
     * @param array<int, array{id:int, nombre:string}> $players
     * @return array<string, int> nombre normalizado -> player_id
     */
    public static function buildIndex(array $players): array
    {
        $index = [];
        foreach ($players as $player) {
            $index[self::normalize($player['nombre'])] = (int) $player['id'];
        }
        return $index;
    }

    /**
     * @param array<string, int> $index resultado de buildIndex()
     */
    public static function findExact(string $rawName, array $index): ?int
    {
        return $index[self::normalize($rawName)] ?? null;
    }

    /**
     * Sugiere el jugador más parecido por similitud de texto (tolera tildes, mayúsculas,
     * orden nombre/apellido y pequeñas diferencias de tipeo). No aplica nada, solo sugiere.
     *
     * @param array<int, array{id:int, nombre:string}> $players
     * @return array{player_id:int, nombre:string, score:float}|null
     */
    public static function suggest(string $rawName, array $players, float $threshold = 72.0): ?array
    {
        $normalizedRaw = self::normalize($rawName);
        if ($normalizedRaw === '') {
            return null;
        }

        $best = null;
        $bestScore = 0.0;

        foreach ($players as $player) {
            $normalizedPlayer = self::normalize($player['nombre']);
            similar_text($normalizedRaw, $normalizedPlayer, $percent);
            if ($percent > $bestScore) {
                $bestScore = $percent;
                $best = $player;
            }
        }

        if ($best === null || $bestScore < $threshold) {
            return null;
        }

        return ['player_id' => (int) $best['id'], 'nombre' => $best['nombre'], 'score' => round($bestScore, 1)];
    }
}
