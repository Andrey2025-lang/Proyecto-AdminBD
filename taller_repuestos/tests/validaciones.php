<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Actions.php';
require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/Views.php';
$passed = 0;
function check(bool $condition, string $label): void
{
    global $passed;
    if (!$condition) { fwrite(STDERR, 'FALLÓ: ' . $label . PHP_EOL); exit(1); }
    $passed++;
    echo 'OK: ' . $label . PHP_EOL;
}
function rejects(callable $callback): bool
{
    try { $callback(); } catch (InvalidArgumentException $e) { return true; }
    return false;
}
$_POST = ['precio'=>'1234.5', 'cantidad'=>'3', 'nombre'=>'  Filtro de aceite  '];
check(centsInput('precio') === 123450, 'precio a centavos sin error de coma flotante');
check(decimal(123450) === '1234.50', 'centavos a DECIMAL SQL');
check(integerInput('cantidad') === 3, 'cantidad positiva');
check(textInput('nombre', 150) === 'Filtro de aceite', 'texto normalizado');
foreach (['-1','1.234','1e3','100000000.00','abc', ['1']] as $bad) {
    $_POST['precio'] = $bad;
    check(rejects(fn() => centsInput('precio')), 'rechazo de precio inválido ' . json_encode($bad));
}
foreach (['0','-1','1.5','2147483648', ['1']] as $bad) {
    $_POST['cantidad'] = $bad;
    check(rejects(fn() => integerInput('cantidad')), 'rechazo de cantidad inválida ' . json_encode($bad));
}
$_POST = ['nombre'=>'', 'precio'=>'99999999.99', 'id'=>'0'];
check(rejects(fn() => textInput('nombre', 100)), 'nombre obligatorio');
check(centsInput('precio') === 9999999999, 'máximo DECIMAL(10,2)');
check(integerInput('id', 0) === 0, 'ID cero para registro nuevo');
check(e('<script>"&') === '&lt;script&gt;&quot;&amp;', 'escape HTML');
$_POST['nombre'] = str_repeat('á', 101);
check(rejects(fn() => textInput('nombre', 100)), 'límite Unicode de texto');
ob_start();
dashboardView(['partes'=>4,'bajos'=>2,'vendido'=>0,'clientes'=>1], [], []);
$html = ob_get_clean();
check(str_contains($html, 'Tu taller, en movimiento.'), 'renderizado de inicio');
check(str_contains($html, 'No hay alertas de existencias.'), 'estado vacío');
foreach ([2601, 2627] as $code) {
    $exception = new PDOException('Mensaje del controlador sin número nativo.');
    $exception->errorInfo = ['23000', $code, 'Duplicate key'];
    check(str_contains(databaseErrorMessage($exception, 'part'), 'código de barras'), 'error UNIQUE ' . $code . ' desde errorInfo');
    check(str_contains(databaseErrorMessage($exception, 'client'), 'cédula'), 'error de cédula ' . $code . ' desde errorInfo');
}
$exception = new PDOException('Foreign key');
$exception->errorInfo = ['23000', 547, 'Foreign key'];
check(str_contains(databaseErrorMessage($exception, 'part'), 'categoría'), 'error de categoría específico');
check(!str_contains(databaseErrorMessage(new RuntimeException('Error interno'), 'part'), 'compras o ventas'), 'error de catálogo sin mensaje de transacciones');
[$token, $signature] = operationCredentials('sale');
$_POST = ['action'=>'sale','operation_token'=>$token,'operation_signature'=>$signature];
check(validateOperationToken('sale') === $token, 'clave de operación firmada válida');
check(rejects(fn() => validateOperationToken('purchase')), 'clave no reutilizable para otro tipo de operación');
$_POST['operation_signature'] = str_repeat('0', 64);
check(rejects(fn() => validateOperationToken('sale')), 'firma manipulada rechazada');
$_POST = [];
check(rejects(fn() => validateOperationToken('sale')), 'solicitud sin clave rechazada');
$error = 'Error de validación';
$_POST = ['action'=>'sale','operation_token'=>$token,'operation_signature'=>$signature];
check(operationCredentials('sale') === [$token,$signature], 'se conserva la clave al corregir un formulario');
$error = null;
check(operationCredentials('sale')[0] !== $token, 'nuevo formulario obtiene una clave distinta');
check(str_starts_with(csvValue(' =SUM(A1:A2)','text'),"'"), 'CSV neutraliza fórmulas con espacios iniciales');
check(str_starts_with(csvValue("\t=1+1",'text'),"'"), 'CSV neutraliza fórmulas con tabulación');
check(csvValue('-10.50','money') === '-10.50', 'CSV conserva los importes numéricos');
check(str_contains(excelCell('A1','=1+1'),'t="inlineStr"') && !str_contains(excelCell('A1','=1+1'),'<f>'), 'Excel trata fórmulas del usuario como texto');
check(excelColumn(0)==='A' && excelColumn(26)==='AA', 'direcciones de columnas Excel');
$_GET=['desde'=>'2026-02-30'];
check(rejects(fn()=>listFilters()), 'filtro de fecha inexistente rechazado');
$_GET=['desde'=>'2026-10-20','hasta'=>'2026-10-01'];
check(rejects(fn()=>listFilters()), 'rango invertido rechazado');
$_GET=['tam'=>'10000'];
check(rejects(fn()=>listFilters()), 'tamaño de página limitado');
$_GET=[];
check(likeText('50%_[x]')==='%50~%~_~[x]%', 'comodines de búsqueda escapados como texto literal');
check(listFilters()['size']===15, '15 filas predeterminadas en listados');
$_GET=['page'=>'inventario'];
check(listFilters()['size']===10 && listFilters()['history_size']===10, '10 filas predeterminadas en existencias e historial');
$_GET=['page'=>'inventario','tam'=>'15','htam'=>'30'];
check(listFilters()['size']===15 && listFilters()['history_size']===30, 'tamaños independientes en inventario');
$_GET=[];
check(str_contains(excelCell('B6','2026-10-29 16:44:30','date'),'<v>') && !str_contains(excelCell('B6','2026-10-29 16:44:30','date'),'inlineStr'), 'fechas nativas en Excel');
check(phoneLabel('88880000')==='8888-0000' && phoneLabel('8888-0000')==='8888-0000','teléfonos locales con un formato uniforme');
check(phoneLabel('+506 88880000')==='+506 8888-0000','teléfono conserva el prefijo internacional');
check(phoneLabel('+1 202 555 0100')==='+1 202 555 0100','teléfonos extranjeros no se reinterpretan como locales');
check(reportValue('estado','COMPLETADA')==='Completada' && reportValue('tipo_movimiento','SALIDA_VENTA')==='Salida por venta','estados y movimientos comprensibles en todos los formatos');
check(reportTitles()['kardex']==='Entradas y salidas de un repuesto','reporte con nombre sencillo');
echo $passed . ' comprobaciones correctas.' . PHP_EOL;
