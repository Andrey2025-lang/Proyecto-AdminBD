<?php
declare(strict_types=1);

/**
 * Cliente aislado para la consulta pública de contribuyentes de Hacienda.
 *
 * El transporte se puede sustituir en pruebas con un callable que reciba
 * (string $url, int $timeout) y devuelva status, body y opcionalmente
 * timed_out. No depende del router, de SQL Server ni de las vistas.
 */
final class HaciendaClient
{
    private const ENDPOINT = 'https://api.hacienda.go.cr/fe/ae';

    /** @var callable|null */
    private $transport;

    public function __construct(private readonly int $timeout = 6, ?callable $transport = null)
    {
        if ($timeout < 5 || $timeout > 8) {
            throw new InvalidArgumentException('El tiempo de espera debe estar entre 5 y 8 segundos.');
        }
        $this->transport = $transport;
    }

    /**
     * @return array{ok:bool,data:?array,error:?array}
     */
    public function consult(string $identificacion): array
    {
        $identificacion = preg_replace('/[\s-]+/', '', $identificacion) ?? '';
        if (!preg_match('/^\d{9,12}$/D', $identificacion)) {
            return $this->failure('identificacion_invalida', 'Ingrese una identificación numérica de 9 a 12 dígitos.', false);
        }

        $url = self::ENDPOINT . '?' . http_build_query(['identificacion' => $identificacion], '', '&', PHP_QUERY_RFC3986);
        try {
            $response = $this->request($url);
        } catch (Throwable $e) {
            return $this->failure('conexion', 'No se pudo consultar Hacienda. Puede continuar registrando el cliente manualmente.', true);
        }

        if (!empty($response['timed_out'])) {
            return $this->failure('tiempo_espera', 'Hacienda tardó demasiado en responder. Puede continuar registrando el cliente manualmente.', true);
        }

        $status = filter_var($response['status'] ?? null, FILTER_VALIDATE_INT);
        if ($status === false || $status < 100 || $status > 599) {
            return $this->failure('conexion', 'No se pudo consultar Hacienda. Puede continuar registrando el cliente manualmente.', true);
        }
        if ($status !== 200) {
            return match ($status) {
                400 => $this->failure('solicitud_invalida', 'La identificación no pudo ser consultada en Hacienda.', false),
                404 => $this->failure('no_encontrado', 'La identificación no se encontró en Hacienda. Puede continuar registrando el cliente manualmente.', false),
                429 => $this->failure('limite_consultas', 'Hacienda limitó temporalmente las consultas. Intente más tarde o continúe manualmente.', true),
                default => $this->failure('servicio_no_disponible', 'Hacienda no está disponible en este momento. Puede continuar registrando el cliente manualmente.', true),
            };
        }

        if (!isset($response['body']) || !is_string($response['body'])) {
            return $this->failure('respuesta_invalida', 'Hacienda devolvió una respuesta no válida. Puede continuar registrando el cliente manualmente.', true);
        }
        try {
            $payload = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return $this->failure('json_invalido', 'Hacienda devolvió una respuesta no válida. Puede continuar registrando el cliente manualmente.', true);
        }

        $data = $this->normalize($payload, $identificacion);
        if ($data === null) {
            return $this->failure('respuesta_incompleta', 'Hacienda devolvió información incompleta. Puede continuar registrando el cliente manualmente.', true);
        }
        return ['ok' => true, 'data' => $data, 'error' => null];
    }

    /** @return array{ok:false,data:null,error:array{code:string,message:string,retryable:bool}} */
    private function failure(string $code, string $message, bool $retryable): array
    {
        return ['ok' => false, 'data' => null, 'error' => compact('code', 'message', 'retryable')];
    }

    /** @return array{status:int,body:?string,timed_out:bool} */
    private function request(string $url): array
    {
        if ($this->transport !== null) {
            $response = ($this->transport)($url, $this->timeout);
            if (!is_array($response)) throw new RuntimeException('Transporte inválido.');
            return $response;
        }
        return $this->streamRequest($url);
    }

    /**
     * Alternativa segura cuando ext-curl no está instalada. HTTPS conserva la
     * verificación de certificado y nombre de host de OpenSSL de PHP.
     *
     * @return array{status:int,body:?string,timed_out:bool}
     */
    private function streamRequest(string $url): array
    {
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => $this->timeout, 'ignore_errors' => true, 'follow_location' => 0],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false],
        ]);
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        });
        try {
            $body = file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }
        $headers = $http_response_header ?? [];
        $status = 0;
        if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $match)) $status = (int)$match[1];
        $timedOut = $body === false && is_string($warning) && str_contains(strtolower($warning), 'timed out');
        return ['status' => $status, 'body' => $body === false ? null : $body, 'timed_out' => $timedOut];
    }

    /** @return array|null */
    private function normalize(mixed $payload, string $identificacion): ?array
    {
        if (!is_array($payload) || !isset($payload['regimen'], $payload['situacion'])
            || !is_array($payload['regimen']) || !is_array($payload['situacion']) || !isset($payload['actividades'])
            || !is_array($payload['actividades'])) return null;
        $required = [
            $payload['nombre'] ?? null, $payload['tipoIdentificacion'] ?? null,
            $payload['regimen']['codigo'] ?? null, $payload['regimen']['descripcion'] ?? null,
            $payload['situacion']['estado'] ?? null, $payload['situacion']['moroso'] ?? null,
            $payload['situacion']['omiso'] ?? null, $payload['situacion']['administracionTributaria'] ?? null,
        ];
        foreach ($required as $value) if (!is_scalar($value) || $value === '') return null;
        return [
            'identificacion' => $identificacion,
            'nombre' => (string)$payload['nombre'],
            'tipo_identificacion' => (string)$payload['tipoIdentificacion'],
            'regimen' => ['codigo' => $payload['regimen']['codigo'], 'descripcion' => (string)$payload['regimen']['descripcion']],
            'situacion' => [
                'estado' => (string)$payload['situacion']['estado'],
                'moroso' => (string)$payload['situacion']['moroso'],
                'omiso' => (string)$payload['situacion']['omiso'],
                'administracion_tributaria' => (string)$payload['situacion']['administracionTributaria'],
            ],
            // Se conserva sin interpretar: Hacienda puede devolver un arreglo vacío.
            'actividades' => $payload['actividades'],
        ];
    }
}
