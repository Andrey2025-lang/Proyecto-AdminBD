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
        $db = new PDO($dsn, !empty($config['integrated_auth']) ? null : $config['username'],
            !empty($config['integrated_auth']) ? null : $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
            ]);
        $db->exec('SET ANSI_NULLS ON; SET ANSI_PADDING ON; SET ANSI_WARNINGS ON; SET ARITHABORT ON; SET CONCAT_NULL_YIELDS_NULL ON; SET QUOTED_IDENTIFIER ON; SET NUMERIC_ROUNDABORT OFF;');
        return $db;
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

function databaseErrorMessage(Throwable $error, string $action): string
{
    // PDO_SQLSRV entrega el número nativo en errorInfo[1]; no necesariamente
    // aparece en getMessage(). UNIQUE sigue protegiendo ante envíos simultáneos.
    $code = $error instanceof PDOException ? (int)($error->errorInfo[1] ?? 0) : 0;
    if (in_array($code, [2601, 2627], true)) {
        return match ($action) {
            'part' => 'Ya existe un repuesto con ese código de barras. Use otro código o edite el repuesto existente.',
            'client' => 'Ya existe un cliente con esa cédula. Use otra cédula o edite el cliente existente.',
            'category' => 'Ya existe una categoría con ese nombre. Selecciónela en la lista.',
            default => 'Ya existe un registro con esos datos únicos. Revise la información.',
        };
    }
    return match ($code) {
        547 => match ($action) {
            'part' => 'La categoría seleccionada ya no está disponible. Seleccione una categoría válida.',
            'sale' => 'El cliente, vendedor o repuesto seleccionado ya no está disponible. Revise la selección.',
            'purchase' => 'El proveedor o repuesto seleccionado ya no está disponible. Revise la selección.',
            default => 'Los datos no cumplen las relaciones o restricciones de la base de datos.',
        },
        515 => 'Faltan datos obligatorios. Complete todos los campos requeridos.',
        8152, 2628 => 'Uno de los textos supera la longitud permitida. Revise los campos.',
        8115 => 'Uno de los importes o cantidades supera el máximo permitido.',
        1205 => 'Otra operación estaba modificando los mismos datos. Intente nuevamente.',
        50001, 50004 => 'Existencias insuficientes para completar la venta.',
        50002 => 'No está configurado el responsable de las compras. Contacte al administrador.',
        50003 => 'La cantidad o el precio indicado no es válido.',
        default => in_array($action, ['sale', 'purchase'], true)
            ? 'No se pudo completar la operación. Verifique la conexión e intente nuevamente; no se guardaron cambios parciales en la compra o venta.'
            : 'No se pudo guardar el registro. Verifique los datos y la conexión e intente nuevamente.',
    };
}
