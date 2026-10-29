# Proyecto-AdminBD

Prototipo académico para gestionar repuestos de vehículos con **PHP + Bulma + HTMX + SQL Server**.

## Contenido

- `taller_repuestos/`: aplicación con repuestos, categorías, clientes, proveedores, compras, ventas e historial de inventario.
- `TallerRepuestosDB.sql`: estructura original de SQL Server, triggers y procedimientos almacenados.
- `taller_repuestos/sql/datos_prueba.sql`: datos ficticios para preparar una demostración.
- `miniframework_cech/`: ejemplo PHP/MySQL de referencia del profesor; la aplicación nueva utiliza SQL Server, no MySQL.
- `php_bulma_htmx.pdf`: material de referencia de las tecnologías utilizadas.

## Ejecutar en otra computadora

1. Instalar PHP 8.1 o superior con `pdo_sqlsrv`, el controlador ODBC de Microsoft y SQL Server.
2. Crear la base con `TallerRepuestosDB.sql` **solo si no existe**. **El script original borra la base existente: no ejecutarlo sobre datos que se quieran conservar.**
3. Ejecutar `taller_repuestos/sql/datos_prueba.sql` para cargar ejemplos.
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

Vista previa de solo lectura, sin conexión a la base: http://127.0.0.1:8080/taller_repuestos/index.php?preview=1.

Las instrucciones completas, pruebas y limitaciones están en [taller_repuestos/README.md](taller_repuestos/README.md).

## Base de datos y seguridad

Git guarda el código y los scripts, **no la base activa ni sus registros actuales**. Las compras y ventas realizadas localmente no se publican automáticamente. Para entregar una copia exacta de la base, generar y compartir un respaldo `.bak` por separado.

Los archivos de configuración locales, las credenciales y los respaldos están excluidos mediante `.gitignore`. Para el ejemplo MySQL se incluye `conexion.example.php`, sin contraseña.

Este es un prototipo local sin autenticación de usuarios; no debe exponerse a Internet ni presentarse como un sistema listo para producción.
