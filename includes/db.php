<?php
/**
 * Conexión PDO única (singleton) a MySQL.
 */
require_once __DIR__ . '/../config/config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        // Soporte de socket Unix. Prioridad:
        // 1) Constante DB_SOCKET (definida en config o por variable de entorno)
        // 2) Socket por defecto de MariaDB/MySQL si existe
        // 3) TCP host:port
        $socket = (defined('DB_SOCKET') && DB_SOCKET) ? DB_SOCKET
                : (getenv('DB_SOCKET') ?: '');
        if (!$socket) {
            // Detectar socket por defecto automáticamente
            foreach (['/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock', '/tmp/mysql.sock'] as $s) {
                if (file_exists($s)) { $socket = $s; break; }
            }
        }
        $dsn = $socket
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, DB_NAME)
            : sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            if (APP_DEBUG) {
                die('Error de conexión a la base de datos: ' . $e->getMessage());
            }
            die('No fue posible conectar con la base de datos. Verifique la configuración en config/config.php.');
        }
    }

    return $pdo;
}
