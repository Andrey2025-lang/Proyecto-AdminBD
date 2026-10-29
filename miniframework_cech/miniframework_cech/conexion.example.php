<?php
// Plantilla del ejemplo MySQL del profesor, no utilizada por taller_repuestos.
// Copiar como conexion.php y configurar localmente si se desea ejecutar ese ejemplo.
$host = 'localhost';
$puerto = '3307';
$bd = 'db_comercial';
$usuario = 'USUARIO_MYSQL';
$clave = '';

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$puerto;dbname=$bd;charset=utf8mb4",
        $usuario,
        $clave,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    exit('No se pudo conectar con la base del ejemplo. Revise conexion.php.');
}
