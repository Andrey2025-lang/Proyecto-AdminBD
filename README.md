# Proyecto-AdminBD

Prototipo académico para gestionar repuestos de vehículos con **PHP + Bulma + HTMX + SQL Server**.

## Contenido

- `taller_repuestos/`: aplicación con repuestos, categorías, clientes, proveedores, compras, ventas e historial de inventario.
- `TallerRepuestosDB.sql`: estructura original de SQL Server, triggers y procedimientos almacenados.
- `taller_repuestos/sql/datos_prueba.sql`: datos ficticios para preparar una demostración.
- `taller_repuestos/sql/001_control_duplicidad.sql`: migración incremental para categorías únicas y control de reenvíos de compras y ventas.
- `taller_repuestos/sql/002_carga_historica.sql`: carga sintética configurable de clientes, catálogo y dos años de compras y ventas.
- `taller_repuestos/sql/003_indices_historial.sql`: índices para búsquedas/listados y fechas de movimientos tomadas de la operación original.
- `miniframework_cech/`: ejemplo PHP/MySQL de referencia del profesor; la aplicación nueva utiliza SQL Server, no MySQL.
- `php_bulma_htmx.pdf`: material de referencia de las tecnologías utilizadas.

## Ejecutar en otra computadora

1. Instalar PHP 8.1 o superior con `pdo_sqlsrv`, el controlador ODBC de Microsoft y SQL Server.
2. Crear la base con `TallerRepuestosDB.sql` **solo si no existe**. **El script original borra la base existente: no ejecutarlo sobre datos que se quieran conservar.**
3. Ejecutar las migraciones **001**, **003**, **004** y **005**, en ese orden. Opcionalmente ejecutar **002** para cargar la historia comercial. Si la base ya existe, ejecutar solo las migraciones pendientes; no volver a crearla. `datos_prueba.sql` es una alternativa pequeña para pruebas.
4. Copiar `taller_repuestos/config.example.php` como `taller_repuestos/config.php` y ajustar la conexión. Para la instancia predeterminada local, usar `server => 'localhost'`; seleccionar la autenticación disponible en ese equipo.
5. Desde la raíz del repositorio, iniciar el servidor de desarrollo:

```powershell
php -S 127.0.0.1:8080 -t .
```

Si PHP no está en el PATH y está instalado en `C:\php`:

```powershell
& 'C:\php\php.exe' -S 127.0.0.1:8080 -t .
```

Abrir http://127.0.0.1:8080/taller_repuestos/.

Todos los módulos consultan SQL Server. La antigua vista con datos incrustados se retiró; `?preview=1` redirige al inicio real.

## Reportes y navegación

En **Reportes** se puede descargar Excel real (`.xlsx`), CSV o abrir un documento imprimible para guardar como PDF con el navegador. Hay filtros por fecha y búsqueda; cada listado también permite exportar sus filtros actuales. No se exporta únicamente la página visible.

El menú muestra únicamente Inicio, Repuestos, Clientes, Proveedores, Compras, Ventas e Inventario e historial. En escritorio se abre al pasar el mouse y el contenido ajusta su ancho; al salir se contrae. El botón Menú fija su apertura y otro clic devuelve el modo automático. En móvil se abre al tocarlo. Cambiar de módulo devuelve la pantalla arriba. Editar abre un formulario superpuesto y conserva filtros/página. La paginación mantiene controles Anterior/Página/Siguiente estables.

Los formularios usan un único buscador con sugerencias para clientes, proveedores y repuestos. Al enfocarlo se pueden recorrer los registros con «Mostrar más»; la búsqueda se filtra desde dos caracteres, con 20 coincidencias por solicitud. La selección admite teclado y ratón y no utiliza un desplegable adicional.

Excel incluye hojas **Resumen** y **Datos**, tablas verdes con filas alternadas, fechas nativas y totales filtrables. El documento imprimible comparte la paleta verde, resúmenes mensuales y resultados por vendedor/proveedor cuando corresponda. CSV conserva datos y metadatos, pero no admite colores ni estilos.

Los datos de la carga son ficticios, con combinaciones comerciales plausibles. Los documentos, teléfonos y compatibilidades no están verificados y no deben utilizarse para contactar personas ni para seleccionar piezas de reparación reales.

Las instrucciones completas, pruebas y limitaciones están en [taller_repuestos/README.md](taller_repuestos/README.md).

## Base de datos y seguridad

Git guarda el código y los scripts, **no la base activa ni sus registros actuales**. Las compras y ventas realizadas localmente no se publican automáticamente. Para entregar una copia exacta de la base, generar y compartir un respaldo `.bak` por separado.

Los archivos de configuración locales, las credenciales y los respaldos están excluidos mediante `.gitignore`. Para el ejemplo MySQL se incluye `conexion.example.php`, sin contraseña.

La migración **005** agrega respaldos verificados. `taller_repuestos/scripts/instalar_respaldos.ps1` registra una tarea de Windows cada 30 minutos: copia completa cada 24 horas y copias del registro entre ellas. En este equipo ya está instalada y se probó una ejecución automática y una restauración completa con sus registros en una base temporal. La tarea actual requiere sesión de Windows abierta y servicio de datos activo, **no abrir PHP ni la página**. No elimina archivos antiguos ni proporciona una copia fuera del equipo. Detalles y limitaciones en la guía de la aplicación.

Este es un prototipo local sin autenticación de usuarios; no debe exponerse a Internet ni presentarse como un sistema listo para producción.
