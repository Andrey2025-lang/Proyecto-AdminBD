USE TallerRepuestosDB;
GO
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_PADDING ON;
SET ANSI_WARNINGS ON;
SET ARITHABORT ON;
SET CONCAT_NULL_YIELDS_NULL ON;
SET NUMERIC_ROUNDABORT OFF;
GO
IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.RP08_DETALLE_VENTAS') AND name='IX_DetVenta_Repuesto')
 CREATE INDEX IX_DetVenta_Repuesto ON dbo.RP08_DETALLE_VENTAS(id_repuesto,id_venta) INCLUDE(cantidad,precio_unitario,subtotal);
IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.RP10_DETALLE_COMPRAS') AND name='IX_DetCompra_Repuesto')
 CREATE INDEX IX_DetCompra_Repuesto ON dbo.RP10_DETALLE_COMPRAS(id_repuesto,id_compra) INCLUDE(cantidad,precio_unitario,subtotal);
IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.RP07_VENTAS') AND name='IX_Ventas_Cliente_Fecha')
 CREATE INDEX IX_Ventas_Cliente_Fecha ON dbo.RP07_VENTAS(id_cliente,fecha,id_venta) INCLUDE(total,id_vendedor,estado);
GO
