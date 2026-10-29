<?php
require_once 'conexion.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'DELETE') {
    header('Allow: DELETE');
    http_response_code(405);
    exit('Método no permitido.');
}
$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    http_response_code(422);
    exit('<div class="notification is-danger">Producto no válido.</div>');
}
try {
    $stmt = $pdo->prepare('DELETE FROM productos WHERE id_producto = ?');
    $stmt->execute([$id]);
    header('HX-Trigger: productoEliminado');
} catch (PDOException $e) {
    http_response_code(409);
    echo '<div class="notification is-danger">No se pudo eliminar el producto. Puede estar asociado a otros registros.</div>';
}
