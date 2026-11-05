<?php
declare(strict_types=1);
// Rechazos fiscales antes de escribir; no abre una conexión real.
require dirname(__DIR__).'/src/Database.php';
require dirname(__DIR__).'/src/Actions.php';
class InvalidTaxStatement extends PDOStatement
{
    public function execute(?array $params=null): bool { return true; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array { return []; }
}
class InvalidTaxDatabase extends PDO
{
    public function __construct() {}
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        if(!str_contains($query,'RP20_TARIFAS_IVA'))throw new RuntimeException('No debe consultar ni escribir operaciones antes de validar el IVA.');
        return new InvalidTaxStatement();
    }
    public function beginTransaction(): bool { throw new RuntimeException('No debe iniciar una operación con tarifa inválida.'); }
}
$_SESSION=[];
[$token,$signature]=operationCredentials('purchase');
foreach([null,'0','-1','999999','abc'] as $tax) {
    $_POST=['action'=>'purchase','id_proveedor'=>'1','lines'=>json_encode([['id_repuesto'=>1,'cantidad'=>1,'precio_unitario'=>'113.00']]),'operation_token'=>$token,'operation_signature'=>$signature];
    if($tax!==null)$_POST['tarifa_compra']=$tax;
    try { action(new InvalidTaxDatabase()); throw new RuntimeException('Se aceptó una tarifa inválida.'); }
    catch(InvalidArgumentException $e) { echo 'OK: tarifa de compra rechazada sin escrituras: '.($tax??'ausente').PHP_EOL; }
}
