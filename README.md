# Proyecto-AdminBD

Prototipo académico para gestionar repuestos de vehículos con **PHP + Bulma + HTMX + SQL Server**.

## Contenido

- `taller_repuestos/`: aplicación con repuestos, categorías, clientes, proveedores, compras, ventas e historial de inventario.
- `TallerRepuestosDB.sql`: estructura original de SQL Server, triggers y procedimientos almacenados.
- `taller_repuestos/sql/datos_prueba.sql`: datos ficticios para preparar una demostración.
- `taller_repuestos/sql/001_control_duplicidad.sql`: migración incremental para categorías únicas y control de reenvíos de compras y ventas.
- `taller_repuestos/sql/002_carga_historica.sql`: carga sintética configurable de clientes, catálogo y dos años de compras y ventas.
- `taller_repuestos/sql/003_indices_historial.sql`: índices para búsquedas/listados y fechas de movimientos tomadas de la operación original.
- `taller_repuestos/sql/005_respaldos.sql`: respaldos completos y del registro con verificación.
- `taller_repuestos/sql/008_catalogo_proveedores.sql`: catálogo de repuestos habilitados por proveedor, validado al registrar compras.
- `taller_repuestos/sql/009_respaldos_manuales.sql`: respaldos manuales `COPY_ONLY` y exclusión de ejecuciones concurrentes.
- `taller_repuestos/sql/010_iva_incluido.sql`: cambios del IVA incluido en compras y ventas.
- `taller_repuestos/sql/011_data_warehouse.sql`: almacén dimensional de ventas y procedimiento ETL.
- `taller_repuestos/sql/012_respaldos_recuperacion.sql`: intervalos de respaldos y validación de la cadena en `SIMPLE`, `FULL` y `BULK_LOGGED`.
- `taller_repuestos/sql/consultas_dw.sql` y `verificar_dw.sql`: análisis y verificación de solo lectura.
- `miniframework_cech/`: ejemplo PHP/MySQL de referencia del profesor; la aplicación nueva utiliza SQL Server, no MySQL.
- `php_bulma_htmx.pdf`: material de referencia de las tecnologías utilizadas.

## Ejecutar en otra computadora

1. Instalar PHP 8.1 o superior con `pdo_sqlsrv`, el controlador ODBC de Microsoft y SQL Server.
2. Crear la base con `TallerRepuestosDB.sql` **solo si no existe**. **El script original borra la base existente: no ejecutarlo sobre datos que se quieran conservar.**
3. En una base nueva, aplicar las migraciones incrementales disponibles en orden: **001**, **003**, **004**, **005**, **006**, **008**, **009**, **010**, **011** y **012**. **011** crea el almacén, pero no carga datos hasta ejecutar expresamente `dw.sp_CargarDataWarehouse`. **002** y **007** son generadores de datos históricos/sintéticos, no migraciones obligatorias; usarlos solo en un entorno donde se autorice poblar datos. Si la base ya existe, aplicar únicamente lo pendiente; no volver a crearla. `datos_prueba.sql` es una alternativa pequeña para pruebas.
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

La migración **010** incorpora IVA incluido: los precios finales no aumentan; se guarda base e impuesto y se muestran en el detalle y comprobantes. Las compras nuevas requieren una tarifa activa y sus precios siempre incluyen ese IVA, sin sumarlo nuevamente. La historia sin desglose se conserva, sin asignarle impuestos supuestos. En Compras también se puede buscar primero un repuesto y elegir entre los proveedores que lo tienen habilitado. Ver alcance fiscal y limitaciones en la guía de la aplicación.

En **Reportes** se puede descargar Excel real (`.xlsx`), CSV o abrir un documento imprimible para guardar como PDF con el navegador. Hay filtros por fecha y búsqueda; cada listado también permite exportar sus filtros actuales. No se exporta únicamente la página visible.

El menú muestra Inicio, Repuestos, Clientes, Proveedores, Compras, Ventas, Inventario e historial y Respaldo de la información. En escritorio se abre al pasar el mouse y el contenido ajusta su ancho; al salir se contrae. El botón Menú fija su apertura y otro clic devuelve el modo automático. En móvil se abre al tocarlo. Cambiar de módulo devuelve la pantalla arriba. Editar abre un formulario superpuesto y conserva filtros/página. La paginación mantiene controles Anterior/Página/Siguiente estables.

Los formularios usan un único buscador con sugerencias para clientes, proveedores y repuestos. Al enfocarlo se pueden recorrer los registros con «Mostrar más»; la búsqueda se filtra desde dos caracteres, con 20 coincidencias por solicitud. La selección admite teclado y ratón y no utiliza un desplegable adicional. En **Compras**, el buscador muestra únicamente los repuestos habilitados para el proveedor elegido; el catálogo se administra desde Proveedores → **Repuestos** y el servidor vuelve a comprobarlo al guardar.

Excel incluye una hoja **Resumen**, la hoja principal de datos, el detalle de repuestos y resúmenes por repuesto, vendedor o proveedor; usa tablas verdes con filas alternadas, fechas nativas y totales filtrables. El documento imprimible comparte la paleta verde y los resúmenes principales. CSV conserva el detalle limpio para importar, pero no admite colores ni estilos.

Los datos de la carga son ficticios, con combinaciones comerciales plausibles. Los documentos, teléfonos y compatibilidades no están verificados y no deben utilizarse para contactar personas ni para seleccionar piezas de reparación reales.

El almacén usa SCD tipo 1 para clientes, repuestos y vendedores: los cambios reemplazan atributos anteriores, sin historial de versiones. Las claves de dimensión enlazadas en hechos permanecen, pero al analizarlos se muestran los atributos actuales. Es una limitación conocida de la primera versión. El esquema `dw` está separado lógicamente, no es otra base de datos física. La dimensión tiempo genera un calendario continuo entre la menor y mayor fecha de ventas y amplía esos límites en cargas posteriores.

Las migraciones **011** y **012** y el procedimiento ETL no se ejecutaron durante esta preparación. La instalación y el funcionamiento del almacén y la cadena de respaldos todavía requieren verificación en un entorno autorizado.

Las instrucciones completas, pruebas y limitaciones están en [taller_repuestos/README.md](taller_repuestos/README.md).

## Base de datos y seguridad

**Menú lateral → Respaldo de la información → Crear respaldo** permite solicitar una copia manual verificada e independiente (`COPY_ONLY`), sin eliminar las anteriores. Requiere las migraciones **009** y **012**. Historial, intervalos y exclusión de ejecuciones simultáneas se manejan en la base. La tarea de Windows debe instalarse por separado; no hay botón para restaurar ni borrar copias.

Git guarda el código y los scripts, **no la base activa ni sus registros actuales**. Las compras y ventas realizadas localmente no se publican automáticamente. Para entregar una copia exacta de la base, generar y compartir un respaldo `.bak` por separado.

Los archivos de configuración locales, las credenciales y los respaldos están excluidos mediante `.gitignore`. Para el ejemplo MySQL se incluye `conexion.example.php`, sin contraseña.

La migración **012** actualiza la lógica según el modelo de recuperación: en `SIMPLE` evita completos automáticos redundantes y no ejecuta respaldos de log; en `FULL`/`BULK_LOGGED` respeta el intervalo de completos y logs, mantiene una base de cadena normal sin reiniciarla con completos periódicos y verifica los logs previos contra `msdb`, su orden LSN y sus archivos. Un log externo no registrado o una cadena incompleta detienen el proceso y quedan en el historial. Los completos manuales `COPY_ONLY` no sustituyen los completos automáticos. La inspección local de solo lectura encontró la base en `SIMPLE`, sin respaldos registrados ni tarea **Repuesto-RespaldoAutomatico**; por tanto, no hay evidencia de ejecución automática o restauración comprobada. La migración no está aplicada por este trabajo. Para habilitarla en un entorno autorizado, aplicar primero **011** y después **012**, luego utilizar `taller_repuestos/scripts/instalar_respaldos.ps1`. La tarea requiere sesión de Windows abierta y servicio SQL activo, **no abrir PHP ni la página**. No elimina archivos antiguos ni proporciona una copia fuera del equipo. Detalles y limitaciones en la guía de la aplicación.

Este es un prototipo local sin autenticación de usuarios; no debe exponerse a Internet ni presentarse como un sistema listo para producción.
