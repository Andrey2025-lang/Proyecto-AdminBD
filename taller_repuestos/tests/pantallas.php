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
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool { return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->data; }
}
class FixtureDatabase extends PDO
{
    public function __construct(private bool $empty) {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if(str_contains($query,'RP21_CONFIG_FISCAL'))return new FixtureStatement([['porcentaje'=>'13.00','tarifa_venta'=>1]]);
        if(str_contains($query,'RP20_TARIFAS_IVA'))return new FixtureStatement([['id_tarifa'=>1,'nombre'=>'IVA general','porcentaje'=>'13.00']]);
        if(str_contains($query,'RP17_CONFIG_RESPALDOS'))return new FixtureStatement([['intervalo_manual_minutos'=>5,'intervalo_completo_horas'=>24,'intervalo_registro_minutos'=>30]]);
        if(str_contains($query,'RP16_RESPALDOS'))return new FixtureStatement($this->empty?[]:[
            ['id_respaldo'=>2,'inicio'=>'2026-10-30 10:30:00','fin'=>'2026-10-30 10:30:00','tipo'=>'REGISTRO','estado'=>'OMITIDO','origen'=>'AUTOMATICO','detalle'=>'Prueba de omisión'],
            ['id_respaldo'=>1,'inicio'=>'2026-10-30 10:00:00','fin'=>'2026-10-30 10:00:01','tipo'=>'COMPLETO','estado'=>'VERIFICADO','origen'=>'MANUAL','detalle'=>'Archivo comprobado'],
        ]);
        if (str_contains($query,'COUNT_BIG(*)')) return new FixtureStatement([['total'=>$this->empty?0:1,'importe'=>'5500.00']]);
        if (str_contains($query, ') partes,')) {
            return new FixtureStatement([['partes'=>4,'bajos'=>2,'vendido'=>'15000.00','clientes'=>1]]);
        }
        return new FixtureStatement($this->empty ? [] : [[
            'id'=>1, 'id_repuesto'=>1, 'id_categoria'=>1, 'id_cliente'=>1, 'id_proveedor'=>1,
            'id_venta'=>1, 'id_compra'=>1, 'usuario_id'=>1, 'nombre'=>'Filtro <prueba>', 'label'=>'Filtro <prueba>',
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
    foreach (['inicio','repuestos','clientes','proveedores','compras','ventas','inventario','reportes','respaldos'] as $page) {
        $_GET = ['edit'=>$empty?'0':'1', 'detail'=>'1', 'q'=>'Filtro'];
        ob_start();
        renderPage(new FixtureDatabase($empty), $page);
        $html = ob_get_clean();
        if ($html === '' || str_contains($html, 'Filtro <prueba>')) throw new RuntimeException('Error de renderizado o escape: ' . $page);
        if ($page==='compras' && (!str_contains($html,'data-purchase-discovery') || str_contains($html,'Sin desglose de IVA confirmado') || str_contains($html,'name="confirmar_iva"'))) throw new RuntimeException('La compra debe ofrecer búsqueda por repuesto e IVA siempre incluido.');
        if ($page==='clientes' && (!str_contains($html,'data-hacienda-identificacion') || !str_contains($html,'data-client-hacienda-panel') || !str_contains($html,'data-hacienda-activities'))) throw new RuntimeException('Clientes debe incluir los puntos de integración de Hacienda.');
        if ($page==='repuestos' && str_contains($html,'part_detail=')) throw new RuntimeException('Repuestos no debe incluir la consulta de proveedores.');
        if (preg_match('/prototipo|académico|entorno local|SQL Server|PHP|HTMX|Bulma|triggers|transaccional/iu', strip_tags($html))) {
            throw new RuntimeException('La pantalla contiene etiquetas técnicas: ' . $page);
        }
        if ($page === 'inicio' && (str_contains($html, 'Respaldo de la información') || !str_contains($html, 'class="button is-primary home-sale-cta"'))) {
            throw new RuntimeException('Inicio debe omitir respaldos y destacar Nueva venta.');
        }
        if ($page === 'respaldos' && (!str_contains($html, 'Respaldo de la información') || !str_contains($html, 'action="index.php?page=respaldos"'))) {
            throw new RuntimeException('La pantalla de respaldos o su formulario no se renderizaron.');
        }
        if ($page === 'respaldos' && !$empty && (!str_contains($html, 'Omitido (no requerido)') || !str_contains($html, 'Prueba de omisión'))) {
            throw new RuntimeException('El historial de respaldos debe explicar las ejecuciones omitidas.');
        }
        if (in_array($page, ['ventas','compras'], true)) {
            $containerPosition=strpos($html, 'class="panel-card operations-layout operations-entry-layout"');
            $infoPosition=strpos($html, 'class="info-strip"');
            $formPosition=strpos($html, 'class="form-panel"');
            $listPosition=strpos($html, 'class="list-panel"');
            if ($containerPosition===false || $infoPosition===false || $formPosition===false || $listPosition===false || !($containerPosition<$infoPosition && $infoPosition<$formPosition && $formPosition<$listPosition)) {
                throw new RuntimeException('Ventas y compras deben agrupar la ayuda, el formulario y el listado en una sola tarjeta.');
            }
        }
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
