<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/**
 * Gestor de Conexión Segura a Base de Datos MySQL 8.0+ (PDO).
 *
 * Garantiza consultas preparadas nativas, soporte transaccional ACID estricto,
 * codificación utf8mb4 y retorno seguro de tipos DECIMAL para BCMath.
 */
final class Database
{
    private static ?PDO $instancia = null;

    private function __construct()
    {
    }

    /**
     * Retorna la instancia única de conexión PDO.
     *
     * @return PDO
     * @throws RuntimeException Si no es posible establecer conexión con MySQL.
     */
    public static function getConnection(): PDO
    {
        if (self::$instancia instanceof PDO) {
            return self::$instancia;
        }

        $host    = getenv('DB_HOST') ?: '127.0.0.1';
        $port    = getenv('DB_PORT') ?: '3306';
        $dbName  = getenv('DB_NAME') ?: 'sistema_contable_chile';
        $user    = getenv('DB_USER') ?: 'root';
        $pass    = getenv('DB_PASS') ?: '';
        $charset = 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset={$charset}";

        $opciones = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, sql_mode='STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
        ];

        try {
            self::$instancia = new PDO($dsn, $user, $pass, $opciones);
            return self::$instancia;
        } catch (PDOException $e) {
            error_log('Error de conexión PDO MySQL: ' . $e->getMessage());
            throw new RuntimeException('No se pudo conectar con el servidor de base de datos.');
        }
    }
}