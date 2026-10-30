-- Relacion muchos-a-muchos. No modifica compras ni detalles anteriores.
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
BEGIN TRANSACTION;
IF OBJECT_ID('dbo.RP18_ENTIDADES_CARGA','U') IS NULL
 CREATE TABLE dbo.RP18_ENTIDADES_CARGA(lote varchar(100) NOT NULL,tipo varchar(10) NOT NULL,id int NOT NULL,PRIMARY KEY(lote,tipo,id));
IF OBJECT_ID('dbo.RP19_PROVEEDOR_REPUESTO','U') IS NULL
CREATE TABLE dbo.RP19_PROVEEDOR_REPUESTO (
 id_proveedor int NOT NULL REFERENCES dbo.RP02_PROVEEDORES(id_proveedor),
 id_repuesto int NOT NULL REFERENCES dbo.RP03_REPUESTOS(id_repuesto),
 estado varchar(20) NOT NULL CONSTRAINT CK_RP19_Estado CHECK(estado IN('ACTIVO','INACTIVO','PENDIENTE')),
 costo_referencia decimal(10,2) NULL CONSTRAINT CK_RP19_Costo CHECK(costo_referencia>=0),
 codigo_proveedor varchar(50) NULL,
 origen varchar(30) NOT NULL CONSTRAINT CK_RP19_Origen CHECK(origen IN('HISTORIAL','CARGA_SINTETICA','MANUAL')),
 actualizado datetime2 NOT NULL DEFAULT SYSDATETIME(),
 version rowversion NOT NULL,
 CONSTRAINT PK_RP19 PRIMARY KEY(id_proveedor,id_repuesto)
);
IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.RP19_PROVEEDOR_REPUESTO') AND name='IX_RP19_Repuesto')
 CREATE INDEX IX_RP19_Repuesto ON dbo.RP19_PROVEEDOR_REPUESTO(id_repuesto,id_proveedor) INCLUDE(estado,costo_referencia);
-- La historia demuestra compras pasadas, no una oferta vigente: queda pendiente.
-- Solo las relaciones del lote ficticio planificado se habilitan como datos de prueba.
;WITH Relaciones AS (
 SELECT C.id_proveedor,D.id_repuesto,MAX(CASE WHEN L.id IS NOT NULL THEN 1 ELSE 0 END) sintetica
 FROM dbo.RP09_COMPRAS C JOIN dbo.RP10_DETALLE_COMPRAS D ON D.id_compra=C.id_compra
 LEFT JOIN dbo.RP18_ENTIDADES_CARGA L ON L.tipo='compra' AND L.id=C.id_compra AND L.lote='historia_64_diarias_v2'
 GROUP BY C.id_proveedor,D.id_repuesto
)
INSERT dbo.RP19_PROVEEDOR_REPUESTO(id_proveedor,id_repuesto,estado,costo_referencia,origen)
SELECT R.id_proveedor,R.id_repuesto,CASE WHEN R.sintetica=1 THEN 'ACTIVO' ELSE 'PENDIENTE' END,U.costo,
 CASE WHEN R.sintetica=1 THEN 'CARGA_SINTETICA' ELSE 'HISTORIAL' END
FROM Relaciones R OUTER APPLY(
 SELECT TOP(1) CONVERT(decimal(10,2),SUM(CONVERT(decimal(20,2),D.subtotal))/NULLIF(SUM(CONVERT(decimal(20,2),D.cantidad)),0)) costo
 FROM dbo.RP09_COMPRAS C JOIN dbo.RP10_DETALLE_COMPRAS D ON D.id_compra=C.id_compra
 WHERE C.id_proveedor=R.id_proveedor AND D.id_repuesto=R.id_repuesto AND C.estado='RECIBIDO'
 GROUP BY C.id_compra,C.fecha ORDER BY C.fecha DESC,C.id_compra DESC
) U WHERE NOT EXISTS(SELECT 1 FROM dbo.RP19_PROVEEDOR_REPUESTO E WHERE E.id_proveedor=R.id_proveedor AND E.id_repuesto=R.id_repuesto);
COMMIT;
GO
CREATE OR ALTER TRIGGER dbo.trg_ValidarCatalogoCompra ON dbo.RP10_DETALLE_COMPRAS AFTER INSERT AS
BEGIN
 SET NOCOUNT ON;
 IF EXISTS(
  SELECT 1 FROM inserted I JOIN dbo.RP09_COMPRAS C ON C.id_compra=I.id_compra
  LEFT JOIN dbo.RP19_PROVEEDOR_REPUESTO P WITH(HOLDLOCK) ON P.id_proveedor=C.id_proveedor AND P.id_repuesto=I.id_repuesto
  WHERE P.estado IS NULL OR P.estado<>'ACTIVO'
 ) THROW 50016,'El proveedor no tiene habilitado uno de los repuestos de esta compra.',1;
END;
GO
