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
- Catálogo de cada proveedor: desde Proveedores → Repuestos se habilitan, deshabilitan o dejan pendientes los repuestos que ese proveedor ofrece, con costo de referencia y código propios. Una compra solo admite repuestos habilitados para el proveedor elegido; la regla se comprueba en el servidor y en la base.
- Compras y ventas de varios repuestos: carrito editable, hasta 100 partidas, total calculado en servidor y confirmación completa o reversión de toda la operación.
- Ventas: precio obtenido del catálogo en el servidor, detección de cambios de precio, vendedor seleccionado, procedimiento almacenado y control acumulado de existencias.
- Detalle de compras y ventas en ventana superpuesta con partidas, totales y comprobante individual en Excel o impresión/PDF.
- Formularios con validación, consultas parametrizadas, escape HTML y token CSRF.
- Comprobación previa de códigos de barras y cédulas repetidos, incluso al editar (se permite conservar el valor del propio registro). SQL Server mantiene sus restricciones UNIQUE como protección final ante peticiones simultáneas.
- Categorías repetidas rechazadas por la aplicación y la base: se ignoran mayúsculas/minúsculas y espacios al inicio/final. Las tildes se conservan como diferencia según la intercalación española configurada. Referencias a categorías, clientes y proveedores comprobadas antes de guardar. Los precios, cantidades, longitudes y estados también se validan en el servidor.
- Protección de reenvíos de compras y ventas mediante claves de formulario firmadas y un registro transaccional en la base. Reenviar una operación confirmada no vuelve a modificar existencias ni historial, incluso desde conexiones simultáneas. Una operación nueva con los mismos datos sigue siendo válida.
- Menú lateral automático al pasar el mouse, con ajuste del ancho del contenido. El botón Menú permite fijarlo abierto y conserva esa preferencia. En móvil funciona al tocarlo. Solo se muestran los siete módulos originales; Reportes, Usuarios, Categorías y respaldos no agregan accesos al menú.
- Edición de repuestos, clientes y proveedores en ventana superpuesta: errores dentro del formulario y actualización del listado conservando filtros/página. Cambiar de módulo devuelve la pantalla al inicio; paginar conserva la zona de navegación.
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

Ejecute `sql/004_operaciones_multiples.sql` después de 001 y 003. Añade `id_operacion` a las constancias de envío y crea `sp_ProcesarVentaMultiple`, sin eliminar el procedimiento original ni alterar registros anteriores. Requiere SQL Server 2016 o posterior y compatibilidad de base 130 o superior para `OPENJSON`. Ya se aplicó localmente:

```powershell
sqlcmd -S localhost -E -C -b -f 65001 -i '.\taller_repuestos\sql\004_operaciones_multiples.sql'
```

Ejecute `sql/008_catalogo_proveedores.sql` al final. Crea `RP19_PROVEEDOR_REPUESTO`, relaciona los repuestos que cada proveedor ya vendió en la historia y agrega un trigger que impide incluir en una compra un repuesto no habilitado para ese proveedor. Las relaciones importadas del historial quedan **pendientes de confirmar**; solo las del lote sintético se marcan como habilitadas para que los datos de prueba permitan comprar. No modifica compras, detalles ni existencias anteriores:

```powershell
sqlcmd -S localhost -E -C -b -f 65001 -i '.\taller_repuestos\sql\008_catalogo_proveedores.sql'
```

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
- La cuenta necesita consultar/insertar/actualizar los catálogos, consultar transacciones e historial, registrar compras, consultar/insertar/actualizar `RP14_SOLICITUDES_OPERACION`, ejecutar `sp_ProcesarVentaMultiple` y permisos suficientes para los triggers según la configuración de SQL Server.

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

### Precios finales e IVA incluido

Aplicar `sql/010_iva_incluido.sql` después de 004. Las ventas nuevas interpretan el precio de venta del catálogo como precio final: **no se aumenta el precio ni se añade otro IVA al confirmar**. La tarifa se consulta desde `RP20_TARIFAS_IVA` y `RP21_CONFIG_FISCAL`; se inicializa la tarifa general del 13 %. Referencia: [Ministerio de Hacienda, tarifas del IVA](https://www.hacienda.go.cr/docs/TarifasdelIVA.pdf). El alcance es venta ordinaria de repuestos bajo tarifa general; no certifica clasificación CABYS, exoneraciones ni facturación electrónica.

`sp_ProcesarVentaMultiple` llama a `sp_RP_RegistrarIVA` antes del COMMIT. Por cada renglón guarda la tarifa utilizada, base redondeada a dos decimales (`total / (1 + tarifa/100)`) e IVA como diferencia respecto al total final. El encabezado suma las bases y los impuestos de los renglones; las restricciones verifican que base más IVA coincida con el total. Cambiar la tarifa configurada afecta operaciones futuras, no documentos confirmados. La vista previa es orientativa; el cálculo definitivo y su validación se realizan en la base.

En compras se ingresa el costo final pagado. Por defecto **no hay desglose confirmado**, que no equivale a compra exenta. Solo se extrae el IVA cuando se selecciona una tarifa y se confirma que todos los precios incluyen esa tarifa según el comprobante del proveedor. Para tarifas mixtas, exoneraciones, o diferencias con el desglose del proveedor, no activar esa opción: este flujo no representa todavía esos casos. Nunca se presume el IVA del proveedor por tener un producto asociado.

El detalle y los comprobantes muestran «Los precios incluyen IVA», tarifa, base e impuesto cuando están registrados. Los reportes separan IVA registrado y cantidad de operaciones sin desglose; no extrapolan impuestos a toda la historia. Las operaciones anteriores conservan todos sus importes y quedan con base/IVA nulos, mostrando «Desglose de IVA no registrado». No se han añadido descuentos, otros impuestos ni un DW nuevo en esta mejora.

- Los controles de filtros tienen altura uniforme y el mensaje de búsqueda ocupa su propia fila. Una recarga real del navegador restablece los filtros del módulo, la primera página y los tamaños predeterminados (15 en listados y 10 en inventario/historial). No se limpian al paginar, editar ni abrir detalles. Los enlaces filtrados siguen funcionando al abrirlos; el reinicio solo ocurre al recargar. Las descargas y los comprobantes no se redirigen.

- Los listados se filtran mientras se escribe: mínimo dos caracteres y pausa de 350 ms. Con un carácter no se envía una consulta y se mantienen los resultados previos con un aviso; al borrar se recupera el listado sin búsqueda. Fechas y selectores se aplican al cambiar. Cada consulta conserva los otros filtros y tamaños, vuelve a la primera página y cancela la petición anterior. Las filas siguen paginadas desde la base, no se descargan los catálogos completos para filtrarlos en el navegador. Sin JavaScript queda disponible el botón Buscar.

- Es una aplicación interna local, **sin inicio de sesión**. Elegir un vendedor no autentica a una persona. No se presenta como lista para producción.
- Cada compra o venta permite hasta 100 partidas. Seleccione un repuesto, cantidad y precio de compra si corresponde, pulse **Agregar a la operación** y repita. Las cantidades y precios de compra del carrito se pueden corregir antes de confirmar. En ventas, el precio lo determina el catálogo, no el navegador. Las ventas agrupan repuestos repetidos; las compras agrupan repuesto y precio iguales y conservan partidas de distinto costo. El servidor valida cantidades acumuladas, stock, referencias, precios y límite total antes de confirmar un solo encabezado. Si falla una partida, revierte encabezado, detalles, historial y clave de envío.
- En compras, el buscador de repuestos se limita al catálogo habilitado del proveedor elegido. Si cambia el proveedor, los renglones anteriores se marcan para revisarlos y el servidor rechaza la operación completa si alguno no pertenece al nuevo proveedor. La relación se administra en Proveedores → **Repuestos**, sin agregar módulos al menú. El costo de referencia es orientativo: la compra guarda el costo real confirmado y no altera compras anteriores.
- Los bloqueos de productos se adquieren en orden de ID. El procedimiento nuevo recibe las partidas como JSON y mantiene los triggers como única fuente de cambios de stock. El procedimiento original se conserva para compatibilidad, pero la aplicación usa el nuevo.
- El botón **Ver detalle** abre una ventana sin abandonar el listado ni perder filtros o página. Puede cerrarse con Escape, el botón de cierre o el fondo. Después de confirmar se abre el comprobante de la operación; el reenvío recupera la misma referencia.
- No se permite editar/anular/eliminar compras o ventas confirmadas. Los triggers originales solo manejan INSERT, no reversiones. Agregar anulaciones requiere diseñar movimientos compensatorios.
- Repuestos nuevos empiezan con cero unidades. No se edita stock manualmente; se registra una compra. Los precios de compra y venta del catálogo se editan explícitamente; recibir una compra no cambia automáticamente esos precios.
- Los listados consultan solo la página solicitada, con 15 filas por defecto y opciones 15/30/50/100 al pie. Inventario e historial empiezan con 10 filas cada uno y ofrecen 10/15/30/50 de forma independiente (`tam` y `htam`). Los tamaños antiguos de 25 se aceptan por compatibilidad con enlaces previos, pero no son el valor predeterminado. El orden se estabiliza por nombre/fecha más ID; entre solicitudes distintas los registros pueden desplazarse si alguien inserta o modifica datos. Las búsquedas de nombres admiten coincidencias internas; no se promete una búsqueda indexada por prefijo cuando se usa `%texto%`.
- Los buscadores de clientes, proveedores y repuestos son un solo campo con sugerencias, sin segundo selector. Al enfocar se muestran registros paginados de 20 en 20 y «Mostrar más» permite recorrerlos. Desde dos caracteres se filtran por nombre, cédula o código; con uno solo no se consulta la base. Se pueden usar flechas, Enter, Escape, ratón y el botón de limpiar. Modificar el texto invalida el ID anterior, las peticiones viejas se cancelan y el servidor vuelve a comprobar las referencias al guardar. Una selección válida se conserva al corregir un formulario rechazado.
- Los importes se muestran en colones costarricenses (CRC, ₡) de forma uniforme en pantallas, comprobantes, reportes y archivos. Se registra IVA incluido en operaciones nuevas según el alcance descrito arriba; no hay descuentos ni devoluciones.
- Compras quedan atribuidas a `sistema` en el historial por diseño del SQL original. Ventas quedan atribuidas al vendedor seleccionado.
- La tabla de auditoría, respaldos y extracción DW del SQL original no tienen módulo administrativo en esta versión. El historial visible es el inventario, no una auditoría completa.
- No modificar existencias desde PHP después de insertar un detalle: los triggers ya hacen esa operación.
- Cada formulario de compra/venta tiene una clave distinta. Un reenvío idéntico de una solicitud confirmada devuelve una confirmación sin volver a guardarla; la misma clave con datos diferentes se rechaza. Si la operación falla, su clave también se revierte y el formulario puede corregirse y reintentarse. Para realizar otra operación, abrir un formulario nuevo.
- No eliminar filas de `RP14_SOLICITUDES_OPERACION` para limpiar historial: son las constancias que impiden procesar reenvíos antiguos. Esta protección aplica a las solicitudes de la aplicación, no convierte dos INSERT manuales de SSMS en una única operación.
- Nombres de clientes/repuestos, correos y teléfonos no son únicos por defecto: compartirlos puede ser legítimo. El proveedor aún no tiene una identificación fiscal en este modelo, por lo que no se inventa una regla de unicidad por nombre.

## Descargar información

Abrir **Reportes**, seleccionar el tipo de informe, sus fechas y el formato. También hay botones **Descargar Excel** e **Imprimir / PDF** en los listados comerciales y de inventario, que conservan sus filtros y exportan todos los resultados, no solo la página actual. Clientes y proveedores no ofrecen exportación masiva de directorios.

- **Ventas y compras:** importes y número de operaciones calculados desde encabezados para no duplicar totales al tener varias partidas. Incluyen resumen mensual, resultados por vendedor/proveedor y primeros 20 repuestos por unidades, obtenidos desde detalles. CSV conserva el listado de operaciones; los resúmenes adicionales están en Excel e impresión.
- **Reposición:** repuestos activos con stock menor o igual al mínimo. Sugiere unidades para llegar al mínimo más uno y estima el costo al precio de compra actual, no como cotización garantizada.
- **Kardex:** exige seleccionar un repuesto, también permite referencias inactivas. Ordena movimientos por fecha e ID, con entrada, salida y saldo después de cada movimiento; Excel e impresión añaden saldos anterior al primero y posterior al último. Los saldos se reconstruyen anclados en el stock actual, considerando todos sus movimientos antes de aplicar las fechas. No inventa un saldo inicial cero ni oculta diferencias causadas por ajustes manuales fuera de la aplicación.
- **Comprobante individual:** desde el detalle de compra o venta, descarga Excel o abre la impresión para guardar como PDF. Incluye datos de contraparte, responsable cuando corresponde, cantidades, precios registrados y total. Si las partidas no coinciden con el encabezado, muestra una advertencia y rechaza emitir el documento.

- **Excel:** archivo `.xlsx` real con hojas **Resumen** y **Datos**. Incluye tabla nativa verde, filas alternadas, encabezados y primera columna inmovilizados, autofiltros, fechas nativas, anchos de columna y configuración de impresión. Los importes son números y las cédulas/códigos son texto. En compras y ventas, el resumen muestra operaciones, importe y promedio por mes y vendedor/proveedor; inventario se agrupa por categoría y movimientos por mes. El total visible de la tabla usa una fórmula SUBTOTAL generada por el sistema y conserva su valor calculado al emitir el archivo. La hoja Resumen mantiene los totales del conjunto exportado. La compresión ZIP es incremental; no se construye todo el libro en memoria ni requiere Composer.
- **CSV:** UTF-8 con BOM y separador `;`, compatible con Excel. Se neutralizan textos que puedan interpretarse como fórmulas. En XLSX se utilizan cadenas explícitas, nunca fórmulas procedentes de datos.
- **Documento:** HTML de impresión con marca, fecha de emisión, filtros, tarjetas de indicadores, resúmenes y tablas con bandas verdes. Los encabezados se repiten al imprimir y los importes se alinean a la derecha. En el navegador pulsar **Imprimir / Guardar PDF** y seleccionar guardar como PDF. No es una descarga de PDF generada por el servidor ni un archivo Word. CSV no admite estilos visuales.
- Compras, ventas, movimientos y kardex aceptan fecha inicial y final **inclusivas**. El catálogo no tiene fecha de creación y no se filtra por un dato inexistente. Inventario y reposición muestran existencias actuales, no un saldo retrospectivo; las fechas del módulo filtran únicamente su historial.
- Máximo 100.000 filas en Excel/CSV y 5.000 en impresión. Si se excede, se pide reducir filtros: no se entrega un reporte truncado silenciosamente. Los reportes vacíos también son válidos.
- Conteo, totales y filas se leen en una transacción SERIALIZABLE para evitar discrepancias por cambios simultáneos. Puede bloquear brevemente operaciones concurrentes mientras se construye el archivo temporal; la transacción termina **antes** de enviarlo al navegador. El archivo se genera por filas, sin cargar toda la exportación en memoria, y se elimina al cerrar la descarga.
- Son documentos informativos: no facturas ni certificaciones contables con firma digital. No se implementó inicio de sesión; no exponer la aplicación a Internet.

## Respaldos automáticos

### Respaldo manual desde Inicio

Aplicar `sql/009_respaldos_manuales.sql` después de 005. En **Inicio → Respaldo de la información → Crear respaldo** se solicita una copia completa `COPY_ONLY`, con nombre único, CHECKSUM, compresión y VERIFYONLY. Nunca reemplaza la base ni elimina copias anteriores. No ofrece restauración desde la página.

El historial, los resultados y los intervalos se consultan de SQL Server: `RP16_RESPALDOS` y `RP17_CONFIG_RESPALDOS`. Valores iniciales: completo automático cada 24 horas, registro cada 30 minutos y cinco minutos entre solicitudes manuales. El bloqueo `Repuesto.Respaldos` es compartido por las ejecuciones manuales y automáticas. Un intento manual fallido registrado también consume el intervalo para evitar repeticiones continuas. Una solicitud interrumpida debe revisarse antes de repetirla; un registro en proceso no prueba por sí solo que la tarea siga activa.

La tarea de Windows existente sigue ejecutándose cada 30 minutos; el procedimiento decide si corresponde una copia. Cambiar el intervalo en la base no modifica la frecuencia de la tarea: un intervalo menor requiere ajustar también su disparador. `programado` es información de instalación, no una comprobación en vivo del Programador de tareas.

Prueba real: `python taller_repuestos/tests/respaldos.py`. Crea como máximo una copia manual, verifica rechazo de CSRF y repetición, COPY_ONLY y conservación de datos; no borra respaldos. La aplicación sigue siendo interna y sin autenticación: no exponer este botón a Internet. La cuenta de conexión necesita permisos de respaldo y verificación; no se conceden permisos adicionales automáticamente ni se aceptan rutas desde el navegador.

1. Aplicar `sql/005_respaldos.sql` después de las otras migraciones. Agrega historial y procedimiento; no borra datos ni modifica el procedimiento original del profesor.
2. Ejecutar `scripts/instalar_respaldos.ps1` desde PowerShell. Usa autenticación de Windows y registra la tarea **Repuesto-RespaldoAutomatico** cada 30 minutos. Si la tarea ya existe se detiene, sin sobrescribirla.
3. Ejecutar `scripts/respaldo.ps1` para la primera copia. Las siguientes ejecuciones generan un completo si no hay uno verificado en las últimas 24 horas; de lo contrario respaldan el registro de transacciones. Requiere permisos de respaldo y verificación en la instancia.

Los archivos tienen fecha, hora e identificador único y se guardan en la carpeta de respaldo de la instancia, no en el proyecto. Cada archivo usa compresión, CHECKSUM y RESTORE VERIFYONLY. `RP16_RESPALDOS` conserva resultados y errores; `RP17_CONFIG_RESPALDOS` registra la instalación, no garantiza que la tarea siga habilitada. Para comprobar el estado real:

```powershell
Get-ScheduledTask -TaskName Repuesto-RespaldoAutomatico
Get-ScheduledTaskInfo -TaskName Repuesto-RespaldoAutomatico
sqlcmd -S localhost -E -C -b -d TallerRepuestosDB -Q "SELECT TOP(10) inicio,fin,tipo,estado FROM dbo.RP16_RESPALDOS ORDER BY id_respaldo DESC"
```

**Instalación local:** tarea registrada y ejecución automática comprobada con resultado 0. El Agente de SQL estaba detenido y esta sesión no podía iniciarlo; por eso se utilizó el Programador de tareas de Windows. La tarea usa la sesión interactiva del usuario: necesita que Windows tenga esa sesión abierta y el servicio SQL esté activo, pero no requiere navegador ni PHP. Después de una suspensión intenta ejecutar tareas pendientes. No funciona con el equipo apagado o la sesión cerrada. Para ejecución sin sesión hace falta configurar una cuenta de servicio o el Agente con permisos de administrador; no se guardan contraseñas en scripts.

No se eliminan copias automáticamente: un registro puede depender de archivos anteriores. Hay que definir almacenamiento y retención antes de depurarlos. Esta copia en el mismo equipo **no protege contra perder el equipo/disco**; aún se necesita una segunda copia externa protegida. No subir `.bak` ni `.trn` a GitHub. Si mueve la carpeta del proyecto, debe actualizar la ruta de la tarea. La restauración completa necesita un completo y todos los registros posteriores necesarios, incluidos los generados por cualquier otro mecanismo de respaldo.

Prueba real, con permisos administrativos:

```powershell
sqlcmd -S localhost -E -C -b -f 65001 -i '.\taller_repuestos\tests\restauracion.sql'
```

Restaura el último completo y sus registros posteriores verificados en una base temporal con nombre único, ejecuta DBCC CHECKDB y elimina únicamente esa base temporal. Nunca reemplaza la base del negocio. Ya se comprobó localmente. VERIFYONLY valida el archivo, no sustituye esta prueba de restauración.

## Estructura de archivos

```text
taller_repuestos/
  index.php               Entrada y navegación completa/parcial HTMX
  config.example.php      Plantilla de conexión, sin credenciales reales
  src/Database.php        Conexión y consultas
  src/Actions.php         Validación y operaciones transaccionales
  src/OperationRequests.php Claves de formulario y protección de reenvíos
  src/Operations.php      Detalle y comprobantes individuales
  src/Views.php           Pantallas y formularios
  src/Queries.php         Filtros, consultas paginadas y búsquedas acotadas
  src/SupplierCatalog.php Catálogo de repuestos por proveedor
  src/Reports.php         Exportaciones y lectura consistente de reportes
  src/BusinessReports.php Resúmenes comerciales y análisis de compras
  src/ExportSelection.php Filtros y vista previa de descargas
  src/ReportLayout.php    Resúmenes y formato del documento imprimible
  src/XlsxWriter.php      Libro Excel por filas, sin dependencias externas
  assets/app.css          Diseño responsivo
  assets/app.js           Estimación de totales y navegación
  assets/operations.js    Carritos y ventanas de detalle
  assets/editor.js        Formularios de edición superpuestos
  assets/downloads.js     Diálogo de descargas con vista previa
  assets/supplier-catalog.js Catálogo del proveedor en ventana superpuesta
  src/Presentation.php   Formatos compartidos y nombres sencillos
  sql/datos_prueba.sql     Datos ficticios sin borrar tablas
  sql/001_control_duplicidad.sql Migración incremental, sin recrear la base
  sql/002_carga_historica.sql Generador de datos sintéticos con marca de lote
  sql/003_indices_historial.sql Índices y fechas de movimientos
  sql/004_operaciones_multiples.sql Procedimiento de venta de varios repuestos
  sql/005_respaldos.sql   Respaldos completos y del registro con verificación
  sql/006_indices_reportes.sql Índices para filtros y totales de reportes
  sql/007_historia_64_diarias.sql Carga de ~64 ventas por día hábil
  sql/008_catalogo_proveedores.sql Catálogo de repuestos por proveedor
  scripts/respaldo.ps1    Ejecución fuera de PHP
  scripts/instalar_respaldos.ps1 Registro de tarea de Windows
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
node --check '.\taller_repuestos\assets\operations.js'
node --check '.\taller_repuestos\assets\editor.js'
python '.\taller_repuestos\tests\paginacion.py'
python '.\taller_repuestos\tests\reportes.py'
python '.\taller_repuestos\tests\rendimiento.py'
sqlcmd -S localhost -E -C -b -y 0 -i '.\taller_repuestos\tests\carga_historica.sql'
```

Para una prueba integral hace falta la instancia real: comprobar compras, ventas, rechazos por stock insuficiente y movimientos con el escenario anterior. Si falla una consulta, revise la salida del servidor PHP; los errores internos no se muestran al usuario.

`tests/catalogos.php` requiere la conexión SQL Server configurada. Prueba altas y ediciones, códigos y cédulas duplicados, categorías inválidas y validaciones de campos. Crea sus propios registros dentro de una transacción y los revierte al terminar, sin conservar datos de prueba ni cambiar las existencias actuales. Las identidades de SQL Server pueden avanzar aunque la transacción se revierta; los saltos en los ID son normales.

`tests/duplicidad.php` requiere las migraciones aplicadas y un vendedor activo. Crea registros propios `TEST-*`, comprueba categorías, reenvíos, firmas, corrección tras errores y dos conexiones PHP simultáneas. También verifica varias partidas, cantidades acumuladas, distintos precios de compra, comprobantes, cambios de precio y reversión completa cuando falla una partida. Al terminar elimina únicamente los IDs y claves generados por esa ejecución. No modifica las existencias de los repuestos existentes; las identidades pueden avanzar.

Con el servidor local activo, `php tests/duplicidad.php --http` (o la ruta completa de PHP y del archivo desde la raíz) añade el envío real de compras y ventas múltiples con cookie, CSRF y claves obtenidas de los formularios. Verifica la referencia para abrir el detalle y el reenvío sin duplicar; limpia igualmente sus datos propios.

Los errores de restricciones de SQL Server se interpretan por su código nativo (`PDOException::errorInfo[1]`), no buscando números en el texto del mensaje. Así, un código duplicado muestra «Ya existe un repuesto con ese código de barras», en lugar de un error genérico de conexión.

También se incluye una prueba integral repetible que utiliza HTTP y consulta los resultados con `sqlcmd`:

```powershell
python '.\taller_repuestos\tests\integracion.py' --confirmar-escritura
```

**Esta prueba escribe en la base:** cada ejecución agrega una compra de 5 filtros y una venta de 3, por lo que las existencias aumentan en 2. Usa los registros DEMO, requiere el servidor PHP en el puerto 8080 y no elimina información. Las ventas rechazadas no deben agregar encabezados, detalles ni movimientos.

Las pruebas Python de paginación, reportes y rendimiento son de solo lectura. `reportes.py` valida ZIP/XML del Excel, filas y totales contra SQL Server, CSV, impresión, archivos vacíos, comprobantes individuales, restricciones de exportación y saldo final del kardex. `carga_historica.sql` verifica saldos cronológicos, inventario y subtotales.

`tests/interfaz.mjs` comprueba el comportamiento real en un navegador Edge/Chrome iniciado para pruebas con CDP en el puerto 9327. Requiere Node con WebSocket nativo (22 o superior), no paquetes npm. Verifica menú plegable y móvil, tamaños de filas, búsquedas, teclado, invalidación de selecciones, carritos editables, rechazo de cantidades excesivas, ventanas de compra/venta con conservación de filtros y devolución del foco, enlaces a comprobantes y documento imprimible. Es de solo lectura: no envía formularios POST. Puede recibir un directorio para guardar capturas. Ejecutar las pruebas de lectura cuando hayan terminado las pruebas que insertan y limpian datos temporales.

`tests/edicion.mjs` prueba el guardado real desde la ventana de edición, un rechazo por cédula duplicada y la conservación de filtros/página. Requiere el mismo navegador CDP, `sqlcmd` y el servidor PHP. Crea 16 clientes propios con prefijo aleatorio `UI-*` y los elimina al terminar; no edita clientes existentes. Ejecute esta prueba antes de las pruebas de solo lectura.

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
