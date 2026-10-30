-- Incremental: no recrea la base ni elimina respaldos existentes.
USE TallerRepuestosDB;
GO
IF OBJECT_ID('dbo.RP16_RESPALDOS','U') IS NULL
CREATE TABLE dbo.RP16_RESPALDOS (
 id_respaldo bigint IDENTITY PRIMARY KEY,
 inicio datetime2 NOT NULL DEFAULT SYSDATETIME(),fin datetime2 NULL,
 tipo varchar(10) NOT NULL,estado varchar(20) NOT NULL,
 archivo nvarchar(512) NOT NULL,detalle nvarchar(2000) NULL
);
IF OBJECT_ID('dbo.RP17_CONFIG_RESPALDOS','U') IS NULL
CREATE TABLE dbo.RP17_CONFIG_RESPALDOS (
 id int PRIMARY KEY CHECK(id=1),programado bit NOT NULL DEFAULT 0,
 tarea nvarchar(100) NULL,actualizado datetime2 NOT NULL DEFAULT SYSDATETIME()
);
IF NOT EXISTS(SELECT 1 FROM dbo.RP17_CONFIG_RESPALDOS WHERE id=1)
 INSERT dbo.RP17_CONFIG_RESPALDOS(id,programado) VALUES(1,0);
GO
CREATE OR ALTER PROCEDURE dbo.sp_RP_RespaldoAutomatico @forzar_completo bit=0
AS
BEGIN
 SET NOCOUNT ON;
 DECLARE @lock int;
 EXEC @lock=sys.sp_getapplock @Resource='Repuesto.Respaldos',@LockMode='Exclusive',@LockOwner='Session',@LockTimeout=0;
 IF @lock<0 RETURN;
 DECLARE @id bigint,@tipo varchar(10),@archivo nvarchar(512),@ruta nvarchar(260);
 BEGIN TRY
  SET @ruta=CONVERT(nvarchar(260),SERVERPROPERTY('InstanceDefaultBackupPath'));
  IF @ruta IS NULL THROW 50020,'No se encontro la carpeta de respaldos de la instancia.',1;
  IF RIGHT(@ruta,1) NOT IN ('\','/') SET @ruta+='\';
  SET @tipo=CASE WHEN @forzar_completo=1 OR NOT EXISTS(SELECT 1 FROM dbo.RP16_RESPALDOS WHERE tipo='COMPLETO' AND estado='VERIFICADO' AND fin>DATEADD(hour,-24,SYSDATETIME())) THEN 'COMPLETO' ELSE 'REGISTRO' END;
  -- Si la base cambia a SIMPLE, se usan completos y no se intenta BACKUP LOG.
  IF (SELECT recovery_model_desc FROM sys.databases WHERE name=DB_NAME())='SIMPLE' SET @tipo='COMPLETO';
  SET @archivo=@ruta+'Repuesto_AUTO_'+@tipo+'_'+CONVERT(char(8),GETDATE(),112)+'_'+REPLACE(CONVERT(char(8),GETDATE(),108),':','')+'_'+CONVERT(nvarchar(36),NEWID())+CASE WHEN @tipo='COMPLETO' THEN '.bak' ELSE '.trn' END;
  INSERT dbo.RP16_RESPALDOS(tipo,estado,archivo) VALUES(@tipo,'EN_CURSO',@archivo);
  SET @id=SCOPE_IDENTITY();
  IF @tipo='COMPLETO' BACKUP DATABASE TallerRepuestosDB TO DISK=@archivo WITH CHECKSUM,COMPRESSION;
  ELSE BACKUP LOG TallerRepuestosDB TO DISK=@archivo WITH CHECKSUM,COMPRESSION;
  RESTORE VERIFYONLY FROM DISK=@archivo WITH CHECKSUM;
  UPDATE dbo.RP16_RESPALDOS SET estado='VERIFICADO',fin=SYSDATETIME(),detalle=N'Archivo comprobado. La prueba de restauración se realiza por separado.' WHERE id_respaldo=@id;
  EXEC sys.sp_releaseapplock @Resource='Repuesto.Respaldos',@LockOwner='Session';
 END TRY
 BEGIN CATCH
  IF @id IS NOT NULL UPDATE dbo.RP16_RESPALDOS SET estado='ERROR',fin=SYSDATETIME(),detalle=LEFT(ERROR_MESSAGE(),2000) WHERE id_respaldo=@id;
  EXEC sys.sp_releaseapplock @Resource='Repuesto.Respaldos',@LockOwner='Session';
  THROW;
 END CATCH;
END;
GO
