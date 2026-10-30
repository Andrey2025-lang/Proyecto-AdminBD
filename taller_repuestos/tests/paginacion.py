"""Verificaciones HTTP de paginación y búsquedas. No escribe en la base."""
import re
import urllib.error
import urllib.parse
import urllib.request

BASE = 'http://127.0.0.1:8080/taller_repuestos/index.php?'


def get(params, status=200):
    try:
        response = urllib.request.urlopen(BASE + urllib.parse.urlencode(params))
    except urllib.error.HTTPError as error:
        response = error
    body = response.read().decode('utf-8')
    assert response.code == status, (response.code, body[:300])
    assert 'No se pudo cargar' not in body
    return body


def first_rows(body):
    return re.search(r'<tbody>(.*?)</tbody>', body, re.S).group(1)


for page in ['clientes','repuestos','ventas','compras']:
    first = get({'page':page})
    second = get({'page':page,'p':2})
    a, b = first_rows(first), first_rows(second)
    if page in ['clientes','repuestos']:
        ids_a = re.findall(r'href="[^"]*edit=(\d+)', a)
        ids_b = re.findall(r'href="[^"]*edit=(\d+)', b)
    else:
        ids_a = re.findall(r'<strong>#(\d+)', a)
        ids_b = re.findall(r'<strong>#(\d+)', b)
    assert len(ids_a) == len(ids_b) == 15
    assert not set(ids_a).intersection(ids_b)
    assert first_rows(get({'page':page,'tam':30})).count('<tr>') == 30
    assert first_rows(get({'page':page,'tam':50})).count('<tr>') == 50
    assert first_rows(get({'page':page,'tam':100})).count('<tr>') == 100
    assert 'Por página' not in first
    print('OK:', page, '15 filas predeterminadas, selector al pie y páginas sin solaparse')

for page in ['inventario','reportes','inicio']:
    get({'page':page})
for kind, term in [('clientes','Daniela'),('repuestos','Hilux'),('proveedores','Distribuidora')]:
    import json
    body = json.loads(get({'lookup':kind,'term':term,'operation':'sale'}))
    assert 0 <= len(body['items']) <= 20 and body['page'] == 1
    print('OK: búsqueda acotada de', kind)
body = json.loads(get({'lookup':'repuestos','term':'H','operation':'sale'}))
assert body['items'] == [] and body['minimum'] == 2
body = get({'page':'inventario'})
tables = re.findall(r'<tbody>(.*?)</tbody>',body,re.S)
assert tables[0].count('<tr>') == tables[1].count('<tr>') == 10
body = get({'page':'inventario','tam':15,'htam':30})
tables = re.findall(r'<tbody>(.*?)</tbody>',body,re.S)
assert tables[0].count('<tr>') == 15 and tables[1].count('<tr>') == 30
print('OK: inventario e historial con 10 filas y tamaños independientes')
get({'page':'clientes','p':2147483647})
assert 'No hay' in get({'page':'clientes','q':'%'}) or 'Todavía' in get({'page':'clientes','q':'%'})
for params in [{'page':'clientes','tam':10000},{'page':'ventas','desde':'2026-02-30'},{'page':'clientes','p':-1}]:
    get(params,422)
print('OK: filtros inválidos rechazados y búsqueda literal de comodines')
