<?php
require_once 'conexion.php';
$buscar = trim($_GET['buscar'] ?? '');
$porPagina = 20;
$paginaSolicitada = filter_var($_GET['pagina'] ?? 1, FILTER_VALIDATE_INT);
$sql = ' FROM productos p LEFT JOIN ubicaciones u ON u.id_ubicacion = p.id_ubicacion';
$parametros = [];
if ($buscar !== '') {
    $sql .= ' WHERE p.codigo LIKE ? OR p.descripcion LIKE ? OR u.codigo LIKE ? OR u.descripcion LIKE ?';
    $parametros = array_fill(0, 4, '%' . $buscar . '%');
}
$stmt = $pdo->prepare('SELECT COUNT(*)' . $sql);
$stmt->execute($parametros);
$total = (int)$stmt->fetchColumn();
$paginas = max(1, (int)ceil($total / $porPagina));
$pagina = min($paginas, max(1, (int)$paginaSolicitada));
$offset = ($pagina - 1) * $porPagina;
$stmt = $pdo->prepare('SELECT p.*, u.codigo AS ubicacion_codigo, u.descripcion AS ubicacion_descripcion' . $sql . ' ORDER BY p.descripcion, p.id_producto LIMIT ? OFFSET ?');
foreach ($parametros as $indice => $valor) {
    $stmt->bindValue($indice + 1, $valor, PDO::PARAM_STR);
}
$stmt->bindValue(count($parametros) + 1, $porPagina, PDO::PARAM_INT);
$stmt->bindValue(count($parametros) + 2, $offset, PDO::PARAM_INT);
$stmt->execute();
$productos = $stmt->fetchAll();
?>
<input type="hidden" id="pagina-productos" name="pagina" value="<?= $pagina ?>">
<div class="table-container">
<table class="table is-fullwidth is-striped is-hoverable">
    <thead><tr><th>ID</th><th>Código</th><th>Descripción</th><th>Precio unitario</th><th>Stock</th><th>Ubicación</th><th>Acciones</th></tr></thead>
    <tbody>
    <?php if (!$productos): ?>
        <tr><td colspan="7" class="has-text-centered">No hay productos que mostrar.</td></tr>
    <?php endif; ?>
    <?php foreach ($productos as $producto): ?>
        <tr>
            <td><?= (int)$producto['id_producto'] ?></td>
            <td><?= htmlspecialchars($producto['codigo']) ?></td>
            <td><?= htmlspecialchars($producto['descripcion']) ?></td>
            <td><?= number_format((float)$producto['precio_unitario'], 2, '.', ',') ?></td>
            <td><?= (int)$producto['stock'] ?></td>
            <td><?= $producto['ubicacion_codigo'] === null ? 'Sin ubicación' : htmlspecialchars($producto['ubicacion_codigo'] . ' — ' . $producto['ubicacion_descripcion']) ?></td>
            <td>
                <button class="button is-small is-info" hx-get="producto_form.php?id=<?= (int)$producto['id_producto'] ?>" hx-target="#modal-contenido">
                    <svg class="icono-boton" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 5 4 4M4 20l5-1L20 8a2.8 2.8 0 0 0-4-4L5 15l-1 5Z"/></svg>
                    <span>Editar</span>
                </button>
                <button class="button is-small is-danger" hx-delete="producto_eliminar.php?id=<?= (int)$producto['id_producto'] ?>" hx-target="#mensaje-lista" hx-confirm="¿Desea eliminar este producto?">
                    <svg class="icono-boton" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7M14 10v7"/></svg>
                    <span>Eliminar</span>
                </button>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<div class="paginacion-productos">
    <p role="status">Mostrando <?= $total ? $offset + 1 : 0 ?>–<?= min($offset + $porPagina, $total) ?> de <?= $total ?> productos</p>
    <nav class="pagination" aria-label="Páginas de productos" hx-target="#lista-productos" hx-include="[name=buscar]" hx-swap="innerHTML">
        <button type="button" class="pagination-previous" hx-get="productos_lista.php?pagina=<?= max(1, $pagina - 1) ?>" <?= $pagina === 1 ? 'disabled' : '' ?>>Anterior</button>
        <button type="button" class="pagination-next" hx-get="productos_lista.php?pagina=<?= min($paginas, $pagina + 1) ?>" <?= $pagina === $paginas ? 'disabled' : '' ?>>Siguiente</button>
        <ul class="pagination-list">
            <?php
            $numeros = array_unique(array_merge([1], range(max(1, $pagina - 2), min($paginas, $pagina + 2)), [$paginas]));
            $anterior = 0;
            foreach ($numeros as $numero):
            ?>
                <?php if ($numero > $anterior + 1): ?>
                    <li><span class="pagination-ellipsis" aria-hidden="true">&hellip;</span></li>
                <?php endif; ?>
                <li><button type="button" class="pagination-link <?= $numero === $pagina ? 'is-current' : '' ?>" aria-label="Página <?= $numero ?>" <?= $numero === $pagina ? 'aria-current="page"' : '' ?> hx-get="productos_lista.php?pagina=<?= $numero ?>"><?= $numero ?></button></li>
            <?php $anterior = $numero; endforeach; ?>
        </ul>
    </nav>
</div>
