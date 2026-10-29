<?php
declare(strict_types=1);

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
    $value = $_POST[$key] ?? '';
    if (!is_string($value) || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', $value)) {
        throw new InvalidArgumentException('El precio debe ser positivo o cero, con hasta 8 enteros y 2 decimales.');
    }
    $parts = explode('.', $value);
    return (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0');
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
    if ($action === 'category') {
        $name = textInput('nombre', 100);
        requireUnique($db, 'RP01_CATEGORIAS', 'id_categoria', 'nombre', $name, 0, 'Ya existe una categoría con ese nombre. Selecciónela en la lista.');
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
        $data['telefono'] = textInput('telefono', 20);
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
    $partId = integerInput('id_repuesto');
    $quantity = integerInput('cantidad');
    $db->beginTransaction();
    try {
        $parts = rows($db, 'SELECT * FROM RP03_REPUESTOS WITH (UPDLOCK, HOLDLOCK) WHERE id_repuesto = ? AND estado = ?', [$partId, 'ACTIVO']);
        if (!$parts) throw new InvalidArgumentException('Seleccione un repuesto activo.');
        if ($action === 'sale') {
            $client = integerInput('id_cliente');
            requireReference($db, 'RP05_CLIENTES', 'id_cliente', $client, 'El cliente seleccionado no existe. Seleccione un cliente válido.');
            $seller = integerInput('id_vendedor');
            if (!rows($db, "SELECT usuario_id FROM RP13_USUARIOS WHERE usuario_id = ? AND estado = 'ACTIVO' AND nombre_usuario <> 'sistema'", [$seller])) {
                throw new InvalidArgumentException('Seleccione un vendedor activo.');
            }
            $price = (string)$parts[0]['precio_venta'];
            if ($quantity > (int)$parts[0]['stock_actual']) throw new InvalidArgumentException('Existencias insuficientes para completar la venta.');
            if ((float)$price * $quantity > 99999999.99) throw new InvalidArgumentException('El total excede la capacidad del modelo de datos.');
            run($db, 'EXEC sp_ProcesarVentaTransaccional @id_cliente=?, @id_vendedor=?, @id_repuesto=?, @cantidad=?, @precio_unitario=?',
                [$client, $seller, $partId, $quantity, $price]);
        } else {
            $supplier = integerInput('id_proveedor');
            requireReference($db, 'RP02_PROVEEDORES', 'id_proveedor', $supplier, 'El proveedor seleccionado no existe. Seleccione un proveedor válido.');
            $cents = centsInput('precio_unitario');
            if ($cents * $quantity > 9999999999) throw new InvalidArgumentException('El total excede la capacidad del modelo de datos.');
            if ((int)$parts[0]['stock_actual'] + $quantity > 2147483647) throw new InvalidArgumentException('La cantidad excede la capacidad del inventario.');
            $total = decimal($cents * $quantity);
            $purchase = rows($db, "INSERT INTO RP09_COMPRAS (id_proveedor, total, estado) OUTPUT INSERTED.id_compra VALUES (?, ?, 'RECIBIDO')", [$supplier, $total]);
            run($db, 'INSERT INTO RP10_DETALLE_COMPRAS (id_compra,id_repuesto,cantidad,precio_unitario,subtotal) VALUES (?,?,?,?,?)',
                [$purchase[0]['id_compra'], $partId, $quantity, decimal($cents), $total]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return $action === 'sale' ? 'Venta completada. Existencias e historial actualizados.' : 'Compra recibida. Existencias e historial actualizados.';
}
