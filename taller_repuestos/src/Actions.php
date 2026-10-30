<?php
declare(strict_types=1);
require_once __DIR__ . '/OperationRequests.php';
require_once __DIR__ . '/Presentation.php';
require_once __DIR__ . '/SupplierCatalog.php';

function textInput(string $key, int $max, bool $required = true): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) throw new InvalidArgumentException('Formato de campo inválido.');
    $value = trim($value);
    $length = preg_match_all('/./us', $value);
    if (($required && $value === '') || $length === false || $length > $max) {
        throw new InvalidArgumentException('Revise el campo ' . str_replace('_', ' ', $key) . ' (máximo ' . $max . ' caracteres).');
    }
    return $value;
}

function integerInput(string $key, int $min = 1): int
{
    $value = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => $min, 'max_range' => 2147483647]]);
    if ($value === false || $value === null) throw new InvalidArgumentException('Revise el campo ' . str_replace('_', ' ', $key) . '.');
    return $value;
}

function centsInput(string $key): int
{
    return moneyCents($_POST[$key]??'');
}
function moneyCents($value): int
{
    if (!is_string($value) || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $value)) {
        throw new InvalidArgumentException('El precio debe ser positivo o cero, con hasta 8 enteros y 2 decimales.');
    }
    $parts = explode('.', $value);
    return (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0');
}

function operationLines(string $action): array
{
    if (isset($_POST['lines'])) {
        if (!is_string($_POST['lines']) || strlen($_POST['lines'])>65536) throw new InvalidArgumentException('La lista de repuestos no es válida.');
        try { $raw=json_decode($_POST['lines'],true,16,JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new InvalidArgumentException('La lista de repuestos no es válida.'); }
    } else {
        // Compatibilidad con formularios anteriores y clientes de una sola línea.
        $raw=[['id_repuesto'=>$_POST['id_repuesto']??null,'cantidad'=>$_POST['cantidad']??null,'precio_unitario'=>$_POST['precio_unitario']??null]];
    }
    if (!is_array($raw) || !array_is_list($raw) || !$raw || count($raw)>100) throw new InvalidArgumentException('Agregue entre 1 y 100 líneas a la operación.');
    $lines=[];
    foreach($raw as $row) {
        if (!is_array($row)) throw new InvalidArgumentException('Una línea no es válida.');
        $id=filter_var($row['id_repuesto']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>2147483647]]);
        $qty=filter_var($row['cantidad']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>2147483647]]);
        if (!$id || !$qty) throw new InvalidArgumentException('Cada repuesto debe tener una cantidad entera positiva.');
        $cents=$action==='purchase'?moneyCents($row['precio_unitario']??''):null;
        $expected=$action==='sale' && isset($row['precio_esperado'])?decimal(moneyCents($row['precio_esperado'])):null;
        $key=$id.':'.($cents??'sale');
        if (isset($lines[$key])) {
            if ($lines[$key]['precio_esperado']!==$expected) throw new InvalidArgumentException('Revise el precio del repuesto repetido.');
            $qty+=$lines[$key]['cantidad'];
        }
        if ($qty>2147483647) throw new InvalidArgumentException('La cantidad acumulada de un repuesto supera el máximo permitido.');
        $lines[$key]=['id_repuesto'=>$id,'cantidad'=>$qty,'precio_unitario'=>$cents===null?null:decimal($cents),'precio_esperado'=>$expected];
    }
    $lines=array_values($lines);
    usort($lines,static fn($a,$b)=>[$a['id_repuesto'],$a['precio_unitario']]<=>[$b['id_repuesto'],$b['precio_unitario']]);
    return $lines;
}

function decimal(int $cents): string
{
    return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
}

function saveRecord(PDO $db, string $table, string $pk, array $data, int $id): void
{
    // Los nombres provienen exclusivamente de constantes del servidor, nunca del formulario.
    if ($id > 0) {
        if (!rows($db, "SELECT $pk FROM $table WHERE $pk = ?", [$id])) {
            throw new InvalidArgumentException('El registro ya no existe.');
        }
        $set = implode(', ', array_map(static fn($key) => "$key = ?", array_keys($data)));
        run($db, "UPDATE $table SET $set WHERE $pk = ?", [...array_values($data), $id]);
    } else {
        $columns = implode(', ', array_keys($data));
        $marks = implode(', ', array_fill(0, count($data), '?'));
        run($db, "INSERT INTO $table ($columns) VALUES ($marks)", array_values($data));
    }
}

function requireUnique(PDO $db, string $table, string $pk, string $column, string $value, int $id, string $message): void
{
    // Identificadores definidos por el servidor. La comparación usa la misma
    // intercalación de SQL Server que las restricciones UNIQUE de las tablas.
    if (rows($db, "SELECT TOP (1) $pk FROM $table WHERE $column = ? AND $pk <> ?", [$value, $id])) {
        throw new InvalidArgumentException($message);
    }
}

function requireReference(PDO $db, string $table, string $pk, int $id, string $message): void
{
    if (!rows($db, "SELECT $pk FROM $table WHERE $pk = ?", [$id])) {
        throw new InvalidArgumentException($message);
    }
}

function action(PDO $db): string
{
    $action = $_POST['action'] ?? '';
    if($action==='backup') {
        run($db,'EXEC dbo.sp_RP_RespaldoAutomatico @manual=1');
        return 'Respaldo creado y verificado. Las copias anteriores se conservaron.';
    }
    if($action==='supplier_part')return saveSupplierPart($db);
    if ($action === 'category') {
        $name = textInput('nombre', 100);
        if (rows($db, 'SELECT TOP (1) id_categoria FROM dbo.RP01_CATEGORIAS WHERE UPPER(LTRIM(RTRIM(nombre))) COLLATE Modern_Spanish_CI_AS = UPPER(LTRIM(RTRIM(?))) COLLATE Modern_Spanish_CI_AS', [$name])) {
            throw new InvalidArgumentException('Ya existe una categoría con ese nombre. Selecciónela en la lista.');
        }
        saveRecord($db, 'RP01_CATEGORIAS', 'id_categoria', ['nombre' => $name], 0);
        return 'Categoría registrada.';
    }
    if ($action === 'part') {
        $status = textInput('estado', 20);
        if (!in_array($status, ['ACTIVO', 'INACTIVO'], true)) throw new InvalidArgumentException('Estado inválido.');
        $id = integerInput('id', 0);
        $data = [
            'codigo_barras' => textInput('codigo_barras', 20), 'nombre' => textInput('nombre', 150),
            'id_categoria' => integerInput('id_categoria'), 'precio_compra' => decimal(centsInput('precio_compra')),
            'precio_venta' => decimal(centsInput('precio_venta')), 'stock_minimo' => integerInput('stock_minimo', 0),
            'estado' => $status,
        ];
        requireUnique($db, 'RP03_REPUESTOS', 'id_repuesto', 'codigo_barras', $data['codigo_barras'], $id,
            'Ya existe un repuesto con ese código de barras. Use otro código o edite el repuesto existente.');
        requireReference($db, 'RP01_CATEGORIAS', 'id_categoria', $data['id_categoria'],
            'La categoría seleccionada no existe. Seleccione una categoría válida.');
        if ($id === 0) $data['stock_actual'] = 0;
        saveRecord($db, 'RP03_REPUESTOS', 'id_repuesto', $data, $id);
        return 'Repuesto guardado. Las existencias se modifican mediante compras y ventas.';
    }
    if ($action === 'client' || $action === 'supplier') {
        $email = textInput('email', 100, $action === 'supplier');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Correo electrónico inválido.');
        $data = $action === 'client'
            ? ['cedula' => textInput('cedula', 20), 'nombre' => textInput('nombre', 100)]
            : ['nombre_empresa' => textInput('nombre_empresa', 150), 'contacto' => textInput('contacto', 100)];
        $data['telefono'] = phoneLabel(textInput('telefono', 20));
        $data['email'] = $email === '' ? null : $email;
        $id = integerInput('id', 0);
        if ($action === 'client') {
            requireUnique($db, 'RP05_CLIENTES', 'id_cliente', 'cedula', $data['cedula'], $id,
                'Ya existe un cliente con esa cédula. Use otra cédula o edite el cliente existente.');
        }
        saveRecord($db, $action === 'client' ? 'RP05_CLIENTES' : 'RP02_PROVEEDORES',
            $action === 'client' ? 'id_cliente' : 'id_proveedor', $data, $id);
        return 'Registro guardado correctamente.';
    }
    if ($action !== 'sale' && $action !== 'purchase') throw new InvalidArgumentException('Operación desconocida.');
    $lines = operationLines($action);
    $token = validateOperationToken($action);
    $counterparty = integerInput($action === 'sale' ? 'id_cliente' : 'id_proveedor');
    $seller = $action === 'sale' ? integerInput('id_vendedor') : null;
    // El precio del catálogo no forma parte de la identidad del envío de venta:
    // puede cambiar después de confirmarlo, sin convertir su reenvío en otra venta.
    $identity=array_map(static fn($line)=>[$line['id_repuesto'],$line['cantidad'],$action==='purchase'?$line['precio_unitario']:null],$lines);
    $fingerprint = hash('sha256', json_encode([$action,$counterparty,$seller,$identity], JSON_THROW_ON_ERROR));
    $db->beginTransaction();
    try {
        if (!claimOperationRequest($db, $token, $action, $fingerprint)) {
            $previous=rows($db,'SELECT id_operacion FROM RP14_SOLICITUDES_OPERACION WHERE solicitud=?',[$token])[0];
            if ($previous['id_operacion']) $GLOBALS['completedOperation']=['kind'=>$action==='sale'?'ventas':'compras','id'=>(int)$previous['id_operacion']];
            $db->commit();
            return $action === 'sale' ? 'Esta venta ya fue registrada. No se generó una venta adicional.'
                : 'Esta compra ya fue registrada. No se generó una compra adicional.';
        }
        if ($action === 'sale') {
            $client = $counterparty;
            requireReference($db, 'RP05_CLIENTES', 'id_cliente', $client, 'El cliente seleccionado no existe. Seleccione un cliente válido.');
            if (!rows($db, "SELECT usuario_id FROM RP13_USUARIOS WHERE usuario_id = ? AND estado = 'ACTIVO' AND nombre_usuario <> 'sistema'", [$seller])) {
                throw new InvalidArgumentException('Seleccione un vendedor activo.');
            }
            $result=rows($db,'EXEC dbo.sp_ProcesarVentaMultiple @id_cliente=?,@id_vendedor=?,@lineas=?',[$client,$seller,json_encode($lines,JSON_THROW_ON_ERROR)]);
            $operationId=(int)$result[0]['id_venta'];
        } else {
            $supplier = $counterparty;
            requireReference($db, 'RP02_PROVEEDORES', 'id_proveedor', $supplier, 'El proveedor seleccionado no existe. Seleccione un proveedor válido.');
            $totals=[]; $amount=0;
            foreach($lines as $line) $totals[$line['id_repuesto']]=($totals[$line['id_repuesto']]??0)+$line['cantidad'];
            ksort($totals,SORT_NUMERIC);
            foreach($totals as $id=>$quantity) {
                if(!rows($db,"SELECT estado FROM dbo.RP19_PROVEEDOR_REPUESTO WITH(UPDLOCK,HOLDLOCK) WHERE id_proveedor=? AND id_repuesto=? AND estado='ACTIVO'",[$supplier,$id]))throw new InvalidArgumentException('El proveedor no tiene habilitado uno de los repuestos. Revise su catálogo antes de registrar la compra.');
                $part=rows($db,"SELECT stock_actual FROM RP03_REPUESTOS WITH(UPDLOCK,HOLDLOCK) WHERE id_repuesto=? AND estado='ACTIVO'",[$id]);
                if (!$part) throw new InvalidArgumentException('Seleccione un repuesto activo.');
                if ($quantity+(int)$part[0]['stock_actual']>2147483647) throw new InvalidArgumentException('La cantidad excede la capacidad del inventario.');
            }
            foreach($lines as $line) {
                $cents=moneyCents($line['precio_unitario']);
                if ($cents && $line['cantidad']>intdiv(9999999999-$amount,$cents)) throw new InvalidArgumentException('El total supera el máximo permitido de 99,999,999.99.');
                $amount+=$cents*$line['cantidad'];
            }
            $total = decimal($amount);
            $purchase = rows($db, "INSERT INTO RP09_COMPRAS (id_proveedor, total, estado) OUTPUT INSERTED.id_compra VALUES (?, ?, 'RECIBIDO')", [$supplier, $total]);
            $operationId=(int)$purchase[0]['id_compra']; $params=[];
            foreach($lines as $line) array_push($params,$operationId,$line['id_repuesto'],$line['cantidad'],$line['precio_unitario'],decimal(moneyCents($line['precio_unitario'])*$line['cantidad']));
            run($db,'INSERT INTO RP10_DETALLE_COMPRAS(id_compra,id_repuesto,cantidad,precio_unitario,subtotal) VALUES '.implode(',',array_fill(0,count($lines),'(?,?,?,?,?)')),$params);
        }
        run($db,'UPDATE RP14_SOLICITUDES_OPERACION SET id_operacion=? WHERE solicitud=?',[$operationId,$token]);
        $db->commit();
        $GLOBALS['completedOperation']=['kind'=>$action==='sale'?'ventas':'compras','id'=>$operationId];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if($e instanceof PDOException && in_array((int)($e->errorInfo[1]??0),[50001,50003,50004,50010,50011,50012,50013,50014,50015,50016],true)) {
            throw new InvalidArgumentException(databaseErrorMessage($e,$action),0,$e);
        }
        throw $e;
    }
    return $action === 'sale' ? 'Venta completada. Existencias e historial actualizados.' : 'Compra recibida. Existencias e historial actualizados.';
}
