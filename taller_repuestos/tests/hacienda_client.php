<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/HaciendaClient.php';

$passed = 0;
function checkHacienda(bool $condition, string $label): void
{
    global $passed;
    if (!$condition) throw new RuntimeException('FALLÓ: ' . $label);
    $passed++;
    echo 'OK: ' . $label . PHP_EOL;
}
function response(array $value): callable
{
    return static fn(string $url, int $timeout): array => $value;
}
$valid = json_encode([
    'nombre' => 'ESTADO-MINISTERIO DE HACIENDA', 'tipoIdentificacion' => '02',
    'regimen' => ['codigo' => 0, 'descripcion' => 'No tiene'],
    'situacion' => ['estado' => 'Inscrito', 'moroso' => 'NO', 'omiso' => 'NO', 'administracionTributaria' => 'San José'],
    'actividades' => [],
], JSON_THROW_ON_ERROR);

$url = '';
$client = new HaciendaClient(6, static function (string $requestUrl, int $timeout) use (&$url, $valid): array {
    $url = $requestUrl;
    return ['status' => 200, 'body' => $valid, 'timed_out' => false];
});
$result = $client->consult(' 210-004 2005 ');
checkHacienda($result['ok'] && $result['data']['identificacion'] === '2100042005', 'identificación válida se normaliza');
checkHacienda(str_ends_with($url, 'identificacion=2100042005'), 'URL HTTPS usa identificación normalizada');
checkHacienda($result['data']['nombre'] === 'ESTADO-MINISTERIO DE HACIENDA' && $result['data']['actividades'] === [], 'HTTP 200 normaliza respuesta y actividades vacías');

$result = (new HaciendaClient(6, response(['status'=>200,'body'=>$valid,'timed_out'=>false])))->consult('12-34');
checkHacienda(!$result['ok'] && $result['error']['code'] === 'identificacion_invalida', 'identificación inválida no se consulta');
foreach ([404 => 'no_encontrado', 429 => 'limite_consultas'] as $status => $code) {
    $result = (new HaciendaClient(6, response(['status'=>$status,'body'=>'','timed_out'=>false])))->consult('2100042005');
    checkHacienda(!$result['ok'] && $result['error']['code'] === $code, "HTTP $status se traduce de forma segura");
}
$result = (new HaciendaClient(6, response(['status'=>500,'body'=>'','timed_out'=>false])))->consult('2100042005');
checkHacienda(!$result['ok'] && $result['error']['code'] === 'servicio_no_disponible', 'HTTP 500 se traduce de forma segura');
$result = (new HaciendaClient(6, static fn(string $url, int $timeout): array => throw new RuntimeException('socket failure')))->consult('2100042005');
checkHacienda(!$result['ok'] && $result['error']['code'] === 'conexion', 'error de conexión no expone detalle técnico');
$result = (new HaciendaClient(6, response(['status'=>0,'body'=>null,'timed_out'=>true])))->consult('2100042005');
checkHacienda(!$result['ok'] && $result['error']['code'] === 'tiempo_espera', 'tiempo de espera agotado se informa');
$result = (new HaciendaClient(6, response(['status'=>200,'body'=>'{','timed_out'=>false])))->consult('2100042005');
checkHacienda(!$result['ok'] && $result['error']['code'] === 'json_invalido', 'JSON inválido se rechaza');
$incomplete = json_encode(['nombre'=>'Nombre'], JSON_THROW_ON_ERROR);
$result = (new HaciendaClient(6, response(['status'=>200,'body'=>$incomplete,'timed_out'=>false])))->consult('2100042005');
checkHacienda(!$result['ok'] && $result['error']['code'] === 'respuesta_incompleta', 'respuesta incompleta se rechaza');
echo $passed . ' comprobaciones correctas, sin consultas externas.' . PHP_EOL;
