<?php
require_once 'conexion.php';
?>
<!DOCTYPE html>
<html lang="es" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CRUD Productos - PHP + Bulma + HTMX</title>

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">

    <link rel="stylesheet" href="estilos.css">
    <script src="htmx.min.js"></script>
</head>
<body>

<section class="section">
<div class="container">

    <div class="level encabezado-productos">
        <div class="level-left">
            <h1 class="title">Productos</h1>
        </div>

        <div class="level-right">
            <button class="button is-primary"
                    hx-get="producto_form.php"
                    hx-target="#modal-contenido"
                    hx-swap="innerHTML">
                <svg class="icono-boton" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 5v14M5 12h14"/></svg>
                <span>Nuevo producto</span>
            </button>
        </div>
    </div>

    <div class="field">
        <div class="control">
            <input class="input"
                   type="search"
                   aria-label="Buscar productos"
                   name="buscar"
                   placeholder="Buscar por código, descripción o ubicación..."
                   hx-get="productos_lista.php"
                   hx-trigger="keyup changed delay:400ms, search"
                   hx-target="#lista-productos"
                   hx-include="this">
        </div>
    </div>

    <div id="mensaje-lista" role="alert"></div>
    <div id="lista-productos"
         hx-get="productos_lista.php"
         hx-trigger="load, productoGuardado from:body, productoEliminado from:body"
         hx-include="[name=buscar], #pagina-productos"
         hx-swap="innerHTML">
    </div>

</div>
</section>

<div id="modal-contenido"></div>
<dialog id="confirmar-eliminacion" class="modal" aria-labelledby="titulo-confirmacion" aria-describedby="texto-confirmacion">
    <div class="modal-background" data-cancelar></div>
    <div class="modal-card">
        <header class="modal-card-head">
            <p id="titulo-confirmacion" class="modal-card-title">Eliminar producto</p>
            <button type="button" class="delete" aria-label="Cerrar" data-cancelar></button>
        </header>
        <section class="modal-card-body">
            <svg class="icono-confirmacion" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7M14 10v7"/></svg>
            <p id="texto-confirmacion">¿Desea eliminar este producto?</p>
            <p class="mt-3">Esta acción no se puede deshacer.</p>
        </section>
        <footer class="modal-card-foot">
            <div class="buttons">
                <button type="button" class="button is-info" data-cancelar autofocus>Cancelar</button>
                <button type="button" class="button is-primary" id="aceptar-eliminacion">
                    <svg class="icono-boton" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7M14 10v7"/></svg>
                    <span>Eliminar</span>
                </button>
            </div>
        </footer>
    </div>
</dialog>
<div id="aviso-temporal" class="notification aviso-temporal" role="status" aria-live="polite" aria-atomic="true" hidden>
    <button type="button" class="delete" aria-label="Cerrar notificación"></button>
    <span id="texto-aviso"></span>
</div>
<script src="avisos.js"></script>

<script>
document.body.addEventListener('cerrarModal', function () {
    document.getElementById('modal-contenido').innerHTML = '';
});

document.body.addEventListener('productoGuardado', function () {
    document.getElementById('modal-contenido').innerHTML = '';
    document.getElementById('mensaje-lista').innerHTML = '';
});
document.body.addEventListener('htmx:beforeSwap', function (event) {
    if ([404, 409, 422, 500].includes(event.detail.xhr.status)) {
        event.detail.shouldSwap = true;
        event.detail.isError = false;
    }
});
</script>

</body>
</html>
