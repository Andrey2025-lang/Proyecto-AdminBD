-- Restaura el último completo en una base temporal nueva y elimina solo esa base.
-- Nunca reemplaza TallerRepuestosDB. Requiere permisos administrativos.
USE master;
GO
SET NOCOUNT ON;
DECLARE @archivo nvarchar(512)=(SELECT TOP(1) archivo FROM TallerRepuestosDB.dbo.RP16_RESPALDOS WHERE tipo='COMPLETO' AND estado='VERIFICADO' ORDER BY id_respaldo DESC);
DECLARE @base bigint=(SELECT TOP(1) id_respaldo FROM TallerRepuestosDB.dbo.RP16_RESPALDOS WHERE tipo='COMPLETO' AND estado='VERIFICADO' ORDER BY id_respaldo DESC);
IF @archivo IS NULL THROW 50021,'No hay un respaldo completo verificado.',1;
DECLARE @nombre sysname='RepuestoRestoreTest_'+REPLACE(CONVERT(varchar(36),NEWID()),'-','');
DECLARE @data nvarchar(260)=CONVERT(nvarchar(260),SERVERPROPERTY('InstanceDefaultDataPath'))+@nombre+'.mdf';
DECLARE @log nvarchar(260)=CONVERT(nvarchar(260),SERVERPROPERTY('InstanceDefaultLogPath'))+@nombre+'.ldf';
DECLARE @ld sysname=(SELECT name FROM sys.master_files WHERE database_id=DB_ID('TallerRepuestosDB') AND file_id=1);
DECLARE @ll sysname=(SELECT name FROM sys.master_files WHERE database_id=DB_ID('TallerRepuestosDB') AND type=1);
DECLARE @sql nvarchar(max),@created bit=0;
BEGIN TRY
 SET @sql=N'RESTORE DATABASE '+QUOTENAME(@nombre)+N' FROM DISK=N'''+REPLACE(@archivo,'''','''''')+N''' WITH MOVE N'''+REPLACE(@ld,'''','''''')+N''' TO N'''+REPLACE(@data,'''','''''')+N''', MOVE N'''+REPLACE(@ll,'''','''''')+N''' TO N'''+REPLACE(@log,'''','''''')+N''', CHECKSUM,NORECOVERY;';
 EXEC(@sql);SET @created=1;
 DECLARE registros CURSOR LOCAL FAST_FORWARD FOR SELECT archivo FROM TallerRepuestosDB.dbo.RP16_RESPALDOS WHERE id_respaldo>@base AND tipo='REGISTRO' AND estado='VERIFICADO' ORDER BY id_respaldo;
 OPEN registros; FETCH NEXT FROM registros INTO @archivo;
 WHILE @@FETCH_STATUS=0
 BEGIN
  SET @sql=N'RESTORE LOG '+QUOTENAME(@nombre)+N' FROM DISK=N'''+REPLACE(@archivo,'''','''''')+N''' WITH CHECKSUM,NORECOVERY;';EXEC(@sql);
  FETCH NEXT FROM registros INTO @archivo;
 END;
 CLOSE registros;DEALLOCATE registros;
 SET @sql=N'RESTORE DATABASE '+QUOTENAME(@nombre)+N' WITH RECOVERY;';EXEC(@sql);
 SET @sql=N'DBCC CHECKDB ('+QUOTENAME(@nombre)+N') WITH NO_INFOMSGS,ALL_ERRORMSGS; SELECT COUNT(*) ventas_restauradas FROM '+QUOTENAME(@nombre)+N'.dbo.RP07_VENTAS;';
 EXEC(@sql);
 SET @sql=N'DROP DATABASE '+QUOTENAME(@nombre);EXEC(@sql);SET @created=0;
 PRINT 'OK: respaldo restaurado en una base temporal, integridad comprobada y base temporal eliminada. La base original no se modifico.';
END TRY
BEGIN CATCH
 IF @created=1 BEGIN SET @sql=N'DROP DATABASE '+QUOTENAME(@nombre);EXEC(@sql);END;
 THROW;
END CATCH;
GO
