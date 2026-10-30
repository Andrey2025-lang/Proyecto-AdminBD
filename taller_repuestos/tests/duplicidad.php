<?php
declare(strict_types=1);
// Pruebas reales con registros propios TEST-*. Se eliminan únicamente esos
// registros al terminar. Los ID pueden avanzar por las inserciones y rechazos.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/Actions.php';
require dirname(__DIR__) . '/src/Operations.php';

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
$category = $part = $second = $client = $supplier = null;
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
    expectRejection($db,$purchase,'no tiene habilitado');
    verifyDuplicate((int)snapshotDuplicate($db,$part)['stock_actual']===0,'proveedor sin catálogo no modifica inventario');
    expectRejection($db,['action'=>'supplier_part','id_proveedor'=>$supplier,'catalog_part'=>$part,'estado'=>'ACTIVO','costo_referencia'=>'10.00','codigo_proveedor'=>''],'Confirme');
    executePost($db,['action'=>'supplier_part','id_proveedor'=>$supplier,'catalog_part'=>$part,'estado'=>'ACTIVO','costo_referencia'=>'10.00','codigo_proveedor'=>'','confirmar_oferta'=>'1']);
    executePost($db, $purchase);
    $initialDoc=operationDocument($db,'compras',$GLOBALS['completedOperation']['id']);
    verifyDuplicate($initialDoc['header']['base_iva']===null&&$initialDoc['header']['monto_iva']===null,'compra sin confirmación no inventa un desglose del IVA');
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

    executePost($db,['action'=>'part','id'=>0,'codigo_barras'=>$prefix.'B','nombre'=>$prefix.' B','id_categoria'=>$category,
        'precio_compra'=>'5.00','precio_venta'=>'15.00','stock_minimo'=>0,'estado'=>'ACTIVO']);
    $second=(int)rows($db,'SELECT id_repuesto FROM RP03_REPUESTOS WHERE codigo_barras=?',[$prefix.'B'])[0]['id_repuesto'];
    $multiple=signedPost(['action'=>'purchase','id_proveedor'=>$supplier,'lines'=>json_encode([
        ['id_repuesto'=>$part,'cantidad'=>2,'precio_unitario'=>'9.00'],
        ['id_repuesto'=>$part,'cantidad'=>3,'precio_unitario'=>'10.00'],
        ['id_repuesto'=>$second,'cantidad'=>8,'precio_unitario'=>'5.00']])]);
    $tax=rows($db,'SELECT T.id_tarifa,T.porcentaje FROM RP21_CONFIG_FISCAL C JOIN RP20_TARIFAS_IVA T ON T.id_tarifa=C.tarifa_venta WHERE C.id=1')[0];
    $multiple['tarifa_compra']=$tax['id_tarifa'];
    expectRejection($db,$multiple,'Confirme');
    $multiple['confirmar_iva']='1';
    $beforeCatalog=[snapshotDuplicate($db,$part),snapshotDuplicate($db,$second)];
    expectRejection($db,$multiple,'no tiene habilitado');
    verifyDuplicate([snapshotDuplicate($db,$part),snapshotDuplicate($db,$second)]===$beforeCatalog,'una relación inválida revierte toda la compra, no solo ese repuesto');
    executePost($db,['action'=>'supplier_part','id_proveedor'=>$supplier,'catalog_part'=>$second,'estado'=>'ACTIVO','costo_referencia'=>'5.00','codigo_proveedor'=>'','confirmar_oferta'=>'1']);
    executePost($db,$multiple); $purchaseId=$GLOBALS['completedOperation']['id'];
    $doc=operationDocument($db,'compras',$purchaseId);
    verifyDuplicate(count($doc['lines'])===3 && $doc['consistent'] && (float)$doc['header']['total']===88.0,'una compra con tres partidas, dos repuestos y total correcto');
    $base=array_sum(array_map(static fn($line)=>round((float)$line['subtotal']/(1+(float)$tax['porcentaje']/100),2),$doc['lines']));
    verifyDuplicate(abs((float)$doc['header']['base_iva']-$base)<0.005&&abs((float)$doc['header']['monto_iva']-(88-$base))<0.005,'IVA de compra se extrae del total ya incluido sin cobro adicional');
    $beforeTax=[snapshotDuplicate($db,$part),snapshotDuplicate($db,$second)];
    expectRejection($db,signedPost(array_replace($multiple,['tarifa_compra'=>2147483647])),'desglose del IVA');
    verifyDuplicate([snapshotDuplicate($db,$part),snapshotDuplicate($db,$second)]===$beforeTax,'tarifa inválida revierte compra completa, stock y movimientos');
    verifyDuplicate((int)snapshotDuplicate($db,$part)['stock_actual']===14 && (int)snapshotDuplicate($db,$second)['stock_actual']===8,'entrada acumulada de partidas del mismo repuesto');
    $multiple=signedPost(['action'=>'sale','id_cliente'=>$client,'id_vendedor'=>$seller,'lines'=>json_encode([
        ['id_repuesto'=>$part,'cantidad'=>2,'precio_esperado'=>'20.00'],
        ['id_repuesto'=>$second,'cantidad'=>3,'precio_esperado'=>'15.00'],
        ['id_repuesto'=>$part,'cantidad'=>1,'precio_esperado'=>'20.00']])]);
    executePost($db,$multiple);$saleId=$GLOBALS['completedOperation']['id'];
    $doc=operationDocument($db,'ventas',$saleId);
    verifyDuplicate(count($doc['lines'])===2 && $doc['units']===6 && $doc['consistent'] && (float)$doc['header']['total']===105.0,'una venta agrupa repuestos repetidos y conserva cantidades, precios y total');
    $base=array_sum(array_map(static fn($line)=>round((float)$line['subtotal']/(1+(float)$tax['porcentaje']/100),2),$doc['lines']));
    verifyDuplicate(abs((float)$doc['header']['base_iva']-$base)<0.005&&abs((float)$doc['header']['monto_iva']-(105-$base))<0.005,'procedimiento de venta guarda IVA incluido con redondeo por repuesto');
    $db->beginTransaction();
    try {
        run($db,'UPDATE RP20_TARIFAS_IVA SET porcentaje=porcentaje+1 WHERE id_tarifa=?',[$tax['id_tarifa']]);
        verifyDuplicate(operationDocument($db,'ventas',$saleId)===$doc,'cambiar una tarifa futura no recalcula el documento confirmado');
    } finally {$db->rollBack();}
    $before=[snapshotDuplicate($db,$part),snapshotDuplicate($db,$second)];
    verifyDuplicate(str_contains(executePost($db,$multiple),'ya fue registrada') && $GLOBALS['completedOperation']['id']===$saleId,'reenvío múltiple recupera el mismo comprobante');
    $failed=signedPost(array_replace($multiple,['lines'=>json_encode([
        ['id_repuesto'=>$part,'cantidad'=>1,'precio_esperado'=>'20.00'],['id_repuesto'=>$second,'cantidad'=>999,'precio_esperado'=>'15.00']])]));
    expectRejection($db,$failed,'Existencias insuficientes');
    verifyDuplicate([snapshotDuplicate($db,$part),snapshotDuplicate($db,$second)]===$before,'falta de stock en una partida revierte toda la venta y su historial');
    expectRejection($db,signedPost(array_replace($multiple,['lines'=>json_encode([
        ['id_repuesto'=>$part,'cantidad'=>1,'precio_esperado'=>'19.00'],['id_repuesto'=>$second,'cantidad'=>1,'precio_esperado'=>'15.00']])])),'precio cambió');
    expectRejection($db,signedPost(['action'=>'purchase','id_proveedor'=>$supplier,'lines'=>json_encode([
        ['id_repuesto'=>$part,'cantidad'=>1,'precio_unitario'=>'1.00'],['id_repuesto'=>$second,'cantidad'=>2,'precio_unitario'=>'99999999.99']])]),'total supera');
    verifyDuplicate([snapshotDuplicate($db,$part),snapshotDuplicate($db,$second)]===$before,'precio cambiado o compra excesiva no dejan cambios parciales');
    expectRejection($db,signedPost(array_replace($multiple,['lines'=>'[]'])),'entre 1 y 100');
    expectRejection($db,signedPost(array_replace($multiple,['lines'=>'{invalido}'])),'lista de repuestos');
    try {operationDocument($db,'ventas',2147483647);throw new RuntimeException('Se aceptó un comprobante inexistente.');}
    catch(OperationNotFound $e){verifyDuplicate(true,'comprobante inexistente rechazado');}

    $version=rows($db,'SELECT CONVERT(bigint,version) version FROM RP19_PROVEEDOR_REPUESTO WHERE id_proveedor=? AND id_repuesto=?',[$supplier,$second])[0]['version'];
    $editCatalog=['action'=>'supplier_part','id_proveedor'=>$supplier,'catalog_part'=>$second,'estado'=>'INACTIVO','costo_referencia'=>'7.50','codigo_proveedor'=>'REF-B','catalog_version'=>$version];
    executePost($db,$editCatalog);
    expectRejection($db,$editCatalog,'fue modificada');
    expectRejection($db,signedPost(['action'=>'purchase','id_proveedor'=>$supplier,'id_repuesto'=>$second,'cantidad'=>1,'precio_unitario'=>'5.00']),'no tiene habilitado');
    verifyDuplicate(operationDocument($db,'compras',$purchaseId)['consistent'],'deshabilitar una relación y cambiar su costo no modifica compras anteriores');
    $beforeCatalog=snapshotDuplicate($db,$second);
    $db->beginTransaction();
    try {
        $header=rows($db,"INSERT RP09_COMPRAS(id_proveedor,total,estado) OUTPUT INSERTED.id_compra VALUES(?,5,'RECIBIDO')",[$supplier])[0]['id_compra'];
        run($db,'INSERT RP10_DETALLE_COMPRAS(id_compra,id_repuesto,cantidad,precio_unitario,subtotal) VALUES(?,?,1,5,5)',[$header,$second]);
        throw new RuntimeException('SQL directo permitió un producto deshabilitado.');
    } catch(PDOException $e) {
        verifyDuplicate((int)($e->errorInfo[1]??0)===50016,'la base también bloquea compras fuera del catálogo mediante SQL directo');
    } finally {if($db->inTransaction())$db->rollBack();}
    verifyDuplicate(snapshotDuplicate($db,$second)===$beforeCatalog,'rechazo desde SQL revierte stock e historial');
    $editCatalog['catalog_version']=rows($db,'SELECT CONVERT(bigint,version) version FROM RP19_PROVEEDOR_REPUESTO WHERE id_proveedor=? AND id_repuesto=?',[$supplier,$second])[0]['version'];
    executePost($db,array_replace($editCatalog,['estado'=>'ACTIVO','confirmar_oferta'=>'1']));

    if(in_array('--http',$argv,true)) {
        // Flujo real con cookie de sesión, CSRF y respuesta parcial de la aplicación.
        $cookie='';
        $http=static function(string $page,?array $post=null) use(&$cookie): array {
            $headers="Cookie: $cookie\r\nHX-Request: true\r\n";
            if($post!==null)$headers.="Content-Type: application/x-www-form-urlencoded\r\n";
            $context=stream_context_create(['http'=>['method'=>$post===null?'GET':'POST','header'=>$headers,'content'=>$post===null?'':http_build_query($post),'ignore_errors'=>true,'timeout'=>20]]);
            $body=file_get_contents('http://127.0.0.1:8080/taller_repuestos/index.php?page='.$page,false,$context);
            if($body===false)throw new RuntimeException('No se pudo acceder al servidor HTTP de pruebas.');
            foreach($http_response_header as $header)if(preg_match('/^Set-Cookie:\s*([^;]+)/i',$header,$m))$cookie=$m[1];
            preg_match('/\s(\d{3})\s/',$http_response_header[0],$status);
            return [(int)$status[1],$body];
        };
        foreach(['purchase'=>'compras','sale'=>'ventas'] as $operation=>$page) {
            [$status,$html]=$http($page); $fields=[];
            foreach(['csrf','operation_token','operation_signature'] as $field) {
                if(!preg_match('/name="'.$field.'" value="([^"]+)"/',$html,$match))throw new RuntimeException('Falta el campo HTTP '.$field);
                $fields[$field]=html_entity_decode($match[1],ENT_QUOTES,'UTF-8');
            }
            $tokens[]=$fields['operation_token'];
            $fields+=['action'=>$operation,'id_cliente'=>$client,'id_proveedor'=>$supplier,'id_vendedor'=>$seller,
                'lines'=>json_encode([['id_repuesto'=>$part,'cantidad'=>1,'precio_unitario'=>'10.00','precio_esperado'=>'20.00'],['id_repuesto'=>$second,'cantidad'=>1,'precio_unitario'=>'5.00','precio_esperado'=>'15.00']])];
            if($operation==='purchase')$fields+=['tarifa_compra'=>$tax['id_tarifa'],'confirmar_iva'=>'1'];
            [$status,$html]=$http($page,$fields);
            verifyDuplicate($status===200 && preg_match('/data-open-operation data-kind="'.$page.'" data-id="(\d+)"/',$html,$match)===1,'HTTP '.$page.' confirma varias partidas y abre su comprobante');
            $id=(int)$match[1];$doc=operationDocument($db,$page,$id);
            verifyDuplicate(count($doc['lines'])===2 && $doc['consistent'],'HTTP '.$page.' guarda un encabezado consistente con dos partidas');
            [$status,$receipt]=$http($page.'&view_operation='.$page.'&id='.$id);
            verifyDuplicate($status===200&&str_contains($receipt,'Los precios incluyen IVA')&&str_contains($receipt,'Monto antes del IVA'),'HTTP detalle '.$page.' muestra IVA incluido sin sumarlo de nuevo');
            [$status,$receipt]=$http($page.'&receipt='.$page.'&id='.$id.'&format=print');
            verifyDuplicate($status===200&&str_contains($receipt,'Los precios incluyen IVA')&&str_contains($receipt,'IVA incluido'),'HTTP comprobante imprimible '.$page.' muestra el desglose');
            [$status,$receipt]=$http($page.'&receipt='.$page.'&id='.$id.'&format=xlsx');
            verifyDuplicate($status===200&&str_starts_with($receipt,'PK'),'HTTP Excel '.$page.' con desglose fiscal válido');
            [$status,$html]=$http($page,$fields);
            verifyDuplicate($status===200 && str_contains($html,'data-id="'.$id.'"') && str_contains($html,'ya fue registrada'),'HTTP '.$page.' reenvía sin duplicar y recupera la misma referencia');
        }
    }
} finally {
    // Limpieza acotada a los IDs y claves generados por esta ejecución.
    $db->beginTransaction();
    try {
        if ($client) {
            run($db, 'DELETE FROM RP08_DETALLE_VENTAS WHERE id_venta IN (SELECT id_venta FROM RP07_VENTAS WHERE id_cliente=?)', [$client]);
            run($db, 'DELETE FROM RP07_VENTAS WHERE id_cliente=?', [$client]);
        }
        if ($supplier) {
            run($db,'DELETE FROM RP19_PROVEEDOR_REPUESTO WHERE id_proveedor=?',[$supplier]);
            run($db, 'DELETE FROM RP10_DETALLE_COMPRAS WHERE id_compra IN (SELECT id_compra FROM RP09_COMPRAS WHERE id_proveedor=?)', [$supplier]);
            run($db, 'DELETE FROM RP09_COMPRAS WHERE id_proveedor=?', [$supplier]);
        }
        foreach(array_filter([$part,$second]) as $id) {
            run($db, 'DELETE FROM RP11_INVENTARIO_HISTORIAL WHERE id_repuesto=?', [$id]);
            run($db, 'DELETE FROM RP03_REPUESTOS WHERE id_repuesto=?', [$id]);
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
