<?php
declare(strict_types=1);

function operationSignature(string $action, string $token, string $secret): string
{
    return hash_hmac('sha256', $action . ':' . $token, $secret);
}

function validateOperationToken(string $action): string
{
    $token = $_POST['operation_token'] ?? null;
    $signature = $_POST['operation_signature'] ?? null;
    $secret = $_SESSION['operation_secret'] ?? null;
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)
        || !is_string($signature) || !is_string($secret)
        || !hash_equals(operationSignature($action, $token, $secret), $signature)) {
        throw new InvalidArgumentException('El formulario de la operación venció o no es válido. Recargue la página e intente nuevamente.');
    }
    return $token;
}

function operationCredentials(string $action): array
{
    $_SESSION['operation_secret'] ??= bin2hex(random_bytes(32));
    // Un error de validación no consume el envío: puede corregirse y reintentarse.
    if (!empty($GLOBALS['error']) && ($_POST['action'] ?? '') === $action) {
        try {
            $token = validateOperationToken($action);
            return [$token, $_POST['operation_signature']];
        } catch (InvalidArgumentException $e) {
            // Credenciales vencidas/manipuladas: emitir un formulario nuevo.
        }
    }
    $token = bin2hex(random_bytes(32));
    return [$token, operationSignature($action, $token, $_SESSION['operation_secret'])];
}

function claimOperationRequest(PDO $db, string $token, string $action, string $fingerprint): bool
{
    // Se llama dentro de la misma transacción de la compra/venta. El bloqueo
    // de la clave (o su rango si aún no existe) serializa reenvíos concurrentes.
    $previous = rows($db, 'SELECT tipo,huella FROM dbo.RP14_SOLICITUDES_OPERACION WITH (UPDLOCK,HOLDLOCK) WHERE solicitud=?', [$token]);
    if ($previous) {
        if ($previous[0]['tipo'] !== $action || !hash_equals($previous[0]['huella'], $fingerprint)) {
            throw new InvalidArgumentException('Esta solicitud ya fue procesada con otros datos. Abra un formulario nuevo para registrar otra operación.');
        }
        return false;
    }
    run($db, 'INSERT INTO dbo.RP14_SOLICITUDES_OPERACION(solicitud,tipo,huella) VALUES (?,?,?)', [$token,$action,$fingerprint]);
    return true;
}
