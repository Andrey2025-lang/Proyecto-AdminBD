"""Prueba real: crea como máximo una copia manual; nunca restaura ni elimina archivos.
Requiere migración 009, servidor HTTP y sqlcmd con autenticación de Windows.
"""
import http.cookiejar
import json
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request

URL = 'http://127.0.0.1:8080/taller_repuestos/index.php?page=inicio'

def sql(query):
    result = subprocess.check_output(['sqlcmd', '-S', 'localhost', '-E', '-C', '-b', '-d', 'TallerRepuestosDB', '-h', '-1', '-w', '65535', '-Q', 'SET NOCOUNT ON; '+query])
    return json.loads(result.decode().strip())

def state():
    return sql("SELECT (SELECT COUNT(*) FROM RP16_RESPALDOS) copias,(SELECT COUNT(*) FROM RP07_VENTAS) ventas,(SELECT COUNT(*) FROM RP09_COMPRAS) compras,(SELECT SUM(CONVERT(bigint,stock_actual)) FROM RP03_REPUESTOS) unidades FOR JSON PATH,WITHOUT_ARRAY_WRAPPER")

client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
html = client.open(URL).read().decode('utf-8')
form = re.search(r'<form[^>]*data-backup-form[^>]*>(.*?)</form>', html, re.S).group(1)
token = re.search(r'name="csrf" value="([^"]+)"', form).group(1)
assert 'Crear respaldo' in form

def post(csrf):
    request = urllib.request.Request(URL, data=urllib.parse.urlencode({'action':'backup','csrf':csrf}).encode())
    try:
        response = client.open(request)
    except urllib.error.HTTPError as error:
        response = error
    return response.code, response.read().decode('utf-8')

before = state()
code, body = post('invalido')
assert code == 422 and state() == before
print('OK: solicitud sin protección válida rechazada sin generar copias')

code, body = post(token)
assert code in (200, 422), (code, body[:200])
if code == 200:
    assert 'Respaldo creado y verificado' in body
    assert state()['copias'] == before['copias']+1
else:
    assert 'intervalo entre solicitudes' in body
    print('OK: copia reciente conservada; se respeta el intervalo de la base')

after = state()
assert all(after[key] == before[key] for key in ('ventas','compras','unidades'))
code, body = post(token)
assert code == 422 and 'intervalo entre solicitudes' in body
assert state() == after
print('OK: clic repetido bloqueado por la base y datos comerciales sin cambios')

latest = sql("SELECT TOP(1) R.estado,R.archivo, B.is_copy_only FROM RP16_RESPALDOS R JOIN msdb.dbo.backupmediafamily F ON F.physical_device_name=R.archivo JOIN msdb.dbo.backupset B ON B.media_set_id=F.media_set_id WHERE R.origen='MANUAL' ORDER BY R.id_respaldo DESC FOR JSON PATH,WITHOUT_ARRAY_WRAPPER")
assert latest['estado'] == 'VERIFICADO' and latest['is_copy_only']
assert latest['archivo'] not in body
print('OK: copia manual verificada, COPY_ONLY y ruta interna no expuesta')
