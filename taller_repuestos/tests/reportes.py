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
        workbook=ET.fromstring(book.read('xl/workbook.xml'))
        names=[s.attrib['name'] for s in workbook.findall('x:sheets/x:sheet',NS)]
        assert names[:2]==['Resumen','Ventas' if kind=='ventas' else 'Compras']
        assert 'Detalle de repuestos' in names and 'Por repuesto' in names
        assert ('Por vendedor' if kind=='ventas' else 'Por proveedor') in names
        assert '₡' in book.read('xl/styles.xml').decode('utf8')
        for index in [3,4,5]+([6] if kind=='compras' else []):
            extra=ET.fromstring(book.read(f'xl/worksheets/sheet{index}.xml'))
            last=extra.findall('x:sheetData/x:row',NS)[-1].findall('x:c',NS)[-1].find('x:v',NS).text
            assert decimal.Decimal(last)==decimal.Decimal(expected['importe'])
        relationships=ET.fromstring(book.read('xl/_rels/workbook.xml.rels'))
        for relation in relationships:
            assert relation.attrib['Type'].startswith('http://schemas.openxmlformats.org/officeDocument/2006/relationships/')
            assert 'xl/'+relation.attrib['Target'] in book.namelist()
    print('OK Excel:', kind, expected['total'], 'filas y total', expected['importe'])

    response, body = request(dict(params, format='csv'))
    records = list(csv.reader(io.StringIO(body.decode('utf-8-sig')), delimiter=';'))
    assert len(records) >= expected['total'] + 1
    assert len({r[0] for r in records[1:]})==expected['total']
    assert sum(decimal.Decimal(r[-1]) for r in records[1:]) == decimal.Decimal(expected['importe'])
    assert records[0][-1].endswith('(CRC)')
    print('OK CSV:', kind, 'filas y total coinciden')
    response, body = request(dict(params, format='print'))
    assert b'window.print()' in body and b'Total del' in body and b'<thead>' in body
    print('OK documento imprimible:', kind)

for kind in ['repuestos','inventario','historial','reposicion']:
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
    {'export':'historial','format':'print'},
    {'export':'ventas','format':'xlsx','cantidad':'200001'},
    {'export':'ventas','format':'xlsx','desde_resultado':'2'},
    {'export':'ventas','format':'xlsx','referencia_desde':'9','referencia_hasta':'3'},
]:
    response, body = request(params, 422)
    assert b'Content_Types' not in body
print('OK: fechas, formatos, reportes inválidos y exceso de impresión rechazados')
for kind in ['clientes','proveedores']:
    request({'export':kind,'format':'xlsx'},422)
print('OK: directorios personales no disponibles para exportación masiva')

def sql_json(query):
    result = subprocess.check_output(['sqlcmd','-S','localhost','-E','-C','-b','-d','TallerRepuestosDB','-h','-1','-w','65535','-Q','SET NOCOUNT ON; '+query])
    return json.loads(result.decode().strip(),parse_float=decimal.Decimal)

for kind, header, detail, key in [('ventas','RP07_VENTAS','RP08_DETALLE_VENTAS','id_venta'),('compras','RP09_COMPRAS','RP10_DETALLE_COMPRAS','id_compra')]:
    doc=sql_json(f'SELECT TOP(1) H.{key} id,H.total,COUNT(*) partidas FROM {header} H JOIN {detail} D ON D.{key}=H.{key} GROUP BY H.{key},H.total HAVING SUM(D.subtotal)=H.total ORDER BY H.{key} DESC FOR JSON PATH,WITHOUT_ARRAY_WRAPPER;')
    _, body=request({'receipt':kind,'id':doc['id'],'format':'xlsx'})
    with zipfile.ZipFile(io.BytesIO(body)) as book:
        assert book.testzip() is None
        sheet=ET.fromstring(book.read('xl/worksheets/sheet1.xml'))
        records=sheet.findall('x:sheetData/x:row', NS)
        assert len(records)==doc['partidas']+6
        assert decimal.Decimal(records[-1].findall('x:c',NS)[-1].find('x:v',NS).text)==doc['total']
    _,body=request({'receipt':kind,'id':doc['id'],'format':'print'})
    assert b'Comprobante interno' in body and b'window.print()' in body
    request({'view_operation':kind,'id':2147483647},404)
    request({'receipt':kind,'id':2147483647,'format':'xlsx'},404)
    print('OK: detalle y comprobantes individuales',kind)

part=sql_json('SELECT TOP(1) id_repuesto id,stock_actual FROM RP03_REPUESTOS WHERE EXISTS(SELECT 1 FROM RP11_INVENTARIO_HISTORIAL H WHERE H.id_repuesto=RP03_REPUESTOS.id_repuesto) ORDER BY id_repuesto FOR JSON PATH,WITHOUT_ARRAY_WRAPPER;')
_,body=request({'export':'kardex','repuesto':part['id'],'format':'csv'})
records=list(csv.reader(io.StringIO(body.decode('utf-8-sig')),delimiter=';'))
assert int(records[-1][4])==part['stock_actual'],records[-1]
for previous,current in zip(records[1:],records[2:]):
    assert int(current[4])==int(previous[4])+int(current[2])-int(current[3])
for format in ['xlsx','print']:
    _,body=request({'export':'kardex','repuesto':part['id'],'format':format})
    if format=='xlsx':
        with zipfile.ZipFile(io.BytesIO(body)) as book:
            assert book.testzip() is None
            for name in book.namelist():
                ET.fromstring(book.read(name))
    else:
        assert 'Cantidades al inicio y al final'.encode() in body
request({'export':'kardex','format':'csv'},422)
print('OK: kardex termina en las existencias actuales y exige un repuesto')
_,body=request({'export':'reposicion','format':'csv'})
records=list(csv.reader(io.StringIO(body.decode('utf-8-sig')),delimiter=';'))
for row in records[1:]:
    assert int(row[3])<=int(row[4])
    assert int(row[5])==int(row[4])-int(row[3])
print('OK: revisión de compras indica faltantes reales, sin recomendar mínimo más uno')

for kind in ['ventas','compras','inventario','historial','reposicion','precios']:
    params={'preview_export':kind,'format':'xlsx','cantidad':'17','desde_resultado':'4'}
    _,body=request(params);preview=json.loads(body)
    assert preview['records']==17,(kind,preview)
    _,body=request({'export':kind,'format':'xlsx','cantidad':'17','desde_resultado':'4'})
    with zipfile.ZipFile(io.BytesIO(body)) as book:
        sheet=ET.fromstring(book.read('xl/worksheets/sheet1.xml'))
        rows_data=sheet.findall('x:sheetData/x:row',NS)
        assert len(rows_data)==17+(6 if kind in ['ventas','compras'] else 5)
        if kind=='precios':
            headers=' '.join(rows_data[4].itertext()).lower()
            assert 'compra' not in headers and 'mínima' not in headers and 'precio de venta' in headers
    print('OK: vista previa y archivo coinciden para selección parcial',kind)

_,body=request({'export':'ventas','format':'xlsx','cantidad':'10','orden':'antiguos','desde_resultado':'1'})
with zipfile.ZipFile(io.BytesIO(body)) as book:
    records=ET.fromstring(book.read('xl/worksheets/sheet1.xml')).findall('x:sheetData/x:row',NS)
    headers=[row.findall('x:c',NS)[0].find('x:is/x:t',NS).text for row in records[5:-1]]
    detail=ET.fromstring(book.read('xl/worksheets/sheet3.xml')).findall('x:sheetData/x:row',NS)
    references={row.findall('x:c',NS)[0].find('x:is/x:t',NS).text for row in detail[5:-1]}
    assert set(headers)==references and len(headers)==10
    assert min(float(r.findall('x:c',NS)[1].find('x:v',NS).text) for r in records[5:-1])<45000
print('OK: selección de ventas antiguas conserva todo su detalle, sin cortar operaciones')
