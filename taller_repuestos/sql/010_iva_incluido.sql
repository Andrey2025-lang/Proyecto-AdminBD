-- Solo operaciones nuevas: los importes historicos permanecen intactos.
USE TallerRepuestosDB;
GO
SET XACT_ABORT ON;
BEGIN TRANSACTION;
IF OBJECT_ID('dbo.RP20_TARIFAS_IVA','U') IS NULL
 CREATE TABLE dbo.RP20_TARIFAS_IVA(
  id_tarifa int PRIMARY KEY,nombre nvarchar(80) NOT NULL,
  porcentaje decimal(5,2) NOT NULL CHECK(porcentaje BETWEEN 0 AND 100),activo bit NOT NULL DEFAULT 1
 );
IF NOT EXISTS(SELECT 1 FROM dbo.RP20_TARIFAS_IVA WHERE id_tarifa=1)
 INSERT dbo.RP20_TARIFAS_IVA(id_tarifa,nombre,porcentaje) VALUES(1,N'IVA general',13);
IF OBJECT_ID('dbo.RP21_CONFIG_FISCAL','U') IS NULL
 CREATE TABLE dbo.RP21_CONFIG_FISCAL(id int PRIMARY KEY CHECK(id=1),tarifa_venta int NOT NULL REFERENCES dbo.RP20_TARIFAS_IVA(id_tarifa));
IF NOT EXISTS(SELECT 1 FROM dbo.RP21_CONFIG_FISCAL WHERE id=1)
 INSERT dbo.RP21_CONFIG_FISCAL VALUES(1,1);
IF COL_LENGTH('dbo.RP07_VENTAS','base_iva') IS NULL
 ALTER TABLE dbo.RP07_VENTAS ADD base_iva decimal(10,2) NULL,monto_iva decimal(10,2) NULL;
IF COL_LENGTH('dbo.RP09_COMPRAS','base_iva') IS NULL
 ALTER TABLE dbo.RP09_COMPRAS ADD base_iva decimal(10,2) NULL,monto_iva decimal(10,2) NULL;
IF COL_LENGTH('dbo.RP08_DETALLE_VENTAS','tarifa_iva') IS NULL
 ALTER TABLE dbo.RP08_DETALLE_VENTAS ADD tarifa_iva decimal(5,2) NULL,base_iva decimal(10,2) NULL,monto_iva decimal(10,2) NULL,
 CONSTRAINT CK_RP08_IVA CHECK((tarifa_iva IS NULL AND base_iva IS NULL AND monto_iva IS NULL) OR (tarifa_iva IS NOT NULL AND base_iva IS NOT NULL AND monto_iva IS NOT NULL AND tarifa_iva BETWEEN 0 AND 100 AND base_iva>=0 AND monto_iva>=0 AND base_iva+monto_iva=subtotal));
IF COL_LENGTH('dbo.RP10_DETALLE_COMPRAS','tarifa_iva') IS NULL
 ALTER TABLE dbo.RP10_DETALLE_COMPRAS ADD tarifa_iva decimal(5,2) NULL,base_iva decimal(10,2) NULL,monto_iva decimal(10,2) NULL,
 CONSTRAINT CK_RP10_IVA CHECK((tarifa_iva IS NULL AND base_iva IS NULL AND monto_iva IS NULL) OR (tarifa_iva IS NOT NULL AND base_iva IS NOT NULL AND monto_iva IS NOT NULL AND tarifa_iva BETWEEN 0 AND 100 AND base_iva>=0 AND monto_iva>=0 AND base_iva+monto_iva=subtotal));
COMMIT;
GO
IF NOT EXISTS(SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID('dbo.RP07_VENTAS') AND name='CK_RP07_IVA')
 ALTER TABLE dbo.RP07_VENTAS ADD CONSTRAINT CK_RP07_IVA CHECK((base_iva IS NULL AND monto_iva IS NULL) OR (base_iva IS NOT NULL AND monto_iva IS NOT NULL AND base_iva>=0 AND monto_iva>=0 AND base_iva+monto_iva=total));
IF NOT EXISTS(SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID('dbo.RP09_COMPRAS') AND name='CK_RP09_IVA')
 ALTER TABLE dbo.RP09_COMPRAS ADD CONSTRAINT CK_RP09_IVA CHECK((base_iva IS NULL AND monto_iva IS NULL) OR (base_iva IS NOT NULL AND monto_iva IS NOT NULL AND base_iva>=0 AND monto_iva>=0 AND base_iva+monto_iva=total));
GO
CREATE OR ALTER PROCEDURE dbo.sp_RP_RegistrarIVA @tipo varchar(10),@id int,@id_tarifa int
AS
BEGIN
 SET NOCOUNT ON;
 IF @@TRANCOUNT=0 THROW 50030,'El desglose debe guardarse dentro de la operacion.',1;
 DECLARE @tarifa decimal(5,2);
 SELECT @tarifa=porcentaje FROM dbo.RP20_TARIFAS_IVA WITH(HOLDLOCK) WHERE id_tarifa=@id_tarifa AND activo=1;
 IF @tarifa IS NULL THROW 50030,'La tarifa de IVA no esta disponible.',1;
 IF @tipo='venta'
 BEGIN
  IF NOT EXISTS(SELECT 1 FROM dbo.RP07_VENTAS WITH(UPDLOCK,HOLDLOCK) WHERE id_venta=@id AND base_iva IS NULL)
   OR EXISTS(SELECT 1 FROM dbo.RP08_DETALLE_VENTAS WHERE id_venta=@id AND tarifa_iva IS NOT NULL)
   THROW 50030,'El desglose ya fue registrado o la operacion no existe.',1;
  UPDATE dbo.RP08_DETALLE_VENTAS SET tarifa_iva=@tarifa,base_iva=ROUND(subtotal/(1+@tarifa/100),2),monto_iva=subtotal-ROUND(subtotal/(1+@tarifa/100),2) WHERE id_venta=@id;
  UPDATE V SET base_iva=D.base,monto_iva=D.iva FROM dbo.RP07_VENTAS V CROSS APPLY(SELECT SUM(base_iva) base,SUM(monto_iva) iva FROM dbo.RP08_DETALLE_VENTAS WHERE id_venta=@id) D WHERE V.id_venta=@id;
  IF NOT EXISTS(SELECT 1 FROM dbo.RP07_VENTAS WHERE id_venta=@id AND base_iva+monto_iva=total) THROW 50030,'El desglose no coincide con el total.',1;
 END
 ELSE IF @tipo='compra'
 BEGIN
  IF NOT EXISTS(SELECT 1 FROM dbo.RP09_COMPRAS WITH(UPDLOCK,HOLDLOCK) WHERE id_compra=@id AND base_iva IS NULL)
   OR EXISTS(SELECT 1 FROM dbo.RP10_DETALLE_COMPRAS WHERE id_compra=@id AND tarifa_iva IS NOT NULL)
   THROW 50030,'El desglose ya fue registrado o la operacion no existe.',1;
  UPDATE dbo.RP10_DETALLE_COMPRAS SET tarifa_iva=@tarifa,base_iva=ROUND(subtotal/(1+@tarifa/100),2),monto_iva=subtotal-ROUND(subtotal/(1+@tarifa/100),2) WHERE id_compra=@id;
  UPDATE C SET base_iva=D.base,monto_iva=D.iva FROM dbo.RP09_COMPRAS C CROSS APPLY(SELECT SUM(base_iva) base,SUM(monto_iva) iva FROM dbo.RP10_DETALLE_COMPRAS WHERE id_compra=@id) D WHERE C.id_compra=@id;
  IF NOT EXISTS(SELECT 1 FROM dbo.RP09_COMPRAS WHERE id_compra=@id AND base_iva+monto_iva=total) THROW 50030,'El desglose no coincide con el total.',1;
 END
 ELSE THROW 50030,'Tipo de operacion no valido.',1;
END;
GO
-- Amplia el procedimiento existente, conservando su validacion y transaccion.
DECLARE @definition nvarchar(max)=OBJECT_DEFINITION(OBJECT_ID('dbo.sp_ProcesarVentaMultiple'));
IF @definition IS NULL THROW 50030,'Primero aplique la migracion 004.',1;
IF CHARINDEX('sp_RP_RegistrarIVA',@definition)=0
BEGIN
 IF CHARINDEX('COMMIT TRANSACTION;',@definition)=0 THROW 50030,'Revise la version del procedimiento de ventas antes de migrar.',1;
 SET @definition=N'ALTER '+SUBSTRING(@definition,CHARINDEX('PROCEDURE',UPPER(@definition)),LEN(@definition));
 SET @definition=REPLACE(@definition,'COMMIT TRANSACTION;',N'DECLARE @tarifaIVA int=(SELECT tarifa_venta FROM dbo.RP21_CONFIG_FISCAL WITH(HOLDLOCK) WHERE id=1);
         EXEC dbo.sp_RP_RegistrarIVA @tipo=''venta'',@id=@venta,@id_tarifa=@tarifaIVA;
         COMMIT TRANSACTION;');
 EXEC sys.sp_executesql @definition;
END;
GO
