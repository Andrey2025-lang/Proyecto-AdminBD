"""Mide respuestas HTTP locales de solo lectura; no modifica la base."""
import argparse
import json
import statistics
import time
import urllib.request

parser = argparse.ArgumentParser()
parser.add_argument('--guardar')
args = parser.parse_args()
results = {}
for page in ['inicio', 'clientes', 'repuestos', 'compras', 'ventas', 'inventario']:
    url = 'http://127.0.0.1:8080/taller_repuestos/index.php?page=' + page
    urllib.request.urlopen(url).read()
    times = []
    for _ in range(5):
        start = time.perf_counter()
        response = urllib.request.urlopen(url)
        body = response.read()
        assert response.status == 200 and b'No se pudo cargar' not in body
        times.append((time.perf_counter() - start) * 1000)
    results[page] = {'mediana_ms': round(statistics.median(times), 2), 'bytes_html': len(body)}
    print(page, results[page])
if args.guardar:
    with open(args.guardar, 'w', encoding='utf-8') as file:
        json.dump(results, file, indent=2)
