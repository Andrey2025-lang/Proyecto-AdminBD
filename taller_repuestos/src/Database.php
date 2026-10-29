<?php
declare(strict_types=1);

function database(): PDO
{
    if (!extension_loaded('pdo_sqlsrv')) {
        throw new RuntimeException('Falta la extensión PDO_SQLSRV de PHP. Consulte README.md para instalarla.');
    }
    $file = dirname(__DIR__) . '/config.php';
    if (!is_file($file)) {
        throw new RuntimeException('Copie config.example.php como config.php y configure su instancia de SQL Server.');
    }
    $config = require $file;
    $dsn = 'sqlsrv:Server=' . $config['server'] . ';Database=' . $config['database']
        . ';Encrypt=1;TrustServerCertificate=' . (!empty($config['trust_server_certificate']) ? '1' : '0');
    try {
        return new PDO($dsn, !empty($config['integrated_auth']) ? null : $config['username'],
            !empty($config['integrated_auth']) ? null : $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
            ]);
    } catch (PDOException $e) {
        error_log($e->getMessage());
        throw new RuntimeException('No se pudo conectar con SQL Server. Verifique config.php, la instancia, sus permisos y que exista TallerRepuestosDB.');
    }
}

function rows(PDO $db, string $sql, array $params = []): array
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function run(PDO $db, string $sql, array $params = []): void
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    // Los procedimientos de SQL Server pueden devolver varios resultados.
    while ($stmt->nextRowset()) {}
    $stmt->closeCursor();
}
