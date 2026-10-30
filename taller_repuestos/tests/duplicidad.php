<?php
declare(strict_types=1);
// Pruebas reales con registros propios TEST-*. Se eliminan únicamente esos
// registros al terminar. Los ID pueden avanzar por las inserciones y rechazos.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/Actions.php';

if (($argv[1] ?? '') === '--worker') {
    $input = json_decode(base64_decode($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    $_SESSION = ['operation_secret'=>$input['secret']];
    $_POST = $input['post'];
    echo action(database());
    exit;
}

$db = database();
$_SESSION = ['operation_secret'=>bin2hex(random_bytes(32))];
$prefix = 'TEST-' . bin2hex(random_bytes(5));
$tokens = [];
$category = $part = $client = $supplier = null;
$passed = 0;
function verifyDuplicate(bool $ok, string $label): void
{
    global $passed;
    if (!$ok) throw new RuntimeException('FALLÓ: ' . $label);
    echo 'OK: ' . $label . PHP_EOL;
    $passed++;
}
function executePost(PDO $db, array $post): string { $_POST = $post; return action($db); }
function signedPost(array $data): array
{
    global $tokens;
    $token = bin2hex(random_bytes(32));
    $tokens[] = $token;
    return array_replace($data, ['operation_token'=>$token, 'operation_signature'=>operationSignature($data['action'], $token, $_SESSION['operation_secret'])]);
}
function expectRejection(PDO $db, array $post, string $message): void
{
    try { executePost($db, $post); }
    catch (InvalidArgumentException $e) {
        verifyDuplicate(str_contains($e->getMessage(), $message), 'rechazo: ' . $message);
        return;
    }
    throw new RuntimeException('Se aceptó una petición inválida.');
}
function snapshotDuplicate(PDO $db, int $part): array
{
    return rows($db, 'SELECT stock_actual,
        (SELECT COUNT(*) FROM RP10_DETALLE_COMPRAS WHERE id_repuesto=?) compras,
        (SELECT COUNT(*) FROM RP08_DETALLE_VENTAS WHERE id_repuesto=?) ventas,
        (SELECT COUNT(*) FROM RP11_INVENTARIO_HISTORIAL WHERE id_repuesto=?) movimientos
        FROM RP03_REPUESTOS WHERE id_repuesto=?', [$part,$part,$part,$part])[0];
}
function parallelPosts(array $post): array
{
    $encoded = base64_encode(json_encode(['post'=>$post,'secret'=>$_SESSION['operation_secret']], JSON_THROW_ON_ERROR));
    $processes = [];
    for ($i=0; $i<2; $i++) {
        $process = proc_open([PHP_BINARY, __FILE__, '--worker', $encoded], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('No se pudo abrir la conexión de prueba concurrente.');
        fclose($pipes[0]);
        $processes[] = [$process,$pipes];
    }
    $results = [];
    foreach ($processes as [$process,$pipes]) {
        $out = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException('Falló el proceso concurrente: ' . $error . $out);
        $results[] = $out;
    }
    return $results;
}

try {
    executePost($db, ['action'=>'category','nombre'=>$prefix]);
    $category = (int)rows($db, 'SELECT id_categoria FROM RP01_CATEGORIAS WHERE nombre=?', [$prefix])[0]['id_categoria'];
    expectRejection($db, ['action'=>'category','nombre'=>'  ' . strtolower($prefix) . '  '], 'Ya existe una categoría');
    try {
        run($db, 'INSERT INTO RP01_CATEGORIAS(nombre) VALUES (?)', ['  ' . strtolower($prefix) . '  ']);
        throw new RuntimeException('SQL Server permitió duplicar la categoría.');
    } catch (PDOException $e) {
        verifyDuplicate(in_array((int)($e->errorInfo[1] ?? 0), [2601,2627], true), 'categoría duplicada bloqueada directamente en SQL Server');
        verifyDuplicate(str_contains(databaseErrorMessage($e,'category'), 'Ya existe una categoría'), 'mensaje específico de categoría duplicada');
    }
    try {
        run($db, "INSERT INTO RP01_CATEGORIAS(nombre) VALUES ('   ')");
        throw new RuntimeException('SQL Server permitió la categoría vacía.');
    } catch (PDOException $e) {
        verifyDuplicate((int)($e->errorInfo[1] ?? 0) === 547, 'categoría vacía bloqueada directamente en SQL Server');
    }
    executePost($db, ['action'=>'part','id'=>'0','codigo_barras'=>$prefix,'nombre'=>$prefix,'id_categoria'=>$category,
        'precio_compra'=>'10.00','precio_venta'=>'20.00','stock_minimo'=>'0','estado'=>'ACTIVO']);
    $part = (int)rows($db, 'SELECT id_repuesto FROM RP03_REPUESTOS WHERE codigo_barras=?', [$prefix])[0]['id_repuesto'];
    executePost($db, ['action'=>'client','id'=>'0','cedula'=>$prefix,'nombre'=>$prefix,'telefono'=>'8888-0000','email'=>'']);
    $client = (int)rows($db, 'SELECT id_cliente FROM RP05_CLIENTES WHERE cedula=?', [$prefix])[0]['id_cliente'];
    executePost($db, ['action'=>'supplier','id'=>'0','nombre_empresa'=>$prefix,'contacto'=>$prefix,'telefono'=>'2222-0000','email'=>$prefix . '@example.com']);
    $supplier = (int)rows($db, 'SELECT id_proveedor FROM RP02_PROVEEDORES WHERE nombre_empresa=?', [$prefix])[0]['id_proveedor'];
    $seller = rows($db, "SELECT TOP (1) usuario_id FROM RP13_USUARIOS WHERE estado='ACTIVO' AND nombre_usuario<>'sistema' ORDER BY usuario_id")[0]['usuario_id'] ?? null;
    if (!$seller) throw new RuntimeException('Se necesita un vendedor activo para las pruebas.');

    $purchase = signedPost(['action'=>'purchase','id_repuesto'=>$part,'cantidad'=>6,'id_proveedor'=>$supplier,'precio_unitario'=>'10.00']);
    executePost($db, $purchase);
    $after = snapshotDuplicate($db, $part);
    verifyDuplicate((int)$after['stock_actual'] === 6 && (int)$after['compras'] === 1, 'compra inicial registrada una sola vez');
    verifyDuplicate(str_contains(executePost($db, $purchase), 'ya fue registrada'), 'reenvío de compra reconocido');
    verifyDuplicate(snapshotDuplicate($db, $part) === $after, 'reenvío no duplica compra, existencias ni historial');
    expectRejection($db, array_replace($purchase,['cantidad'=>7]), 'otros datos');
    expectRejection($db, array_replace($purchase,['operation_signature'=>str_repeat('0',64)]), 'formulario');
    executePost($db, signedPost(['action'=>'purchase','id_repuesto'=>$part,'cantidad'=>6,'id_proveedor'=>$supplier,'precio_unitario'=>'10.00']));
    verifyDuplicate((int)snapshotDuplicate($db,$part)['stock_actual'] === 12, 'otra solicitud con los mismos datos es una compra legítima');

    $sale = signedPost(['action'=>'sale','id_repuesto'=>$part,'cantidad'=>2,'id_cliente'=>$client,'id_vendedor'=>$seller]);
    executePost($db, $sale);
    $after = snapshotDuplicate($db,$part);
    verifyDuplicate(str_contains(executePost($db, $sale), 'ya fue registrada'), 'reenvío de venta reconocido');
    verifyDuplicate(snapshotDuplicate($db,$part) === $after, 'reenvío no duplica venta, descuento ni historial');
    $failed = signedPost(array_replace($sale,['cantidad'=>999]));
    // signedPost genera una clave nueva aunque los datos originales tengan clave.
    expectRejection($db, $failed, 'Existencias insuficientes');
    verifyDuplicate(!rows($db,'SELECT solicitud FROM RP14_SOLICITUDES_OPERACION WHERE solicitud=?',[$failed['operation_token']]), 'el error revierte también la clave de solicitud');
    executePost($db, array_replace($failed,['cantidad'=>1]));
    verifyDuplicate((int)snapshotDuplicate($db,$part)['stock_actual'] === 9, 'el formulario rechazado permite corregir y reintentar');

    $concurrent = signedPost(['action'=>'sale','id_repuesto'=>$part,'cantidad'=>1,'id_cliente'=>$client,'id_vendedor'=>$seller]);
    $results = parallelPosts($concurrent);
    $after = snapshotDuplicate($db,$part);
    verifyDuplicate((int)$after['stock_actual'] === 8 && (int)$after['ventas'] === 3 && (int)$after['movimientos'] === 5,
        'dos conexiones simultáneas generan una sola venta y un solo movimiento');
    verifyDuplicate(count(array_filter($results, static fn($message) => str_contains($message, 'ya fue registrada'))) === 1,
        'la conexión repetida recibe confirmación de operación ya registrada');
    $concurrent = signedPost(['action'=>'purchase','id_repuesto'=>$part,'cantidad'=>1,'id_proveedor'=>$supplier,'precio_unitario'=>'10.00']);
    parallelPosts($concurrent);
    $after = snapshotDuplicate($db,$part);
    verifyDuplicate((int)$after['stock_actual'] === 9 && (int)$after['compras'] === 3 && (int)$after['movimientos'] === 6,
        'dos conexiones simultáneas generan una sola compra y una sola entrada');
} finally {
    // Limpieza acotada a los IDs y claves generados por esta ejecución.
    $db->beginTransaction();
    try {
        if ($client) {
            run($db, 'DELETE FROM RP08_DETALLE_VENTAS WHERE id_venta IN (SELECT id_venta FROM RP07_VENTAS WHERE id_cliente=?)', [$client]);
            run($db, 'DELETE FROM RP07_VENTAS WHERE id_cliente=?', [$client]);
        }
        if ($supplier) {
            run($db, 'DELETE FROM RP10_DETALLE_COMPRAS WHERE id_compra IN (SELECT id_compra FROM RP09_COMPRAS WHERE id_proveedor=?)', [$supplier]);
            run($db, 'DELETE FROM RP09_COMPRAS WHERE id_proveedor=?', [$supplier]);
        }
        if ($part) {
            run($db, 'DELETE FROM RP11_INVENTARIO_HISTORIAL WHERE id_repuesto=?', [$part]);
            run($db, 'DELETE FROM RP03_REPUESTOS WHERE id_repuesto=?', [$part]);
        }
        if ($category) run($db,'DELETE FROM RP01_CATEGORIAS WHERE id_categoria=?',[$category]);
        if ($client) run($db,'DELETE FROM RP05_CLIENTES WHERE id_cliente=?',[$client]);
        if ($supplier) run($db,'DELETE FROM RP02_PROVEEDORES WHERE id_proveedor=?',[$supplier]);
        foreach ($tokens as $token) run($db,'DELETE FROM RP14_SOLICITUDES_OPERACION WHERE solicitud=?',[$token]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}
echo $passed . ' comprobaciones correctas. Se limpiaron únicamente los datos temporales de esta ejecución.' . PHP_EOL;
