<?php
require_once 'conexion.php';
function falloProducto($mensaje, $estado = 422) {
    http_response_code($estado);
    exit('<div class="notification is-danger">' . htmlspecialchars($mensaje) . '</div>');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    falloProducto('Método no permitido.', 405);
}
$id = filter_var($_POST['id_producto'] ?? '0', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483647]]);
$codigo = trim($_POST['codigo'] ?? '');
$descripcion = trim($_POST['descripcion'] ?? '');
$precio = trim($_POST['precio_unitario'] ?? '');
$stock = filter_var($_POST['stock'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483647]]);
$ubicacionTexto = $_POST['id_ubicacion'] ?? '';
$ubicacion = $ubicacionTexto === '' ? null : filter_var($ubicacionTexto, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
if ($id === false || $ubicacion === false) falloProducto('Identificador no válido.');
if ($codigo === '' || $descripcion === '') falloProducto('Código y descripción son obligatorios.');
if (preg_match_all('/./us', $codigo) > 50 || preg_match_all('/./us', $descripcion) > 150) falloProducto('El código admite 50 caracteres y la descripción 150.');
if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/D', $precio)) falloProducto('El precio debe ser positivo o cero, con hasta 10 enteros y 2 decimales.');
if ($stock === false) falloProducto('El stock debe ser un entero entre 0 y 2147483647.');
try {
    if ($ubicacion !== null) {
        $stmt = $pdo->prepare('SELECT id_ubicacion FROM ubicaciones WHERE id_ubicacion = ?');
        $stmt->execute([$ubicacion]);
        if (!$stmt->fetch()) falloProducto('La ubicación seleccionada ya no existe.');
    }
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT id_producto FROM productos WHERE id_producto = ?');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) falloProducto('El producto ya no existe.', 404);
        $stmt = $pdo->prepare('UPDATE productos SET codigo = ?, descripcion = ?, precio_unitario = ?, stock = ?, id_ubicacion = ? WHERE id_producto = ?');
        $stmt->execute([$codigo, $descripcion, $precio, $stock, $ubicacion, $id]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO productos (codigo, descripcion, precio_unitario, stock, id_ubicacion) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$codigo, $descripcion, $precio, $stock, $ubicacion]);
    }
    header('HX-Trigger: productoGuardado');
    echo '<div class="notification is-success">Producto guardado correctamente.</div>';
} catch (PDOException $e) {
    $numero = (int)($e->errorInfo[1] ?? 0);
    if ($numero === 1062) falloProducto('Ya existe un producto con ese código.', 409);
    if ($numero === 1452) falloProducto('La ubicación seleccionada ya no existe.', 409);
    error_log($e->getMessage());
    falloProducto('No se pudo guardar el producto.', 500);
}
