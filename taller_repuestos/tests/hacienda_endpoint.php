<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/HaciendaClient.php';
require dirname(__DIR__) . '/src/HaciendaEndpoint.php';

$passed = 0;
function checkEndpoint(bool $condition, string $label): void
{
    global $passed;
    if (!$condition) throw new RuntimeException('FALLÓ: ' . $label);
    $passed++;
    echo 'OK: ' . $label . PHP_EOL;
}
function clientForEndpoint(array $response, ?int &$calls = null): HaciendaClient
{
    return new HaciendaClient(6, static function (string $url, int $timeout) use ($response, &$calls): array {
        if ($calls !== null) $calls++;
        return $response;
    });
}
$payload = json_encode([
    'nombre' => 'ESTADO-MINISTERIO DE HACIENDA', 'tipoIdentificacion' => '02',
    'regimen' => ['codigo' => 0, 'descripcion' => 'No tiene'],
    'situacion' => ['estado' => 'Inscrito', 'moroso' => 'NO', 'omiso' => 'NO', 'administracionTributaria' => 'San José'],
    'actividades' => [],
], JSON_THROW_ON_ERROR);

$session = [];
$calls = 0;
$result = HaciendaEndpoint::handle(clientForEndpoint(['status'=>200,'body'=>$payload,'timed_out'=>false], $calls), $session, ' 210-004 2005 ', 1000);
checkEndpoint($result['status'] === 200 && $result['body']['ok'] && !$result['body']['cached'], 'identificación válida devuelve JSON exitoso');
checkEndpoint($result['body']['data']['identificacion'] === '2100042005' && $result['body']['data']['actividades'] === [], 'respuesta exitosa queda normalizada');
checkEndpoint(isset($session['hacienda_cache']['2100042005']) && $calls === 1, 'respuesta exitosa se guarda en caché de sesión');
$cachedSession = $session;
$noInscrito = json_encode([
    'nombre' => 'CLIENTE NO INSCRITO', 'tipoIdentificacion' => '01',
    'regimen' => ['codigo' => 0, 'descripcion' => 'No tiene'],
    'situacion' => ['estado' => 'No inscrito', 'moroso' => 'NO', 'omiso' => 'NO', 'administracionTributaria' => ''],
    'actividades' => [],
], JSON_THROW_ON_ERROR);
$session = [];
$result = HaciendaEndpoint::handle(clientForEndpoint(['status'=>200,'body'=>$noInscrito,'timed_out'=>false]), $session, '2100042005', 1000);
checkEndpoint($result['status'] === 200 && $result['body']['ok'] && $result['body']['data']['nombre'] === 'CLIENTE NO INSCRITO', 'endpoint acepta contribuyente no inscrito');
$cached = HaciendaEndpoint::handle(clientForEndpoint(['status'=>500,'body'=>'','timed_out'=>false], $calls), $cachedSession, '2100042005', 1001);
checkEndpoint($cached['status'] === 200 && $cached['body']['cached'] && $calls === 1, 'caché vigente evita una nueva consulta externa');

$session = [];
$invalid = HaciendaEndpoint::handle(clientForEndpoint(['status'=>200,'body'=>$payload,'timed_out'=>false]), $session, 'abc', 1000);
checkEndpoint($invalid['status'] === 422 && $invalid['body']['error']['code'] === 'identificacion_invalida', 'identificación inválida responde 422');
foreach ([404 => [404, 'no_encontrado'], 429 => [429, 'limite_consultas'], 500 => [502, 'servicio_no_disponible']] as $external => [$status, $code]) {
    $session = [];
    $result = HaciendaEndpoint::handle(clientForEndpoint(['status'=>$external,'body'=>'','timed_out'=>false]), $session, '2100042005', 1000);
    checkEndpoint($result['status'] === $status && $result['body']['error']['code'] === $code && !$result['body']['cached'], "HTTP externo $external usa respuesta JSON segura");
}
$session = [];
$connectionClient = new HaciendaClient(6, static fn(string $url, int $timeout): array => throw new RuntimeException('network failure'));
$result = HaciendaEndpoint::handle($connectionClient, $session, '2100042005', 1000);
checkEndpoint($result['status'] === 502 && $result['body']['error']['code'] === 'conexion', 'error externo de conexión responde 502 sin detalle técnico');
$session = [];
$result = HaciendaEndpoint::handle(clientForEndpoint(['status'=>0,'body'=>null,'timed_out'=>true]), $session, '2100042005', 1000);
checkEndpoint($result['status'] === 504 && $result['body']['error']['code'] === 'tiempo_espera', 'timeout externo responde 504');
$json = json_encode($cached['body'], JSON_THROW_ON_ERROR);
checkEndpoint(json_decode($json, true, 32, JSON_THROW_ON_ERROR)['ok'] === true, 'contrato del endpoint es JSON consistente');
echo $passed . ' comprobaciones correctas, sin consultas externas.' . PHP_EOL;
