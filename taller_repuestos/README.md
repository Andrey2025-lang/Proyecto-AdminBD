# Repuesto · Gestión de repuestos

Prototipo académico con PHP, Bulma, HTMX y la base **SQL Server** `TallerRepuestosDB`. El ejemplo del profesor y el SQL original permanecen intactos.

## Estado de la instalación local

La conexión de `config.php` está configurada para `localhost` con autenticación de Windows. Se creó `TallerRepuestosDB` después de comprobar que no existía y se cargaron los datos de prueba.

Se verificaron las siete pantallas contra SQL Server y el flujo real desde HTTP: compra de 5 filtros (10 → 15), venta de 3 (15 → 12), rechazo por stock insuficiente y reversión completa de una venta con cliente inexistente. La compra y la venta de demostración se conservaron para consultar sus detalles e historial. El precio definitivo de la venta se obtuvo del servidor, aunque la petición enviara otro precio.

**No vuelva a ejecutar el script original para iniciar la aplicación:** la base ya está creada y contiene información.

## Qué incluye

- Inicio con indicadores reales y alertas de existencias.
- Repuestos: búsqueda, alta, edición, categorías y desactivación.
- Clientes y proveedores: búsqueda, alta y edición.
- Compras: recepción transaccional, total calculado en servidor e incremento de existencias por trigger.
- Ventas: precio obtenido del catálogo en el servidor, vendedor seleccionado, procedimiento almacenado y control de existencias.
- Listado y detalle de compras y ventas, existencias e historial de entradas y salidas.
- Formularios con validación, consultas parametrizadas, escape HTML y token CSRF.
- Comprobación previa de códigos de barras y cédulas repetidos, incluso al editar (se permite conservar el valor del propio registro). SQL Server mantiene sus restricciones UNIQUE como protección final ante peticiones simultáneas.
- Categorías repetidas rechazadas por la aplicación y la base: se ignoran mayúsculas/minúsculas y espacios al inicio/final. Las tildes se conservan como diferencia según la intercalación española configurada. Referencias a categorías, clientes y proveedores comprobadas antes de guardar. Los precios, cantidades, longitudes y estados también se validan en el servidor.
- Protección de reenvíos de compras y ventas mediante claves de formulario firmadas y un registro transaccional en la base. Reenviar una operación confirmada no vuelve a modificar existencias ni historial, incluso desde conexiones simultáneas. Una operación nueva con los mismos datos sigue siendo válida.
- Menú lateral plegable con preferencia guardada en escritorio y panel superpuesto accesible en móvil.
- Paginación predeterminada de 15 filas, con selector al pie; existencias e historial tienen 10 por sección y controles independientes.
- Reportes Excel `.xlsx`, CSV y documento imprimible para guardar como PDF. Filas, metadatos y totales se consultan de forma consistente desde la base.

## 1. Requisitos

- PHP **8.1 o superior**, con `PDO` y `pdo_sqlsrv`.
- Microsoft ODBC Driver 17 o 18 for SQL Server y Microsoft Drivers for PHP for SQL Server compatibles con la versión, arquitectura y modalidad TS/NTS de PHP.
- SQL Server o SQL Server Express accesible. `sqlcmd` o SSMS por sí solos **no** son el motor de base de datos.
- Internet para Bulma y las fuentes de Google. HTMX utiliza el archivo local del ejemplo del profesor: no mueva esta carpeta fuera del proyecto sin actualizar esa ruta.

Comprobar PHP en este equipo (no está agregado al PATH):

```powershell
& 'C:\php\php.exe' -v
& 'C:\php\php.exe' -m
```

Documentación del controlador: https://learn.microsoft.com/sql/connect/php/download-drivers-php-sql-server

## 2. Crear y poblar la base

Si la base **todavía no existe**, ejecute `../TallerRepuestosDB.sql` con SSMS o sqlcmd.

**Advertencia: el script original elimina la base si ya existe. No lo vuelva a ejecutar sobre información que quiera conservar. La aplicación nunca lo ejecuta automáticamente.**

Después ejecute `sql/001_control_duplicidad.sql`. Es una migración incremental que puede repetirse sin recrear la base: agrega una clave única de categoría normalizada, rechaza nombres vacíos y crea `RP14_SOLICITUDES_OPERACION`. Si encuentra categorías duplicadas preexistentes, se detiene y las muestra; no decide cuál borrar ni modifica sus relaciones. La migración ya está aplicada en la instalación local.

Ejemplo para la instancia local:

```powershell
sqlcmd -S localhost -E -C -b -f 65001 -i '.\taller_repuestos\sql\001_control_duplicidad.sql'
```

Ejecute también `sql/003_indices_historial.sql`. Agrega índices según las consultas reales y hace que las entradas/salidas del historial usen la fecha del encabezado de compra/venta, sin modificar movimientos existentes ni desactivar triggers.

Para cargar el negocio con historia, ejecute `sql/002_carga_historica.sql` **después de 001 y 003**. Sus cantidades configurables por defecto son: 5.000 clientes, 2.000 repuestos, 4 vendedores, 15 proveedores, 15.000 ventas y 1.500 compras. Son registros adicionales a los existentes. El lote ya está cargado localmente para el período 2024-10-29 a 2026-10-29.

La carga construye los datos en SQL, no en PHP/JavaScript, utiliza bloques de inserción dentro de una transacción, mantiene las restricciones y triggers activos y verifica que el saldo nunca sea negativo en orden cronológico. Una marca en `RP15_CARGAS_DATOS` impide repetirla; si cambia las cantidades del mismo lote, se detiene. Las colisiones con clientes, vendedores, proveedores o códigos preexistentes también la detienen sin sobrescribirlos.

Los nombres y referencias son sintéticos: los correos utilizan el dominio reservado `example.com`, los documentos y teléfonos no son identidades verificadas y las compatibilidades de vehículos son ilustrativas. No utilizar esos datos para contactar personas ni como catálogo técnico de reparación. Los códigos internos de los productos cargados tienen dígito de control EAN-13.

```powershell
sqlcmd -S localhost -E -C -b -f 65001 -i '.\taller_repuestos\sql\003_indices_historial.sql'
sqlcmd -S localhost -E -C -b -f 65001 -y 0 -i '.\taller_repuestos\sql\002_carga_historica.sql'
```

Como alternativa pequeña, `sql/datos_prueba.sql` incluye cuatro repuestos, tres categorías, un cliente, un proveedor, un vendedor y existencias ingresadas mediante una compra. No es necesario ejecutarlo si se utiliza la carga histórica. En esta instalación esos ejemplos previos se conservaron.

Ejemplo con autenticación de Windows, sustituyendo la instancia:

```powershell
sqlcmd -S '.\SQLEXPRESS' -E -b -f 65001 -i '.\taller_repuestos\sql\datos_prueba.sql'
```

No es necesario tener SSMS abierto para que la aplicación funcione; sí debe estar activo el servicio de SQL Server.

## 3. Configurar PHP

Copie `config.example.php` como `config.php` y ajuste `server`, `database` y la autenticación.

```powershell
Copy-Item '.\taller_repuestos\config.example.php' '.\taller_repuestos\config.php'
```

- Con `integrated_auth => true`, PHP se conecta usando la cuenta de Windows que lo ejecuta.
- Para autenticación SQL, use `integrated_auth => false` y configure `username` y `password` de un usuario con acceso a esta base. No utilice `sa` como cuenta de aplicación.
- En instancias con puerto fijo, `server` puede ser `localhost,1433`.
- `trust_server_certificate` es una facilidad para desarrollo local; en producción se debe validar el certificado.
- La cuenta necesita consultar/insertar/actualizar los catálogos, consultar transacciones e historial, registrar compras, consultar/insertar `RP14_SOLICITUDES_OPERACION`, ejecutar el procedimiento de venta y permisos suficientes para los triggers según la configuración de SQL Server.

## 4. Abrir el prototipo

Desde la carpeta **ParteProgramada**, ejecute:

```powershell
& 'C:\php\php.exe' -S 127.0.0.1:8080 -t .
```

- Aplicación: http://127.0.0.1:8080/taller_repuestos/
- Reportes: http://127.0.0.1:8080/taller_repuestos/index.php?page=reportes

La vista con datos incrustados fue retirada. Todas las pantallas consultan la base; la antigua URL `?preview=1` redirige al inicio real.

Para detener el servidor: `Ctrl+C` en su terminal. El servidor integrado de PHP es solo para desarrollo; no exponerlo a Internet.

## Demostración sugerida

1. Cargue los datos de prueba y abra Inicio.
2. Consulte `DEMO-FILTRO-01`: tendrá 10 unidades si no se han hecho movimientos posteriores.
3. Registre una compra de 5 unidades: debe quedar en 15.
4. Registre una venta de 3 con el cliente y vendedor de demostración: debe quedar en 12.
5. Intente vender 13: la aplicación debe impedirlo sin crear una venta parcial. Además de la validación de pantalla, el servidor y SQL Server validan las existencias.
6. Consulte el detalle de la operación e Inventario e historial.
7. Recargue el navegador: los datos deben persistir.

## Alcance y decisiones

- Es una aplicación interna local, **sin inicio de sesión**. Elegir un vendedor no autentica a una persona. No se presenta como lista para producción.
- Cada nueva compra o venta permite un repuesto; el procedimiento original de venta acepta una sola referencia. El detalle muestra todas las líneas de operaciones existentes, incluida la compra inicial de demostración.
- No se permite editar/anular/eliminar compras o ventas confirmadas. Los triggers originales solo manejan INSERT, no reversiones. Agregar anulaciones requiere diseñar movimientos compensatorios.
- Repuestos nuevos empiezan con cero unidades. No se edita stock manualmente; se registra una compra. Los precios de compra y venta del catálogo se editan explícitamente; recibir una compra no cambia automáticamente esos precios.
- Los listados consultan solo la página solicitada, con 15 filas por defecto y opciones 15/30/50/100 al pie. Inventario e historial empiezan con 10 filas cada uno y ofrecen 10/15/30/50 de forma independiente (`tam` y `htam`). Los tamaños antiguos de 25 se aceptan por compatibilidad con enlaces previos, pero no son el valor predeterminado. El orden se estabiliza por nombre/fecha más ID; entre solicitudes distintas los registros pueden desplazarse si alguien inserta o modifica datos. Las búsquedas de nombres admiten coincidencias internas; no se promete una búsqueda indexada por prefijo cuando se usa `%texto%`.
- Los buscadores de clientes, proveedores y repuestos son un solo campo con sugerencias, sin segundo selector. Al enfocar se muestran registros paginados de 20 en 20 y «Mostrar más» permite recorrerlos. Desde dos caracteres se filtran por nombre, cédula o código; con uno solo no se consulta la base. Se pueden usar flechas, Enter, Escape, ratón y el botón de limpiar. Modificar el texto invalida el ID anterior, las peticiones viejas se cancelan y el servidor vuelve a comprobar las referencias al guardar. Una selección válida se conserva al corregir un formulario rechazado.
- Los importes se muestran sin símbolo: el modelo no define una moneda, impuestos ni descuentos.
- Compras quedan atribuidas a `sistema` en el historial por diseño del SQL original. Ventas quedan atribuidas al vendedor seleccionado.
- La tabla de auditoría, respaldos y extracción DW del SQL original no tienen módulo administrativo en esta versión. El historial visible es el inventario, no una auditoría completa.
- No modificar existencias desde PHP después de insertar un detalle: los triggers ya hacen esa operación.
- Cada formulario de compra/venta tiene una clave distinta. Un reenvío idéntico de una solicitud confirmada devuelve una confirmación sin volver a guardarla; la misma clave con datos diferentes se rechaza. Si la operación falla, su clave también se revierte y el formulario puede corregirse y reintentarse. Para realizar otra operación, abrir un formulario nuevo.
- No eliminar filas de `RP14_SOLICITUDES_OPERACION` para limpiar historial: son las constancias que impiden procesar reenvíos antiguos. Esta protección aplica a las solicitudes de la aplicación, no convierte dos INSERT manuales de SSMS en una única operación.
- Nombres de clientes/repuestos, correos y teléfonos no son únicos por defecto: compartirlos puede ser legítimo. El proveedor aún no tiene una identificación fiscal en este modelo, por lo que no se inventa una regla de unicidad por nombre.

## Descargar información

Abrir **Reportes**, seleccionar el tipo de informe, sus fechas y el formato. También hay botones **Descargar Excel** e **Imprimir / PDF** en cada listado, que conservan sus filtros y exportan todos los resultados, no solo la página actual.

- **Excel:** archivo `.xlsx` real con hojas **Resumen** y **Datos**. Incluye tabla nativa verde, filas alternadas, encabezados y primera columna inmovilizados, autofiltros, fechas nativas, anchos de columna y configuración de impresión. Los importes son números y las cédulas/códigos son texto. En compras y ventas, el resumen muestra operaciones, importe y promedio por mes y vendedor/proveedor; inventario se agrupa por categoría y movimientos por mes. El total visible de la tabla usa una fórmula SUBTOTAL generada por el sistema y conserva su valor calculado al emitir el archivo. La hoja Resumen mantiene los totales del conjunto exportado. La compresión ZIP es incremental; no se construye todo el libro en memoria ni requiere Composer.
- **CSV:** UTF-8 con BOM y separador `;`, compatible con Excel. Se neutralizan textos que puedan interpretarse como fórmulas. En XLSX se utilizan cadenas explícitas, nunca fórmulas procedentes de datos.
- **Documento:** HTML de impresión con marca, fecha de emisión, filtros, tarjetas de indicadores, resúmenes y tablas con bandas verdes. Los encabezados se repiten al imprimir y los importes se alinean a la derecha. En el navegador pulsar **Imprimir / Guardar PDF** y seleccionar guardar como PDF. No es una descarga de PDF generada por el servidor ni un archivo Word. CSV no admite estilos visuales.
- Compras, ventas y movimientos aceptan fecha inicial y final **inclusivas**. Clientes y catálogo no tienen fecha de creación en el modelo y no se filtran por un dato inexistente. Inventario muestra existencias actuales, no un saldo retrospectivo; las fechas del módulo filtran únicamente su historial.
- Máximo 100.000 filas en Excel/CSV y 5.000 en impresión. Si se excede, se pide reducir filtros: no se entrega un reporte truncado silenciosamente. Los reportes vacíos también son válidos.
- Conteo, totales y filas se leen en una transacción SERIALIZABLE para evitar discrepancias por cambios simultáneos. Puede bloquear brevemente operaciones concurrentes mientras se construye el archivo temporal; la transacción termina **antes** de enviarlo al navegador. El archivo se genera por filas, sin cargar toda la exportación en memoria, y se elimina al cerrar la descarga.
- Son documentos informativos: no facturas ni certificaciones contables con firma digital. No se implementó inicio de sesión; no exponer la aplicación a Internet.

## Estructura

```text
taller_repuestos/
  index.php               Entrada y navegación completa/parcial HTMX
  config.example.php      Plantilla de conexión, sin credenciales reales
  src/Database.php        Conexión y consultas
  src/Actions.php         Validación y operaciones transaccionales
  src/OperationRequests.php Claves de formulario y protección de reenvíos
  src/Views.php           Pantallas y formularios
  src/Queries.php         Filtros, consultas paginadas y búsquedas acotadas
  src/Reports.php         Exportaciones y lectura consistente de reportes
  src/ReportLayout.php    Resúmenes y formato del documento imprimible
  src/XlsxWriter.php      Libro Excel por filas, sin dependencias externas
  assets/app.css          Diseño responsivo
  assets/app.js           Estimación de totales y navegación
  sql/datos_prueba.sql     Datos ficticios sin borrar tablas
  sql/001_control_duplicidad.sql Migración incremental, sin recrear la base
  sql/002_carga_historica.sql Generador de datos sintéticos con marca de lote
  sql/003_indices_historial.sql Índices y fechas de movimientos
  tests/validaciones.php  Pruebas locales sin base de datos
```

## Comprobaciones

```powershell
Get-ChildItem '.\taller_repuestos' -Filter *.php -Recurse | ForEach-Object { & 'C:\php\php.exe' -l $_.FullName }
& 'C:\php\php.exe' '.\taller_repuestos\tests\validaciones.php'
& 'C:\php\php.exe' '.\taller_repuestos\tests\pantallas.php'
& 'C:\php\php.exe' '.\taller_repuestos\tests\catalogos.php'
& 'C:\php\php.exe' '.\taller_repuestos\tests\duplicidad.php'
node --check '.\taller_repuestos\assets\app.js'
python '.\taller_repuestos\tests\paginacion.py'
python '.\taller_repuestos\tests\reportes.py'
python '.\taller_repuestos\tests\rendimiento.py'
sqlcmd -S localhost -E -C -b -y 0 -i '.\taller_repuestos\tests\carga_historica.sql'
```

Para una prueba integral hace falta la instancia real: comprobar compras, ventas, rechazos por stock insuficiente y movimientos con el escenario anterior. Si falla una consulta, revise la salida del servidor PHP; los errores internos no se muestran al usuario.

`tests/catalogos.php` requiere la conexión SQL Server configurada. Prueba altas y ediciones, códigos y cédulas duplicados, categorías inválidas y validaciones de campos. Crea sus propios registros dentro de una transacción y los revierte al terminar, sin conservar datos de prueba ni cambiar las existencias actuales. Las identidades de SQL Server pueden avanzar aunque la transacción se revierta; los saltos en los ID son normales.

`tests/duplicidad.php` requiere la migración aplicada y un vendedor activo. Crea registros propios `TEST-*`, comprueba categorías desde SQL directo, reenvíos, firmas inválidas, corrección tras errores y dos conexiones PHP independientes enviando simultáneamente la misma compra/venta. Al terminar elimina únicamente los IDs y claves generados por esa ejecución. No modifica las existencias de los repuestos existentes; las identidades pueden avanzar.

Los errores de restricciones de SQL Server se interpretan por su código nativo (`PDOException::errorInfo[1]`), no buscando números en el texto del mensaje. Así, un código duplicado muestra «Ya existe un repuesto con ese código de barras», en lugar de un error genérico de conexión.

También se incluye una prueba integral repetible que utiliza HTTP y consulta los resultados con `sqlcmd`:

```powershell
python '.\taller_repuestos\tests\integracion.py' --confirmar-escritura
```

**Esta prueba escribe en la base:** cada ejecución agrega una compra de 5 filtros y una venta de 3, por lo que las existencias aumentan en 2. Usa los registros DEMO, requiere el servidor PHP en el puerto 8080 y no elimina información. Las ventas rechazadas no deben agregar encabezados, detalles ni movimientos.

Las pruebas Python de paginación, reportes y rendimiento son de solo lectura. `reportes.py` valida ZIP/XML del Excel, filas y totales contra SQL Server, CSV, impresión, archivos vacíos y filtros inválidos. `carga_historica.sql` verifica saldos cronológicos, inventario y subtotales.

`tests/interfaz.mjs` comprueba el comportamiento real en un navegador Edge/Chrome iniciado para pruebas con CDP en el puerto 9327. Requiere Node con WebSocket nativo (22 o superior), no paquetes npm. Verifica menú plegable y móvil, tamaños de filas, búsquedas con mínimo de dos caracteres, «Mostrar más», teclado, invalidación de selecciones y documento imprimible. Es de solo lectura: no envía formularios POST. Puede recibir un directorio como argumento para guardar capturas. Ejecutar las pruebas de lectura cuando hayan terminado las pruebas que insertan y limpian datos temporales, para no comparar páginas sobre un conjunto que está cambiando.

### Medición local de referencia

Medición histórica anterior al ajuste visual de 15/10 filas: medianas de cinco solicitudes HTTP tras calentamiento, en este equipo y con la misma base ya poblada. La referencia original usa listados de 100 filas y selectores completos; la versión medida usaba páginas de 25, conteos y selectores de hasta 20. Los índices de la migración 003 estaban presentes en ambas mediciones. No representa un nuevo benchmark de la interfaz actual.

| Pantalla | Antes (ms) | Después (ms) | HTML antes | HTML después |
|---|---:|---:|---:|---:|
| Ventas | 93,93 | 19,51 | 580.416 bytes | 24.440 bytes |
| Compras | 45,38 | 14,67 | 304.872 bytes | 22.620 bytes |
| Clientes | 10,13 | 12,25 | 36.214 bytes | 14.512 bytes |
| Repuestos | 10,16 | 13,18 | 59.299 bytes | 22.788 bytes |
| Inventario | 14,29 | 23,47 | 49.812 bytes | 19.486 bytes |

No todas las consultas son más rápidas: conteos y filtros agregan trabajo a listados pequeños. La mejora principal es evitar transferir catálogos completos y limitar filas por solicitud. Las ventas paginan primero los IDs de encabezado cuando no hay búsqueda por cliente; los joins de nombres solo recuperan la página. Los totales sin búsqueda evitan joins innecesarios.

Se comprobó también un Excel de 59.045 movimientos: aproximadamente 2,15 MB comprimidos y 4,27 s **incluyendo descarga y validación ZIP/XML en Python**. Son mediciones de referencia, no garantías para otros equipos o cargas concurrentes.
