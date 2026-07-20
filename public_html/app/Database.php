<?php

class Database
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance === null) {
            self::$instance = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        }
        return self::$instance;
    }

    /**
     * Devuelve una conexión viva, reconectando si el servidor la cerró.
     * Necesario cuando una operación larga (ej: una llamada a la IA de ~60s) deja la conexión
     * ociosa el tiempo suficiente para que el hosting compartido la corte ("MySQL server has gone away").
     * Llamar justo antes de volver a tocar la DB después de una espera larga.
     */
    public static function ping(): PDO
    {
        try {
            self::get()->query('SELECT 1');
        } catch (PDOException $e) {
            self::$instance = null;
        }
        return self::get();
    }
}
