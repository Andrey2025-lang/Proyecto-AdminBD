"""Pruebas de exportación de solo lectura: ZIP/XML, filas, totales y filtros."""
import csv
import datetime
import decimal
import io
import json
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET
import zipfile

BASE = 'http://127.0.0.1:8080/taller_repuestos/index.php?'
NS = {'x': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}


def request(params, expected=200):
    try:
        response = urllib.request.urlopen(BASE + urllib.parse.urlencode(params))
    except urllib.error.HTTPError as error:
        response = error
    body = response.read()
    assert response.code == expected, (response.code, body[:500])
    return response, body


def summary(table, start, end):
    query = f"SET NOCOUNT ON; SELECT COUNT(*) total,COALESCE(SUM(total),0) importe FROM {table} WHERE fecha >= '{start}' AND fecha < DATEADD(day,1,CONVERT(date,'{end}')) FOR JSON PATH,WITHOUT_ARRAY_WRAPPER;"
    result = subprocess.check_output(['sqlcmd','-S','localhost','-E','-C','-b','-d','TallerRepuestosDB','-h','-1','-w','65535','-Q',query])
    return json.loads(result.decode().strip(), parse_float=decimal.Decimal)


today = datetime.date.today()
start, end = today.replace(day=1).isoformat(), today.isoformat()
for kind, table in [('ventas','RP07_VENTAS'),('compras','RP09_COMPRAS')]:
    expected = summary(table, start, end)
    params = {'export':kind, 'desde':start, 'hasta':end, 'format':'xlsx'}
    response, body = request(params)
    assert response.headers['Content-Type'].startswith('application/vnd.openxmlformats')
    with zipfile.ZipFile(io.BytesIO(body)) as book:
        assert book.testzip() is None
        for name in book.namelist():
            ET.fromstring(book.read(name))
        sheet = ET.fromstring(book.read('xl/worksheets/sheet1.xml'))
        records = sheet.findall('x:sheetData/x:row', NS)
        assert len(records) == expected['total'] + 6, (len(records), expected)
        total = records[-1].findall('x:c', NS)[-1].find('x:v', NS).text
        assert decimal.Decimal(total) == decimal.Decimal(expected['importe'])
        for formula in sheet.findall('.//x:f', NS):
            assert re.fullmatch(r'SUBTOTAL\(109,[A-Z]+6:[A-Z]+\d+\)',formula.text), 'Solo se admite la fórmula segura de total generado.'
        assert 'xl/worksheets/sheet2.xml' in book.namelist()
        table = ET.fromstring(book.read('xl/tables/table1.xml'))
        assert table.find('x:tableStyleInfo',NS).attrib['name'] == 'RepuestoGreen'
        assert table.attrib['totalsRowCount'] == '1'
        dates = records[5].findall('x:c',NS)[1]
        assert dates.attrib.get('t') != 'inlineStr' and float(dates.find('x:v',NS).text) > 40000
        overview = ET.fromstring(book.read('xl/worksheets/sheet2.xml'))
        assert 'Resumen mensual' in ''.join(overview.itertext())
        assert ('Resultados por vendedor' if kind == 'ventas' else 'Resultados por proveedor') in ''.join(overview.itertext())
    print('OK Excel:', kind, expected['total'], 'filas y total', expected['importe'])

    response, body = request(dict(params, format='csv'))
    records = list(csv.reader(io.StringIO(body.decode('utf-8-sig')), delimiter=';'))
    assert len(records) == expected['total'] + 4
    assert decimal.Decimal(records[-1][1]) == decimal.Decimal(expected['importe'])
    print('OK CSV:', kind, 'filas y total coinciden')
    response, body = request(dict(params, format='print'))
    assert b'window.print()' in body and b'Total del' in body and b'<thead>' in body
    print('OK documento imprimible:', kind)

for kind in ['clientes','proveedores','repuestos','inventario','historial']:
    response, body = request({'export':kind,'format':'xlsx','q':'NO-EXISTE-987654321'})
    with zipfile.ZipFile(io.BytesIO(body)) as book:
        sheet = ET.fromstring(book.read('xl/worksheets/sheet1.xml'))
        assert len(sheet.findall('x:sheetData/x:row', NS)) == 5
    print('OK Excel vacío válido:', kind)

for params in [
    {'export':'ventas','format':'xlsx','desde':'2026-02-30'},
    {'export':'ventas','format':'xlsx','desde':'2026-10-20','hasta':'2026-10-01'},
    {'export':'ventas','format':'exe'},
    {'export':'tabla_inventada','format':'xlsx'},
    {'export':'ventas','format':'print'},
]:
    response, body = request(params, 422)
    assert b'Content_Types' not in body
print('OK: fechas, formatos, reportes inválidos y exceso de impresión rechazados')
response, body = request({'export':'clientes','format':'xlsx'})
with zipfile.ZipFile(io.BytesIO(body)) as book:
    sheet = ET.fromstring(book.read('xl/worksheets/sheet1.xml'))
    assert len(sheet.findall('x:sheetData/x:row', NS)) >= 5005
print('OK: exportación completa de clientes, no solo la página visible')
