-- Migración incremental: índices para listados y fechas fieles de movimientos.
USE TallerRepuestosDB;
GO
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_PADDING ON;
SET ANSI_WARNINGS ON;
SET ARITHABORT ON;
SET CONCAT_NULL_YIELDS_NULL ON;
SET NUMERIC_ROUNDABORT OFF;
SET XACT_ABORT ON;
GO
CREATE OR ALTER TRIGGER dbo.trg_ActualizarStockVenta ON dbo.RP08_DETALLE_VENTAS AFTER INSERT AS
BEGIN
    SET NOCOUNT ON;
    IF EXISTS (
        SELECT 1 FROM (SELECT id_repuesto,SUM(cantidad) cantidad FROM inserted GROUP BY id_repuesto) I
        JOIN dbo.RP03_REPUESTOS R WITH (UPDLOCK,HOLDLOCK) ON R.id_repuesto=I.id_repuesto
        WHERE R.stock_actual<I.cantidad
    ) THROW 50001,'Existencias insuficientes en inventario.',1;
    UPDATE R SET stock_actual=R.stock_actual-I.cantidad FROM dbo.RP03_REPUESTOS R
    JOIN (SELECT id_repuesto,SUM(cantidad) cantidad FROM inserted GROUP BY id_repuesto) I ON I.id_repuesto=R.id_repuesto;
    INSERT INTO dbo.RP11_INVENTARIO_HISTORIAL(id_repuesto,usuario_id,tipo_movimiento,cantidad,fecha,motivo)
    SELECT I.id_repuesto,V.id_vendedor,'SALIDA_VENTA',I.cantidad,V.fecha,'Descuento automatico por venta'
    FROM inserted I JOIN dbo.RP07_VENTAS V ON V.id_venta=I.id_venta;
END;
GO
CREATE OR ALTER TRIGGER dbo.trg_ActualizarStockCompra ON dbo.RP10_DETALLE_COMPRAS AFTER INSERT AS
BEGIN
    SET NOCOUNT ON;
    UPDATE R SET stock_actual=R.stock_actual+I.cantidad FROM dbo.RP03_REPUESTOS R
    JOIN (SELECT id_repuesto,SUM(cantidad) cantidad FROM inserted GROUP BY id_repuesto) I ON I.id_repuesto=R.id_repuesto;
    DECLARE @usuario INT=(SELECT usuario_id FROM dbo.RP13_USUARIOS WHERE nombre_usuario='sistema');
    IF @usuario IS NULL THROW 50002,'Debe existir el usuario sistema para registrar compras.',1;
    INSERT INTO dbo.RP11_INVENTARIO_HISTORIAL(id_repuesto,usuario_id,tipo_movimiento,cantidad,fecha,motivo)
    SELECT I.id_repuesto,@usuario,'ENTRADA_COMPRA',I.cantidad,C.fecha,'Ingreso automatico por compra'
    FROM inserted I JOIN dbo.RP09_COMPRAS C ON C.id_compra=I.id_compra;
END;
GO
BEGIN TRANSACTION;
IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.RP03_REPUESTOS') AND name='IX_Repuestos_Nombre_Id')
    CREATE INDEX IX_Repuestos_Nombre_Id ON dbo.RP03_REPUESTOS(nombre,id_repuesto) INCLUDE(codigo_barras,id_categoria,precio_compra,precio_venta,stock_actual,stock_minimo,estado);
IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.RP03_REPUESTOS') AND name='IX_Repuestos_Categoria_Estado')
    CREATE INDEX IX_Repuestos_Categoria_Estado ON dbo.RP03_REPUESTOS(id_categoria,estado,nombre,id_repuesto) INCLUDE(codigo_barras,stock_actual,stock_minimo,precio_venta);
IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.RP05_CLIENTES') AND name='IX_Clientes_Nombre_Id')
    CREATE INDEX IX_Clientes_Nombre_Id ON dbo.RP05_CLIENTES(nombre,id_cliente) INCLUDE(cedula,telefono,email);
IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.RP02_PROVEEDORES') AND name='IX_Proveedores_Nombre_Id')
    CREATE INDEX IX_Proveedores_Nombre_Id ON dbo.RP02_PROVEEDORES(nombre_empresa,id_proveedor) INCLUDE(contacto,telefono,email);
IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.RP07_VENTAS') AND name='IX_Ventas_Fecha_Id')
    CREATE INDEX IX_Ventas_Fecha_Id ON dbo.RP07_VENTAS(fecha DESC,id_venta DESC) INCLUDE(id_cliente,id_vendedor,total,estado);
IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.RP09_COMPRAS') AND name='IX_Compras_Fecha_Id')
    CREATE INDEX IX_Compras_Fecha_Id ON dbo.RP09_COMPRAS(fecha DESC,id_compra DESC) INCLUDE(id_proveedor,total,estado);
IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.RP11_INVENTARIO_HISTORIAL') AND name='IX_Historial_Fecha_Id')
    CREATE INDEX IX_Historial_Fecha_Id ON dbo.RP11_INVENTARIO_HISTORIAL(fecha DESC,id_historial DESC) INCLUDE(id_repuesto,usuario_id,tipo_movimiento,cantidad,motivo);
IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.RP11_INVENTARIO_HISTORIAL') AND name='IX_Historial_Repuesto_Fecha')
    CREATE INDEX IX_Historial_Repuesto_Fecha ON dbo.RP11_INVENTARIO_HISTORIAL(id_repuesto,fecha,id_historial) INCLUDE(tipo_movimiento,cantidad,usuario_id);
COMMIT;
GO
