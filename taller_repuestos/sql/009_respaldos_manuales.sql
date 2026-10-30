-- No elimina archivos ni modifica los datos comerciales.
USE TallerRepuestosDB;
GO
IF COL_LENGTH('dbo.RP16_RESPALDOS','origen') IS NULL
 ALTER TABLE dbo.RP16_RESPALDOS ADD origen varchar(12) NOT NULL CONSTRAINT DF_RP16_Origen DEFAULT 'AUTOMATICO';
IF COL_LENGTH('dbo.RP17_CONFIG_RESPALDOS','intervalo_manual_minutos') IS NULL
 ALTER TABLE dbo.RP17_CONFIG_RESPALDOS ADD intervalo_manual_minutos int NOT NULL DEFAULT 5 CHECK(intervalo_manual_minutos BETWEEN 1 AND 1440);
IF COL_LENGTH('dbo.RP17_CONFIG_RESPALDOS','intervalo_completo_horas') IS NULL
 ALTER TABLE dbo.RP17_CONFIG_RESPALDOS ADD intervalo_completo_horas int NOT NULL DEFAULT 24 CHECK(intervalo_completo_horas BETWEEN 1 AND 168);
IF COL_LENGTH('dbo.RP17_CONFIG_RESPALDOS','intervalo_registro_minutos') IS NULL
 ALTER TABLE dbo.RP17_CONFIG_RESPALDOS ADD intervalo_registro_minutos int NOT NULL DEFAULT 30 CHECK(intervalo_registro_minutos BETWEEN 1 AND 1440);
GO
CREATE OR ALTER PROCEDURE dbo.sp_RP_RespaldoAutomatico @forzar_completo bit=0, @manual bit=0
AS
BEGIN
 SET NOCOUNT ON;
 IF @@TRANCOUNT>0 THROW 50023,'El respaldo debe ejecutarse fuera de una operacion de datos.',1;
 DECLARE @lock int,@id bigint,@tipo varchar(10),@archivo nvarchar(512),@ruta nvarchar(260),@manualMin int,@horas int,@registroMin int;
 EXEC @lock=sys.sp_getapplock @Resource='Repuesto.Respaldos',@LockMode='Exclusive',@LockOwner='Session',@LockTimeout=0;
 IF @lock<0 THROW 50021,'Ya hay un respaldo en proceso. Consulte el historial antes de reintentar.',1;
 BEGIN TRY
  SELECT @manualMin=intervalo_manual_minutos,@horas=intervalo_completo_horas,@registroMin=intervalo_registro_minutos FROM dbo.RP17_CONFIG_RESPALDOS WHERE id=1;
  IF @manualMin IS NULL THROW 50023,'Falta la configuracion de respaldos.',1;
  IF @manual=1 AND EXISTS(SELECT 1 FROM dbo.RP16_RESPALDOS WHERE origen='MANUAL' AND inicio>DATEADD(minute,-@manualMin,SYSDATETIME()))
   THROW 50022,'Espere el intervalo entre solicitudes manuales indicado en pantalla.',1;
  -- El bloqueo libre demuestra que ninguna ejecucion de este procedimiento sigue activa.
  UPDATE dbo.RP16_RESPALDOS SET estado='ERROR',fin=SYSDATETIME(),detalle=N'Ejecución interrumpida; comprobar el archivo por separado antes de utilizarlo.' WHERE estado='EN_CURSO';
  SET @tipo=CASE WHEN @manual=1 OR @forzar_completo=1 OR NOT EXISTS(SELECT 1 FROM dbo.RP16_RESPALDOS WHERE tipo='COMPLETO' AND origen='AUTOMATICO' AND estado='VERIFICADO' AND fin>DATEADD(hour,-@horas,SYSDATETIME())) THEN 'COMPLETO' ELSE 'REGISTRO' END;
  IF (SELECT recovery_model_desc FROM sys.databases WHERE name=DB_NAME())='SIMPLE' SET @tipo='COMPLETO';
  IF @manual=0 AND @forzar_completo=0 AND @tipo='REGISTRO' AND EXISTS(SELECT 1 FROM dbo.RP16_RESPALDOS WHERE origen='AUTOMATICO' AND estado='VERIFICADO' AND DATEDIFF(minute,inicio,SYSDATETIME())<@registroMin)
  BEGIN
   EXEC sys.sp_releaseapplock @Resource='Repuesto.Respaldos',@LockOwner='Session';
   RETURN;
  END;
  SET @ruta=CONVERT(nvarchar(260),SERVERPROPERTY('InstanceDefaultBackupPath'));
  IF @ruta IS NULL THROW 50020,'No se encontro la carpeta de respaldos de la instancia.',1;
  IF RIGHT(@ruta,1) NOT IN ('\','/') SET @ruta+='\';
  SET @archivo=@ruta+'Repuesto_'+CASE WHEN @manual=1 THEN 'MANUAL_' ELSE 'AUTO_' END+@tipo+'_'+CONVERT(char(8),GETDATE(),112)+'_'+CONVERT(nvarchar(36),NEWID())+CASE WHEN @tipo='COMPLETO' THEN '.bak' ELSE '.trn' END;
  INSERT dbo.RP16_RESPALDOS(tipo,estado,archivo,origen) VALUES(@tipo,'EN_CURSO',@archivo,CASE WHEN @manual=1 THEN 'MANUAL' ELSE 'AUTOMATICO' END);
  SET @id=SCOPE_IDENTITY();
  IF @manual=1 BACKUP DATABASE TallerRepuestosDB TO DISK=@archivo WITH COPY_ONLY,CHECKSUM,COMPRESSION;
  ELSE IF @tipo='COMPLETO' BACKUP DATABASE TallerRepuestosDB TO DISK=@archivo WITH CHECKSUM,COMPRESSION;
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
