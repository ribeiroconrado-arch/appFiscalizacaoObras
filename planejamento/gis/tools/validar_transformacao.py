# -*- coding: utf-8 -*-
"""
Etapa 1 — Validacao independente da transformacao local -> UTM.

`resolver_transformacao.py` deduz a transformacao a partir de um unico
loteamento. Este script confere se ela faz sentido para o MAPA INTEIRO, que e
uma verificacao independente: aplica a transformacao a extensao dos layers
`GIS_*`, reprojeta para WGS84 e mede a distancia ate o centro conhecido do
municipio. Se a base inteira cair sobre a cidade, a transformacao vale para
alem do loteamento que a originou.

Uso:
    python-qgis.bat validar_transformacao.py <cidade.dxf> <dX> <dY> [EPSG]
Somente leitura.
"""
import sys
import math

from osgeo import ogr, osr, gdal

gdal.UseExceptions(); ogr.UseExceptions()
gdal.PushErrorHandler("CPLQuietErrorHandler")

CIDADE_LAT, CIDADE_LON = -15.5556, -54.2961
UTM_X = (100000.0, 1000000.0)
UTM_Y = (7000000.0, 9500000.0)


def main(dxf, dx, dy, epsg=31981):
    ds = ogr.Open(dxf)
    lyr = ds.GetLayer(0)
    ext = [1e18, 1e18, -1e18, -1e18]
    n = 0
    for f in lyr:
        cad = f.GetField("Layer") or ""
        if not cad.startswith("GIS_"):
            continue
        g = f.GetGeometryRef()
        if g is None:
            continue
        e = g.GetEnvelope()
        cx, cy = (e[0] + e[1]) / 2.0, (e[2] + e[3]) / 2.0
        if UTM_X[0] <= cx <= UTM_X[1] and UTM_Y[0] <= cy <= UTM_Y[1]:
            continue
        n += 1
        ext[0] = min(ext[0], e[0]); ext[1] = min(ext[1], e[2])
        ext[2] = max(ext[2], e[1]); ext[3] = max(ext[3], e[3])

    print("Layers GIS_*: %d entidades" % n)
    print("Extensao local:  X %.1f .. %.1f   Y %.1f .. %.1f" % (ext[0], ext[2], ext[1], ext[3]))
    print("Dimensao: %.0f x %.0f m" % (ext[2] - ext[0], ext[3] - ext[1]))

    ux0, uy0, ux1, uy1 = ext[0] + dx, ext[1] + dy, ext[2] + dx, ext[3] + dy
    print("\nExtensao em UTM (EPSG:%d):" % epsg)
    print("  X %.1f .. %.1f   Y %.1f .. %.1f" % (ux0, ux1, uy0, uy1))

    src = osr.SpatialReference(); src.ImportFromEPSG(epsg)
    src.SetAxisMappingStrategy(osr.OAMS_TRADITIONAL_GIS_ORDER)
    dst = osr.SpatialReference(); dst.ImportFromEPSG(4326)
    dst.SetAxisMappingStrategy(osr.OAMS_TRADITIONAL_GIS_ORDER)
    tr = osr.CoordinateTransformation(src, dst)

    print("\nCantos em WGS84 (lat, lon):")
    cantos = [("SO", ux0, uy0), ("SE", ux1, uy0), ("NE", ux1, uy1), ("NO", ux0, uy1)]
    for nome, x, y in cantos:
        lon, lat, _ = tr.TransformPoint(x, y)
        print("  %s: %.6f, %.6f" % (nome, lat, lon))

    cxu, cyu = (ux0 + ux1) / 2.0, (uy0 + uy1) / 2.0
    lon, lat, _ = tr.TransformPoint(cxu, cyu)
    dkm = math.hypot((lat - CIDADE_LAT) * 111.32,
                     (lon - CIDADE_LON) * 111.32 * math.cos(math.radians(lat)))
    print("\nCentro da base: %.6f, %.6f" % (lat, lon))
    print("Centro conhecido de Primavera do Leste: %.6f, %.6f" % (CIDADE_LAT, CIDADE_LON))
    print("DISTANCIA: %.2f km" % dkm)
    if dkm < 3:
        print("\n>>> VALIDADO: a base inteira cai sobre a cidade.")
    elif dkm < 15:
        print("\n>>> PLAUSIVEL, mas conferir: pode ser deslocamento de datum ou fuso.")
    else:
        print("\n>>> FALHOU: a transformacao nao coloca a base sobre a cidade.")


if __name__ == "__main__":
    main(sys.argv[1], float(sys.argv[2]), float(sys.argv[3]),
         int(sys.argv[4]) if len(sys.argv) > 4 else 31981)
