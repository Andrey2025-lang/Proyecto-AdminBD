param([string]$Server='localhost')
$ErrorActionPreference='Stop'
# Solo CLI. Autenticación de Windows, sin contraseñas ni dependencia del servidor PHP.
& sqlcmd -S $Server -E -C -b -d TallerRepuestosDB -Q 'EXEC dbo.sp_RP_RespaldoAutomatico;' -r 1
if($LASTEXITCODE -ne 0){throw "Falló el respaldo (código $LASTEXITCODE). Revise el historial de Respaldos."}
