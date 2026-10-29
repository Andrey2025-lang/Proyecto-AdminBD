<?php
declare(strict_types=1);
// Pruebas de renderizado con fixtures: NO sustituyen pruebas de SQL Server.
require dirname(__DIR__) . '/src/Database.php';
require dirname(__DIR__) . '/src/Views.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

class FixtureStatement extends PDOStatement
{
    public function __construct(private array $data) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->data; }
}
class FixtureDatabase extends PDO
{
    public function __construct(private bool $empty) {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, ') partes,')) {
            return new FixtureStatement([['partes'=>4,'bajos'=>2,'vendido'=>'15000.00','clientes'=>1]]);
        }
        return new FixtureStatement($this->empty ? [] : [[
            'id'=>1, 'id_repuesto'=>1, 'id_categoria'=>1, 'id_cliente'=>1, 'id_proveedor'=>1,
            'id_venta'=>1, 'id_compra'=>1, 'usuario_id'=>1, 'nombre'=>'Filtro <prueba>',
            'codigo_barras'=>'DEMO-01', 'categoria'=>'Filtros', 'precio_compra'=>'3500.00',
            'precio_venta'=>'5500.00', 'stock_actual'=>10, 'stock_minimo'=>5, 'estado'=>'ACTIVO',
            'cedula'=>'DEMO', 'telefono'=>'0000', 'email'=>'demo@example.com',
            'nombre_empresa'=>'Empresa Demo', 'contacto'=>'Contacto', 'nombre_usuario'=>'vendedor_demo',
            'persona'=>'Cliente Demo', 'vendedor'=>'vendedor_demo', 'fecha'=>'2026-10-28 10:00:00',
            'total'=>'5500.00', 'cantidad'=>1, 'precio_unitario'=>'5500.00', 'subtotal'=>'5500.00',
            'tipo_movimiento'=>'SALIDA_VENTA', 'motivo'=>'Prueba',
        ]]);
    }
}
$_SESSION = ['csrf'=>'token-de-prueba'];
$count = 0;
foreach ([true, false] as $empty) {
    foreach (['inicio','repuestos','clientes','proveedores','compras','ventas','inventario'] as $page) {
        $_GET = ['edit'=>'1', 'detail'=>'1', 'q'=>'Filtro'];
        ob_start();
        renderPage(new FixtureDatabase($empty), $page);
        $html = ob_get_clean();
        if ($html === '' || str_contains($html, 'Filtro <prueba>')) throw new RuntimeException('Error de renderizado o escape: ' . $page);
        echo 'OK: ' . $page . ($empty ? ' vacío' : ' con registros') . PHP_EOL;
        $count++;
    }
}
$error = 'Validación de prueba';
$_POST = ['action'=>'part', 'id'=>'1', 'nombre'=>'Nombre corregido'];
$_GET = [];
ob_start();
partsPage(new FixtureDatabase(false));
$html = ob_get_clean();
if (!str_contains($html, 'value="Nombre corregido"') || !str_contains($html, 'name="id" value="1"')) {
    throw new RuntimeException('No se conservó la edición después de un error.');
}
echo 'OK: edición conservada tras error' . PHP_EOL;
echo ($count + 1) . ' comprobaciones de pantallas correctas (fixtures, sin SQL Server).' . PHP_EOL;
