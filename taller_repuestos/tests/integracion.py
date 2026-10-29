"""Prueba real local. Agrega una compra de 5 unidades y una venta de 3 DEMO.

Uso: python taller_repuestos/tests/integracion.py --confirmar-escritura
Requiere SQL Server local, datos_prueba.sql y PHP en 127.0.0.1:8080.
No elimina registros. Cada ejecución deja dos operaciones de demostración.
"""
import argparse
import http.cookiejar
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--confirmar-escritura', action='store_true')
args = parser.parse_args()
if not args.confirmar_escritura:
    parser.error('Se requiere --confirmar-escritura: se guardan operaciones reales de demostración.')

BASE = 'http://127.0.0.1:8080/taller_repuestos/'
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))


def sql(query):
    result = subprocess.run(
        ['sqlcmd', '-S', 'localhost', '-E', '-C', '-b', '-d', 'TallerRepuestosDB',
         '-h', '-1', '-W', '-s', '|', '-Q', 'SET NOCOUNT ON; ' + query],
        capture_output=True, check=True,
    )
    return result.stdout.decode(errors='replace').strip()


def get(page):
    response = opener.open(BASE + 'index.php?page=' + page)
    html = response.read().decode('utf-8')
    assert response.status == 200 and '<h1>' in html, page
    assert 'No se pudo consultar' not in html and 'Conectemos el taller' not in html, page
    return html


def post(page, payload, expected=200):
    html = get(page)
    token = re.search(r'name="csrf" value="([a-f0-9]+)"', html).group(1)
    payload['csrf'] = token
    request = urllib.request.Request(
        BASE + 'index.php?page=' + page,
        data=urllib.parse.urlencode(payload).encode(),
        headers={'HX-Request': 'true'},
    )
    try:
        response = opener.open(request)
    except urllib.error.HTTPError as error:
        response = error
    body = response.read().decode('utf-8')
    assert response.code == expected, (page, response.code, body)
    if expected == 200:
        assert 'notification is-success' in body, body
    return body


part, client, seller, supplier = map(int, sql("""
SELECT
 (SELECT id_repuesto FROM RP03_REPUESTOS WHERE codigo_barras='DEMO-FILTRO-01'),
 (SELECT id_cliente FROM RP05_CLIENTES WHERE cedula='DEMO-CLIENTE'),
 (SELECT usuario_id FROM RP13_USUARIOS WHERE nombre_usuario='vendedor_demo'),
 (SELECT TOP (1) id_proveedor FROM RP02_PROVEEDORES WHERE email='proveedor-demo@example.com' ORDER BY id_proveedor)
""").split('|'))


def snapshot():
    return tuple(map(int, sql(f"""SELECT stock_actual,
        (SELECT COUNT(*) FROM RP09_COMPRAS),
        (SELECT COUNT(*) FROM RP10_DETALLE_COMPRAS),
        (SELECT COUNT(*) FROM RP07_VENTAS),
        (SELECT COUNT(*) FROM RP08_DETALLE_VENTAS),
        (SELECT COUNT(*) FROM RP11_INVENTARIO_HISTORIAL)
        FROM RP03_REPUESTOS WHERE id_repuesto={part}""").split('|')))


for page in ['inicio', 'repuestos', 'clientes', 'proveedores', 'compras', 'ventas', 'inventario']:
    get(page)
    print('OK: pantalla real', page)

before = snapshot()
post('compras', {'action':'purchase', 'id_repuesto':part, 'cantidad':5,
                'id_proveedor':supplier, 'precio_unitario':'3500.00'})
after_purchase = snapshot()
assert after_purchase == (before[0]+5, before[1]+1, before[2]+1, before[3], before[4], before[5]+1)
assert sql(f'SELECT TOP (1) subtotal FROM RP10_DETALLE_COMPRAS WHERE id_repuesto={part} ORDER BY id_detalle_compra DESC') == '17500.00'
print('OK: compra +5, importe 17500.00 y entrada en historial')

post('ventas', {'action':'sale', 'id_repuesto':part, 'cantidad':3,
               'id_cliente':client, 'id_vendedor':seller, 'precio_unitario':'0.01'})
after_sale = snapshot()
assert after_sale == (before[0]+2, before[1]+1, before[2]+1, before[3]+1, before[4]+1, before[5]+2)
assert sql(f'SELECT TOP (1) subtotal FROM RP08_DETALLE_VENTAS WHERE id_repuesto={part} ORDER BY id_detalle_venta DESC') == '16500.00'
assert int(sql(f'SELECT TOP (1) usuario_id FROM RP11_INVENTARIO_HISTORIAL WHERE id_repuesto={part} ORDER BY id_historial DESC')) == seller
print('OK: venta -3, precio del servidor (no el manipulado) y vendedor en historial')

body = post('ventas', {'action':'sale', 'id_repuesto':part, 'cantidad':after_sale[0]+1,
                      'id_cliente':client, 'id_vendedor':seller}, 422)
assert 'Existencias insuficientes' in body
assert snapshot() == after_sale
print('OK: venta con stock insuficiente rechazada sin cambios parciales')

post('ventas', {'action':'sale', 'id_repuesto':part, 'cantidad':1,
               'id_cliente':2147483647, 'id_vendedor':seller}, 422)
assert snapshot() == after_sale
print('OK: error de clave foranea revierte toda la venta')

for page in ['inicio', 'compras', 'ventas', 'inventario']:
    get(page)
assert snapshot() == after_sale
print('OK: operaciones persistentes tras recargar las pantallas')
print(f'Existencias DEMO-FILTRO-01: {before[0]} -> {after_purchase[0]} -> {after_sale[0]}')
print('Prueba real completada; se conservaron una compra y una venta de demostracion.')
