<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Actions.php';
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
echo $passed . ' comprobaciones correctas.' . PHP_EOL;
