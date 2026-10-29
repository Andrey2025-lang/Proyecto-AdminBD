<?php
declare(strict_types=1);
// Pruebas reales de catálogos. Todas las escrituras se revierten al finalizar.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/Actions.php';
$db = database();
$passed = 0;
function verify(bool $condition, string $label): void
{
    global $passed;
    if (!$condition) throw new RuntimeException('FALLÓ: ' . $label);
    $passed++;
    echo 'OK: ' . $label . PHP_EOL;
}
function expectInvalid(PDO $db, array $post, string $message): void
{
    $_POST = $post;
    try { action($db); }
    catch (InvalidArgumentException $e) {
        verify(str_contains($e->getMessage(), $message), $message);
        return;
    }
    throw new RuntimeException('No se rechazó el dato inválido: ' . $message);
}
$prefix = 'TEST-' . bin2hex(random_bytes(4));
$before = rows($db, 'SELECT (SELECT COUNT(*) FROM RP03_REPUESTOS) repuestos,(SELECT COUNT(*) FROM RP01_CATEGORIAS) categorias,(SELECT COUNT(*) FROM RP05_CLIENTES) clientes');
$db->beginTransaction();
try {
    $_POST = ['action'=>'category', 'nombre'=>$prefix];
    action($db);
    $category = (int)rows($db, 'SELECT id_categoria FROM RP01_CATEGORIAS WHERE nombre=?', [$prefix])[0]['id_categoria'];
    expectInvalid($db, ['action'=>'category', 'nombre'=>'  ' . $prefix . '  '], 'Ya existe una categoría');

    $first = ['action'=>'part', 'id'=>'0', 'codigo_barras'=>$prefix . '-1', 'nombre'=>'Repuesto de prueba',
        'id_categoria'=>(string)$category, 'precio_compra'=>'10.00','precio_venta'=>'20.00', 'stock_minimo'=>'2', 'estado'=>'ACTIVO'];
    $_POST = $first;
    action($db);
    $part = rows($db, 'SELECT * FROM RP03_REPUESTOS WHERE codigo_barras=?', [$first['codigo_barras']])[0];
    verify((int)$part['stock_actual'] === 0, 'alta válida con existencias cero');
    expectInvalid($db, $first, 'Ya existe un repuesto con ese código de barras');
    expectInvalid($db, array_replace($first, ['codigo_barras'=>'  ' . strtolower($first['codigo_barras']) . '  ']), 'Ya existe un repuesto');
    $_POST = array_replace($first, ['id'=>(string)$part['id_repuesto'], 'nombre'=>'Nombre editado']);
    action($db);
    $part = rows($db, 'SELECT * FROM RP03_REPUESTOS WHERE id_repuesto=?', [$part['id_repuesto']])[0];
    verify($part['nombre'] === 'Nombre editado', 'edición permite conservar el código del propio repuesto');

    $second = array_replace($first, ['codigo_barras'=>$prefix . '-2']);
    $_POST = $second;
    action($db);
    $other = rows($db, 'SELECT * FROM RP03_REPUESTOS WHERE codigo_barras=?', [$second['codigo_barras']])[0];
    expectInvalid($db, array_replace($first, ['id'=>(string)$other['id_repuesto']]), 'Ya existe un repuesto');
    verify(rows($db, 'SELECT * FROM RP03_REPUESTOS WHERE id_repuesto=?', [$other['id_repuesto']])[0] === $other,
        'edición duplicada no modifica el registro');
    expectInvalid($db, array_replace($first, ['codigo_barras'=>$prefix . '-3','id_categoria'=>'2147483647']), 'categoría seleccionada no existe');
    foreach (['precio_compra'=>'-1', 'precio_venta'=>'1.234', 'stock_minimo'=>'-1', 'nombre'=>'', 'estado'=>'OTRO', 'codigo_barras'=>str_repeat('1', 21)] as $field=>$value) {
        expectInvalid($db, array_replace($first, ['codigo_barras'=>$prefix . '-3'], [$field=>$value]),
            match ($field) { 'precio_compra','precio_venta'=>'precio', 'estado'=>'Estado', default=>str_replace('_', ' ', $field) });
    }

    $client = ['action'=>'client', 'id'=>'0', 'cedula'=>$prefix . '-C', 'nombre'=>'Cliente de prueba', 'telefono'=>'8888-0000', 'email'=>'prueba@example.com'];
    $_POST = $client;
    action($db);
    $clientId = rows($db, 'SELECT id_cliente FROM RP05_CLIENTES WHERE cedula=?', [$client['cedula']])[0]['id_cliente'];
    expectInvalid($db, $client, 'Ya existe un cliente con esa cédula');
    $_POST = array_replace($client, ['id'=>(string)$clientId, 'nombre'=>'Cliente editado']);
    action($db);
    verify(rows($db, 'SELECT nombre FROM RP05_CLIENTES WHERE id_cliente=?', [$clientId])[0]['nombre'] === 'Cliente editado', 'cliente puede conservar su propia cédula');
    expectInvalid($db, array_replace($client, ['email'=>'correo-invalido']), 'Correo electrónico inválido');

    // Saltar la comprobación previa deliberadamente para probar el último
    // resguardo de SQL Server (también cubre carreras entre dos peticiones).
    try {
        saveRecord($db, 'RP03_REPUESTOS', 'id_repuesto', [
            'codigo_barras'=>$first['codigo_barras'], 'nombre'=>'Duplicado', 'id_categoria'=>$category,
            'precio_compra'=>'1.00', 'precio_venta'=>'2.00', 'stock_actual'=>0,'stock_minimo'=>0,'estado'=>'ACTIVO',
        ], 0);
        throw new RuntimeException('SQL Server permitió duplicar el código.');
    } catch (PDOException $e) {
        verify(in_array((int)($e->errorInfo[1] ?? 0), [2601,2627], true), 'SQL Server bloquea duplicados aunque se omita la validación PHP');
        verify(str_contains(databaseErrorMessage($e, 'part'), 'código de barras'), 'error nativo real se transforma en mensaje claro');
    }
} finally {
    if ($db->inTransaction()) $db->rollBack();
}
$after = rows($db, 'SELECT (SELECT COUNT(*) FROM RP03_REPUESTOS) repuestos,(SELECT COUNT(*) FROM RP01_CATEGORIAS) categorias,(SELECT COUNT(*) FROM RP05_CLIENTES) clientes');
verify($before === $after, 'todos los registros temporales se revirtieron');
echo $passed . ' comprobaciones reales correctas, sin conservar registros de prueba.' . PHP_EOL;
