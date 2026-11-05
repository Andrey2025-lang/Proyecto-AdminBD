<?php
declare(strict_types=1);

/**
 * Adapta HaciendaClient al contrato HTTP interno y conserva resultados
 * exitosos por sesión. No conoce el router ni produce encabezados HTTP.
 */
final class HaciendaEndpoint
{
    public const CACHE_TTL = 600;

    /** @return array{status:int,body:array{ok:bool,data:?array,error:?array,cached:bool}} */
    public static function handle(HaciendaClient $client, array &$session, mixed $value, ?int $now = null): array
    {
        $identificacion = self::normalizeIdentification($value);
        if ($identificacion === null) {
            return self::response(422, false, null, [
                'code' => 'identificacion_invalida',
                'message' => 'Ingrese una identificación numérica de 9 a 12 dígitos.',
                'retryable' => false,
            ]);
        }

        $now ??= time();
        self::removeExpired($session, $now);
        $entry = $session['hacienda_cache'][$identificacion] ?? null;
        if (is_array($entry) && isset($entry['expires_at'], $entry['data'])
            && is_int($entry['expires_at']) && $entry['expires_at'] > $now && is_array($entry['data'])) {
            return self::response(200, true, $entry['data'], null, true);
        }

        $result = $client->consult($identificacion);
        if ($result['ok']) {
            $session['hacienda_cache'][$identificacion] = [
                'expires_at' => $now + self::CACHE_TTL,
                'data' => $result['data'],
            ];
            return self::response(200, true, $result['data'], null);
        }
        return self::response(self::statusFor((string)$result['error']['code']), false, null, $result['error']);
    }

    /** @return array{status:int,body:array{ok:bool,data:?array,error:?array,cached:bool}} */
    private static function response(int $status, bool $ok, ?array $data, ?array $error, bool $cached = false): array
    {
        return ['status' => $status, 'body' => ['ok' => $ok, 'data' => $data, 'error' => $error, 'cached' => $cached]];
    }

    private static function normalizeIdentification(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $value = preg_replace('/[\s-]+/', '', $value) ?? '';
        return preg_match('/^\d{9,12}$/D', $value) ? $value : null;
    }

    private static function removeExpired(array &$session, int $now): void
    {
        if (!isset($session['hacienda_cache']) || !is_array($session['hacienda_cache'])) {
            $session['hacienda_cache'] = [];
            return;
        }
        foreach ($session['hacienda_cache'] as $key => $entry) {
            if (!is_array($entry) || !isset($entry['expires_at']) || !is_int($entry['expires_at']) || $entry['expires_at'] <= $now) {
                unset($session['hacienda_cache'][$key]);
            }
        }
    }

    private static function statusFor(string $code): int
    {
        return match ($code) {
            'identificacion_invalida', 'solicitud_invalida' => 422,
            'no_encontrado' => 404,
            'limite_consultas' => 429,
            'tiempo_espera' => 504,
            default => 502,
        };
    }
}
