-- Sistema Integral de Gestion de Repuestos de Auto
-- SQL Server - Script basado en el anexo tecnico Hito 1 y 2
-- ADVERTENCIA: elimina TallerRepuestosDB si ya existe.
IF DB_ID(N'TallerRepuestosDB') IS NOT NULL
BEGIN
    ALTER DATABASE TallerRepuestosDB SET SINGLE_USER WITH ROLLBACK IMMEDIATE;
    DROP DATABASE TallerRepuestosDB;
END;
GO
CREATE DATABASE TallerRepuestosDB;
GO
USE TallerRepuestosDB;
GO
CREATE TABLE RP01_CATEGORIAS (
 id_categoria INT IDENTITY(1,1) PRIMARY KEY,
 nombre VARCHAR(100) NOT NULL
);
CREATE TABLE RP03_REPUESTOS (
 id_repuesto INT IDENTITY(1,1) PRIMARY KEY,
 codigo_barras VARCHAR(20) NOT NULL UNIQUE,
 nombre VARCHAR(150) NOT NULL,
 id_categoria INT NOT NULL REFERENCES RP01_CATEGORIAS(id_categoria),
 precio_compra DECIMAL(10,2) NOT NULL CHECK(precio_compra >= 0),
 precio_venta DECIMAL(10,2) NOT NULL CHECK(precio_venta >= 0),
 stock_actual INT NOT NULL CHECK(stock_actual >= 0),
 stock_minimo INT NOT NULL CHECK(stock_minimo >= 0),
 estado VARCHAR(20) NOT NULL DEFAULT 'ACTIVO'
);
CREATE TABLE RP05_CLIENTES (
 id_cliente INT IDENTITY(1,1) PRIMARY KEY,
 cedula VARCHAR(20) NOT NULL UNIQUE,
 nombre VARCHAR(100) NOT NULL,
 telefono VARCHAR(20) NOT NULL,
 email VARCHAR(100) NULL
);
CREATE TABLE RP13_USUARIOS (
 usuario_id INT IDENTITY(1,1) PRIMARY KEY,
 nombre_usuario VARCHAR(50) NOT NULL UNIQUE,
 rol VARCHAR(50) NOT NULL,
 estado VARCHAR(20) NOT NULL DEFAULT 'ACTIVO'
);
CREATE TABLE RP07_VENTAS (
 id_venta INT IDENTITY(1,1) PRIMARY KEY,
 id_cliente INT NOT NULL REFERENCES RP05_CLIENTES(id_cliente),
 id_vendedor INT NOT NULL REFERENCES RP13_USUARIOS(usuario_id),
 fecha DATETIME2 NOT NULL DEFAULT GETDATE(),
 total DECIMAL(10,2) NOT NULL DEFAULT 0,
 estado VARCHAR(20) NOT NULL DEFAULT 'COMPLETADA'
);
CREATE TABLE RP08_DETALLE_VENTAS (
 id_detalle_venta INT IDENTITY(1,1) PRIMARY KEY,
 id_venta INT NOT NULL REFERENCES RP07_VENTAS(id_venta),
 id_repuesto INT NOT NULL REFERENCES RP03_REPUESTOS(id_repuesto),
 cantidad INT NOT NULL CHECK(cantidad > 0),
 precio_unitario DECIMAL(10,2) NOT NULL,
 subtotal DECIMAL(10,2) NOT NULL
);
CREATE TABLE RP02_PROVEEDORES (
 id_proveedor INT IDENTITY(1,1) PRIMARY KEY,
 nombre_empresa VARCHAR(150) NOT NULL,
 contacto VARCHAR(100) NOT NULL,
 telefono VARCHAR(20) NOT NULL,
 email VARCHAR(100) NOT NULL
);
CREATE TABLE RP09_COMPRAS (
 id_compra INT IDENTITY(1,1) PRIMARY KEY,
 id_proveedor INT NOT NULL REFERENCES RP02_PROVEEDORES(id_proveedor),
 fecha DATETIME2 NOT NULL DEFAULT GETDATE(),
 estado VARCHAR(20) NOT NULL DEFAULT 'RECIBIDO',
 total DECIMAL(10,2) NOT NULL DEFAULT 0
);
CREATE TABLE RP10_DETALLE_COMPRAS (
 id_detalle_compra INT IDENTITY(1,1) PRIMARY KEY,
 id_compra INT NOT NULL REFERENCES RP09_COMPRAS(id_compra),
 id_repuesto INT NOT NULL REFERENCES RP03_REPUESTOS(id_repuesto),
 cantidad INT NOT NULL CHECK(cantidad > 0),
 precio_unitario DECIMAL(10,2) NOT NULL,
 subtotal DECIMAL(10,2) NOT NULL
);
CREATE TABLE RP11_INVENTARIO_HISTORIAL (
 id_historial INT IDENTITY(1,1) PRIMARY KEY,
 id_repuesto INT NOT NULL REFERENCES RP03_REPUESTOS(id_repuesto),
 usuario_id INT NOT NULL REFERENCES RP13_USUARIOS(usuario_id),
 tipo_movimiento VARCHAR(20) NOT NULL,
 cantidad INT NOT NULL,
 fecha DATETIME2 NOT NULL DEFAULT GETDATE(),
 motivo VARCHAR(255) NOT NULL
);
CREATE TABLE RP12_AUDITORIA (
 id_auditoria INT IDENTITY(1,1) PRIMARY KEY,
 usuario_id INT NOT NULL REFERENCES RP13_USUARIOS(usuario_id),
 tabla_afectada VARCHAR(50) NOT NULL,
 operacion VARCHAR(10) NOT NULL,
 fecha DATETIME2 NOT NULL DEFAULT GETDATE(),
 detalle VARCHAR(MAX) NOT NULL
);
GO
CREATE NONCLUSTERED INDEX IX_Repuestos_CodigoBarras ON RP03_REPUESTOS(codigo_barras);
CREATE NONCLUSTERED INDEX IX_Ventas_Fecha ON RP07_VENTAS(fecha);
CREATE NONCLUSTERED INDEX IX_DetVentas_Venta ON RP08_DETALLE_VENTAS(id_venta);
CREATE NONCLUSTERED INDEX IX_DetCompras_Compra ON RP10_DETALLE_COMPRAS(id_compra);
GO
CREATE TRIGGER trg_ActualizarStockVenta ON RP08_DETALLE_VENTAS AFTER INSERT AS
BEGIN
 SET NOCOUNT ON;
 IF EXISTS (
  SELECT 1 FROM (SELECT id_repuesto, SUM(cantidad) cantidad FROM inserted GROUP BY id_repuesto) I
  JOIN RP03_REPUESTOS R WITH (UPDLOCK, HOLDLOCK) ON R.id_repuesto = I.id_repuesto
  WHERE R.stock_actual < I.cantidad
 ) THROW 50001, 'Existencias insuficientes en inventario.', 1;
 UPDATE R SET stock_actual = R.stock_actual - I.cantidad
 FROM RP03_REPUESTOS R
 JOIN (SELECT id_repuesto, SUM(cantidad) cantidad FROM inserted GROUP BY id_repuesto) I
 ON R.id_repuesto = I.id_repuesto;
 INSERT INTO RP11_INVENTARIO_HISTORIAL(id_repuesto,usuario_id,tipo_movimiento,cantidad,fecha,motivo)
 SELECT I.id_repuesto,V.id_vendedor,'SALIDA_VENTA',I.cantidad,GETDATE(),'Descuento automatico por venta'
 FROM inserted I JOIN RP07_VENTAS V ON I.id_venta=V.id_venta;
END;
GO
CREATE TRIGGER trg_ActualizarStockCompra ON RP10_DETALLE_COMPRAS AFTER INSERT AS
BEGIN
 SET NOCOUNT ON;
 UPDATE R SET stock_actual = R.stock_actual + I.cantidad
 FROM RP03_REPUESTOS R
 JOIN (SELECT id_repuesto, SUM(cantidad) cantidad FROM inserted GROUP BY id_repuesto) I
 ON R.id_repuesto = I.id_repuesto;
 -- El modelo no registra usuario responsable en el encabezado de compras.
 -- Se requiere un usuario administrador con nombre_usuario='sistema' para el kardex.
 DECLARE @usuario_sistema INT = (SELECT usuario_id FROM RP13_USUARIOS WHERE nombre_usuario='sistema');
 IF @usuario_sistema IS NULL THROW 50002, 'Debe existir el usuario sistema para registrar compras.', 1;
 INSERT INTO RP11_INVENTARIO_HISTORIAL(id_repuesto,usuario_id,tipo_movimiento,cantidad,fecha,motivo)
 SELECT I.id_repuesto,@usuario_sistema,'ENTRADA_COMPRA',I.cantidad,GETDATE(),'Ingreso automatico por compra'
 FROM inserted I;
END;
GO
CREATE PROCEDURE sp_ProcesarVentaTransaccional
 @id_cliente INT,@id_vendedor INT,@id_repuesto INT,@cantidad INT,@precio_unitario DECIMAL(10,2)
AS
BEGIN
 SET NOCOUNT ON;
 SET XACT_ABORT ON;
 BEGIN TRY
  BEGIN TRANSACTION;
  IF @cantidad <= 0 OR @precio_unitario < 0 THROW 50003,'Cantidad o precio invalido.',1;
  DECLARE @stock_disponible INT;
  SELECT @stock_disponible=stock_actual FROM RP03_REPUESTOS WITH (UPDLOCK,HOLDLOCK) WHERE id_repuesto=@id_repuesto;
  IF @stock_disponible IS NULL OR @stock_disponible < @cantidad THROW 50004,'Existencias insuficientes.',1;
  DECLARE @monto_total DECIMAL(10,2)=@cantidad*@precio_unitario;
  INSERT INTO RP07_VENTAS(id_cliente,id_vendedor,fecha,total,estado)
  VALUES(@id_cliente,@id_vendedor,GETDATE(),@monto_total,'COMPLETADA');
  DECLARE @id_venta INT=CONVERT(INT,SCOPE_IDENTITY());
  INSERT INTO RP08_DETALLE_VENTAS(id_venta,id_repuesto,cantidad,precio_unitario,subtotal)
  VALUES(@id_venta,@id_repuesto,@cantidad,@precio_unitario,@monto_total);
  COMMIT TRANSACTION;
 END TRY
 BEGIN CATCH
  IF @@TRANCOUNT>0 ROLLBACK TRANSACTION;
  THROW;
 END CATCH;
END;
GO
CREATE PROCEDURE sp_EjecutarRespaldoBD @ruta_destino VARCHAR(255)='C:\SQLBackups\'
AS
BEGIN
 SET NOCOUNT ON;
 DECLARE @archivo VARCHAR(500)=@ruta_destino+'TallerRepuestosDB_FULL_'+CONVERT(VARCHAR(8),GETDATE(),112)+'.bak';
 BACKUP DATABASE TallerRepuestosDB TO DISK=@archivo WITH INIT, NAME='Respaldo Completo TallerRepuestosDB';
END;
GO
CREATE VIEW vw_DW_ExtraccionVentas AS
SELECT V.id_venta,V.fecha,C.nombre AS cliente,U.nombre_usuario AS vendedor,
 R.nombre AS repuesto,DV.cantidad,DV.precio_unitario,DV.subtotal
FROM RP07_VENTAS V
JOIN RP05_CLIENTES C ON V.id_cliente=C.id_cliente
JOIN RP13_USUARIOS U ON V.id_vendedor=U.usuario_id
JOIN RP08_DETALLE_VENTAS DV ON V.id_venta=DV.id_venta
JOIN RP03_REPUESTOS R ON DV.id_repuesto=R.id_repuesto;
GO
-- Usuario tecnico requerido para registrar movimientos de compras.
INSERT INTO RP13_USUARIOS(nombre_usuario,rol,estado) VALUES('sistema','Admin','ACTIVO');
GO
