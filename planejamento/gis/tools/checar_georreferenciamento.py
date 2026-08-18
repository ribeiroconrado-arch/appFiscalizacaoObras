# -*- coding: utf-8 -*-
"""
Verifica se um DXF esta em coordenadas UTM verdadeiras (georreferenciado) ou em
sistema local. Uso rapido, so le a extensao das entidades.

Serve para triar candidatos a fonte de pontos de controle: um loteamento
desenhado em UTM que tambem apareca no mapa da cidade permite resolver o
georreferenciamento por pares de pontos correspondentes.

Uso:
    python-qgis.bat checar_georreferenciamento.py <a.dxf> [b.dxf ...]
Somente leitura.
"""
import sys
import os
from collections import defaultdict

from osgeo import ogr, gdal

gdal.UseExceptions()
ogr.UseExceptions()
gdal.PushErrorHandler("CPLQuietErrorHandler")

UTM_X = (100000.0, 1000000.0)
UTM_Y = (7000000.0, 9500000.0)


def checar(caminho):
    ds = ogr.Open(caminho)
    if ds is None:
        print("  ERRO ao abrir")
        return
    lyr = ds.GetLayer(0)
    n = n_utm = 0
    bx = [float("inf"), float("inf"), float("-inf"), float("-inf")]
    ux = [float("inf"), float("inf"), float("-inf"), float("-inf")]
    layers_utm = defaultdict(int)

    for f in lyr:
        g = f.GetGeometryRef()
        if g is None:
            continue
        n += 1
        e = g.GetEnvelope()
        cx, cy = (e[0] + e[1]) / 2.0, (e[2] + e[3]) / 2.0
        bx[0] = min(bx[0], e[0]); bx[1] = min(bx[1], e[2])
        bx[2] = max(bx[2], e[1]); bx[3] = max(bx[3], e[3])
        if UTM_X[0] <= cx <= UTM_X[1] and UTM_Y[0] <= cy <= UTM_Y[1]:
            n_utm += 1
            layers_utm[f.GetField("Layer") or "?"] += 1
            ux[0] = min(ux[0], e[0]); ux[1] = min(ux[1], e[2])
            ux[2] = max(ux[2], e[1]); ux[3] = max(ux[3], e[3])

    pct = 100.0 * n_utm / n if n else 0
    print("  entidades: %d · em coordenada UTM: %d (%.1f%%)" % (n, n_utm, pct))
    print("  extensao geral: X %.1f..%.1f  Y %.1f..%.1f" % (bx[0], bx[2], bx[1], bx[3]))
    if n_utm:
        larg, alt = ux[2] - ux[0], ux[3] - ux[1]
        print("  >>> TRECHO UTM: X %.1f..%.1f  Y %.1f..%.1f  (%.0f x %.0f m)"
              % (ux[0], ux[2], ux[1], ux[3], larg, alt))
        top = sorted(layers_utm.items(), key=lambda kv: -kv[1])[:8]
        print("  >>> layers em UTM: %s" % ", ".join("%s(%d)" % t for t in top))
        if pct > 50:
            print("  >>> VEREDITO: GEORREFERENCIADO — serve como fonte de controle")
        else:
            print("  >>> VEREDITO: parcialmente georreferenciado — investigar")
    else:
        print("  >>> VEREDITO: sistema local, nao serve como fonte de controle")


if __name__ == "__main__":
    for p in sys.argv[1:]:
        print("\n=== %s ===" % os.path.basename(p))
        checar(p)
