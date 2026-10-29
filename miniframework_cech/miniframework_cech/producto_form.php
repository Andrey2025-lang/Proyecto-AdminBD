<?php
require_once 'conexion.php';
$id = (int)($_GET['id'] ?? 0);
$producto = ['id_producto' => 0, 'codigo' => '', 'descripcion' => '', 'precio_unitario' => '0.00', 'stock' => 0, 'id_ubicacion' => null];
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM productos WHERE id_producto = ?');
    $stmt->execute([$id]);
    $producto = $stmt->fetch();
    if (!$producto) {
        http_response_code(404);
        exit('<div class="notification is-warning">El producto ya no existe.</div>');
    }
}
$ubicaciones = $pdo->query('SELECT id_ubicacion, codigo, descripcion FROM ubicaciones ORDER BY codigo')->fetchAll();
?>
<div class="modal is-active">
    <div class="modal-background" onclick="document.getElementById('modal-contenido').innerHTML=''"></div>
    <div class="modal-card">
        <header class="modal-card-head">
            <p class="modal-card-title"><?= $id > 0 ? 'Modificar producto' : 'Nuevo producto' ?></p>
            <button class="delete" aria-label="Cerrar" onclick="document.getElementById('modal-contenido').innerHTML=''"></button>
        </header>
        <form hx-post="producto_guardar.php" hx-target="#mensaje-form" hx-swap="innerHTML">
            <section class="modal-card-body">
                <input type="hidden" name="id_producto" value="<?= (int)$producto['id_producto'] ?>">
                <div id="mensaje-form" role="alert"></div>
                <div class="field">
                    <label class="label" for="codigo">Código</label>
                    <input class="input" id="codigo" name="codigo" maxlength="50" required value="<?= htmlspecialchars($producto['codigo']) ?>">
                </div>
                <div class="field">
                    <label class="label" for="descripcion">Descripción</label>
                    <input class="input" id="descripcion" name="descripcion" maxlength="150" required value="<?= htmlspecialchars($producto['descripcion']) ?>">
                </div>
                <div class="columns">
                    <div class="column field">
                        <label class="label" for="precio_unitario">Precio unitario</label>
                        <input class="input" id="precio_unitario" name="precio_unitario" type="number" min="0" max="9999999999.99" step="0.01" required value="<?= htmlspecialchars($producto['precio_unitario']) ?>">
                    </div>
                    <div class="column field">
                        <label class="label" for="stock">Stock</label>
                        <input class="input" id="stock" name="stock" type="number" min="0" max="2147483647" step="1" required value="<?= (int)$producto['stock'] ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="label" for="id_ubicacion">Ubicación</label>
                    <div class="select is-fullwidth">
                        <select id="id_ubicacion" name="id_ubicacion">
                            <option value="">Sin ubicación</option>
                            <?php foreach ($ubicaciones as $ubicacion): ?>
                                <option value="<?= (int)$ubicacion['id_ubicacion'] ?>" <?= (int)$producto['id_ubicacion'] === (int)$ubicacion['id_ubicacion'] ? 'selected' : '' ?>><?= htmlspecialchars($ubicacion['codigo'] . ' — ' . $ubicacion['descripcion']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </section>
            <footer class="modal-card-foot">
                <div class="buttons">
                    <button type="submit" class="button is-primary">
                        <svg class="icono-boton" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 3h13l4 4v14H3V4a1 1 0 0 1 1-1Z"/><path d="M7 3v6h9V3M7 21v-8h10v8"/></svg>
                        <span>Guardar</span>
                    </button>
                    <button type="button" class="button" onclick="document.getElementById('modal-contenido').innerHTML=''">
                        <svg class="icono-boton" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m6 6 12 12M18 6 6 18"/></svg>
                        <span>Cancelar</span>
                    </button>
                </div>
            </footer>
        </form>
    </div>
</div>
