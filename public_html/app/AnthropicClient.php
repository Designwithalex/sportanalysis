<?php

class AnthropicClient
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const MODEL = 'claude-sonnet-4-5';

    /**
     * @return string el texto de la respuesta (primer bloque de tipo "text")
     */
    public static function complete(string $systemPrompt, string $userPrompt, int $maxTokens = 4096): string
    {
        // JSON_INVALID_UTF8_SUBSTITUTE: si el prompt trae algún byte no-UTF8 (ej. un header de CSV en
        // Latin-1, o texto pegado), sustituye el byte en vez de que json_encode devuelva false y se
        // mande un body vacío (que Anthropic rechaza con un 400 confuso de "body vacío").
        $payload = json_encode([
            'model' => self::MODEL,
            'max_tokens' => $maxTokens,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($payload === false) {
            throw new RuntimeException('No se pudo construir la solicitud para la IA: ' . json_last_error_msg());
        }

        // La API a veces devuelve 529 (Overloaded) o 429 (rate limit) de forma transitoria.
        // Reintentamos unas pocas veces con backoff antes de rendirnos.
        $maxAttempts = 4;
        $response = false;
        $curlError = '';
        $httpCode = 0;
        $decoded = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $ch = curl_init(self::API_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_TIMEOUT => 90,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'x-api-key: ' . ANTHROPIC_API_KEY,
                    'anthropic-version: ' . self::API_VERSION,
                ],
            ]);

            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($response === false) {
                if ($attempt < $maxAttempts) {
                    sleep($attempt * 2);
                    continue;
                }
                throw new RuntimeException('Error de conexión con Anthropic: ' . $curlError);
            }

            $decoded = json_decode($response, true);

            if (($httpCode === 529 || $httpCode === 429 || $httpCode >= 500) && $attempt < $maxAttempts) {
                sleep($attempt * 2); // backoff: 2s, 4s, 6s
                continue;
            }
            break;
        }

        if ($httpCode !== 200) {
            $message = $decoded['error']['message'] ?? $response;
            throw new RuntimeException("Error de la API de Anthropic ($httpCode): $message");
        }

        $text = $decoded['content'][0]['text'] ?? null;
        if ($text === null) {
            throw new RuntimeException('Respuesta inesperada de Anthropic: sin contenido de texto.');
        }

        return $text;
    }

    /**
     * Extrae el primer bloque JSON de un texto (la IA a veces lo envuelve en ```json ... ``` o texto extra).
     */
    public static function extractJson(string $text): array
    {
        $text = trim($text);
        if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $text, $m)) {
            $text = $m[1];
        }

        $start = strpos($text, '[') === false
            ? strpos($text, '{')
            : (strpos($text, '{') === false ? strpos($text, '[') : min(strpos($text, '{'), strpos($text, '[')));

        if ($start === false) {
            throw new RuntimeException('No se encontró JSON en la respuesta de la IA.');
        }

        $candidate = substr($text, $start);
        $decoded = json_decode($candidate, true);

        if ($decoded === null) {
            throw new RuntimeException('El JSON devuelto por la IA no se pudo interpretar: ' . json_last_error_msg());
        }

        return $decoded;
    }
}
