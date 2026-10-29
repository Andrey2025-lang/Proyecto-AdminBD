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
- Categorías repetidas rechazadas por la aplicación y referencias a categorías, clientes y proveedores comprobadas antes de guardar. Los precios, cantidades, longitudes y estados también se validan en el servidor.
- Vista previa **de solo lectura con datos ilustrativos** para revisar el diseño sin una conexión.

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

Después ejecute `sql/datos_prueba.sql` sobre su instancia. Es transaccional, no elimina datos y evita repetir la recepción inicial de los repuestos DEMO. Incluye cuatro repuestos, tres categorías, un cliente, un proveedor, un vendedor y existencias ingresadas mediante una compra. Los valores son ficticios.

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
- La cuenta necesita consultar/insertar/actualizar los catálogos, consultar transacciones e historial, registrar compras, ejecutar el procedimiento de venta y permisos suficientes para los triggers según la configuración de SQL Server.

## 4. Abrir el prototipo

Desde la carpeta **ParteProgramada**, ejecute:

```powershell
& 'C:\php\php.exe' -S 127.0.0.1:8080 -t .
```

- Aplicación: http://127.0.0.1:8080/taller_repuestos/
- Vista previa: http://127.0.0.1:8080/taller_repuestos/index.php?preview=1

La vista previa muestra únicamente el resumen ilustrativo. El resto de módulos exige la conexión real: no simula guardar ventas ni modifica archivos para sustituir SQL Server.

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
- Se muestran hasta 100 resultados por búsqueda y las últimas 100 operaciones/movimientos. No hay paginación en esta versión.
- Los importes se muestran sin símbolo: el modelo no define una moneda, impuestos ni descuentos.
- Compras quedan atribuidas a `sistema` en el historial por diseño del SQL original. Ventas quedan atribuidas al vendedor seleccionado.
- La tabla de auditoría, respaldos y extracción DW del SQL original no tienen módulo administrativo en esta versión. El historial visible es el inventario, no una auditoría completa.
- No modificar existencias desde PHP después de insertar un detalle: los triggers ya hacen esa operación.

## Estructura

```text
taller_repuestos/
  index.php               Entrada y navegación completa/parcial HTMX
  config.example.php      Plantilla de conexión, sin credenciales reales
  src/Database.php        Conexión y consultas
  src/Actions.php         Validación y operaciones transaccionales
  src/Views.php           Pantallas y formularios
  assets/app.css          Diseño responsivo
  assets/app.js           Estimación de totales y navegación
  sql/datos_prueba.sql     Datos ficticios sin borrar tablas
  tests/validaciones.php  Pruebas locales sin base de datos
```

## Comprobaciones

```powershell
Get-ChildItem '.\taller_repuestos' -Filter *.php -Recurse | ForEach-Object { & 'C:\php\php.exe' -l $_.FullName }
& 'C:\php\php.exe' '.\taller_repuestos\tests\validaciones.php'
& 'C:\php\php.exe' '.\taller_repuestos\tests\pantallas.php'
& 'C:\php\php.exe' '.\taller_repuestos\tests\catalogos.php'
node --check '.\taller_repuestos\assets\app.js'
```

Para una prueba integral hace falta la instancia real: comprobar compras, ventas, rechazos por stock insuficiente y movimientos con el escenario anterior. Si falla una consulta, revise la salida del servidor PHP; los errores internos no se muestran al usuario.

`tests/catalogos.php` requiere la conexión SQL Server configurada. Prueba altas y ediciones, códigos y cédulas duplicados, categorías inválidas y validaciones de campos. Crea sus propios registros dentro de una transacción y los revierte al terminar, sin conservar datos de prueba ni cambiar las existencias actuales. Las identidades de SQL Server pueden avanzar aunque la transacción se revierta; los saltos en los ID son normales.

Los errores de restricciones de SQL Server se interpretan por su código nativo (`PDOException::errorInfo[1]`), no buscando números en el texto del mensaje. Así, un código duplicado muestra «Ya existe un repuesto con ese código de barras», en lugar de un error genérico de conexión.

También se incluye una prueba integral repetible que utiliza HTTP y consulta los resultados con `sqlcmd`:

```powershell
python '.\taller_repuestos\tests\integracion.py' --confirmar-escritura
```

**Esta prueba escribe en la base:** cada ejecución agrega una compra de 5 filtros y una venta de 3, por lo que las existencias aumentan en 2. Usa los registros DEMO, requiere el servidor PHP en el puerto 8080 y no elimina información. Las ventas rechazadas no deben agregar encabezados, detalles ni movimientos.
