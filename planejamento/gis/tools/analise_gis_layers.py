# -*- coding: utf-8 -*-
"""
Etapa 0/1 — Analise focada nos layers `GIS_*` do DXF.

Complementa o diagnostico geral (`diagnostico_dxf.py`) respondendo as tres
perguntas que decidem o projeto:

  1. QUAL O CRS REAL? Testa os candidatos de UTM para Primavera do Leste/MT
     reprojetando o centro do desenho e comparando com a coordenada conhecida
     da cidade (-15.5556, -54.2961).
  2. ONDE ESTA CADA LAYER? Extensao por layer, separando as entidades que caem
     na faixa UTM plausivel das que estao perto da origem (moldura, legenda,
     "fora de escala") — a mistura das duas e o que faz a extensao global
     parecer absurda.
  3. DA PRA MONTAR POLIGONO DE LOTE? Para GIS_LOTES/GIS_QUADRAS, mede as
     polilinhas fechadas e a distribuicao de areas, para saber quantas tem
     tamanho compativel com lote urbano.

Uso:
    "C:\\Program Files\\QGIS 4.2.1\\bin\\python-qgis.bat" analise_gis_layers.py <entrada.dxf> <saida.md>
Somente leitura.
"""
import sys
import os
from collections import defaultdict

from osgeo import ogr, osr, gdal

gdal.UseExceptions()
ogr.UseExceptions()
gdal.PushErrorHandler("CPLQuietErrorHandler")

# Coordenada conhecida do centro de Primavera do Leste/MT (WGS84)
CIDADE_LAT, CIDADE_LON = -15.5556, -54.2961

# Candidatos de CRS projetado para a regiao. O fuso e calculado por
# floor((lon+180)/6)+1 = 21 para lon -54.3, mas 22 entra na lista porque a
# cidade fica quase no limite dos fusos 21/22 (meridiano -54).
CANDIDATOS = [
    (31981, "SIRGAS 2000 / UTM 21S"),
    (31982, "SIRGAS 2000 / UTM 22S"),
    (32721, "WGS 84 / UTM 21S"),
    (32722, "WGS 84 / UTM 22S"),
    (29191, "SAD69 / UTM 21S"),
    (29192, "SAD69 / UTM 22S"),
]

# Faixa considerada "coordenada UTM plausivel" (metros)
UTM_X = (100000.0, 1000000.0)
UTM_Y = (7000000.0, 9500000.0)

FAIXAS_AREA = [(0, 100), (100, 200), (200, 360), (360, 600), (600, 1000),
               (1000, 2000), (2000, 5000), (5000, 20000), (20000, 1e12)]


def na_faixa_utm(x, y):
    return UTM_X[0] <= x <= UTM_X[1] and UTM_Y[0] <= y <= UTM_Y[1]


def testar_crs(x, y):
    """Reprojeta (x,y) de cada CRS candidato para WGS84 e mede o erro em km
    contra a coordenada conhecida da cidade."""
    alvo = osr.SpatialReference()
    alvo.ImportFromEPSG(4326)
    alvo.SetAxisMappingStrategy(osr.OAMS_TRADITIONAL_GIS_ORDER)
    out = []
    for epsg, nome in CANDIDATOS:
        try:
            src = osr.SpatialReference()
            src.ImportFromEPSG(epsg)
            src.SetAxisMappingStrategy(osr.OAMS_TRADITIONAL_GIS_ORDER)
            lon, lat, _ = osr.CoordinateTransformation(src, alvo).TransformPoint(x, y)
            # aproximacao suficiente para ranquear candidatos
            dkm = ((lat - CIDADE_LAT) * 111.32) ** 2 + \
                  ((lon - CIDADE_LON) * 111.32 * 0.963) ** 2
            out.append((dkm ** 0.5, epsg, nome, lat, lon))
        except Exception as e:
            out.append((float("inf"), epsg, nome + " (erro: %s)" % e, 0, 0))
    return sorted(out)


def area_poligono(geom):
    """Area do poligono formado por uma LINESTRING fechada (formula do shoelace,
    em unidades do desenho ao quadrado = m2 se o CRS for UTM)."""
    n = geom.GetPointCount()
    if n < 4:
        return 0.0
    s = 0.0
    for i in range(n - 1):
        x1, y1 = geom.GetX(i), geom.GetY(i)
        x2, y2 = geom.GetX(i + 1), geom.GetY(i + 1)
        s += x1 * y2 - x2 * y1
    return abs(s) / 2.0


def fechada(g, tol=1e-6):
    n = g.GetPointCount()
    if n < 4:
        return False
    return (abs(g.GetX(0) - g.GetX(n - 1)) < tol and
            abs(g.GetY(0) - g.GetY(n - 1)) < tol)


def main(entrada, saida):
    ds = ogr.Open(entrada)
    lyr = ds.GetLayer(0)

    ext = {}          # layer -> [minx, miny, maxx, maxy] (so entidades em faixa UTM)
    dentro = defaultdict(int)
    fora = defaultdict(int)
    areas = defaultdict(list)   # layer -> areas das polilinhas fechadas
    subtipo_fech = defaultdict(lambda: defaultdict(int))
    total = 0

    for f in lyr:
        total += 1
        cad = f.GetField("Layer") or "(sem layer)"
        g = f.GetGeometryRef()
        if g is None:
            continue
        e = g.GetEnvelope()  # minx maxx miny maxy
        cx, cy = (e[0] + e[1]) / 2.0, (e[2] + e[3]) / 2.0
        if na_faixa_utm(cx, cy):
            dentro[cad] += 1
        else:
            fora[cad] += 1
        # A extensao e medida sobre TODAS as entidades do layer: o filtro UTM
        # serve so para contar quantas caem na faixa, nao para descartar — foi
        # descartando que a primeira versao deste script zerou a analise de area.
        if cad not in ext:
            ext[cad] = [e[0], e[2], e[1], e[3]]
        else:
            b = ext[cad]
            b[0] = min(b[0], e[0]); b[1] = min(b[1], e[2])
            b[2] = max(b[2], e[1]); b[3] = max(b[3], e[3])

        if cad.startswith("GIS_") and g.GetGeometryType() in (
                ogr.wkbLineString, ogr.wkbLineString25D):
            if fechada(g):
                a = area_poligono(g)
                areas[cad].append(a)
                sub = (f.GetField("SubClasses") or "").split(":")[-1]
                subtipo_fech[cad][sub] += 1

    # Centro de referencia para o teste de CRS: usa o layer GIS_LOTES, que e o
    # corpo real da base cadastral (a extensao global mistura moldura e
    # anotacoes soltas e nao serve como referencia).
    ref = ext.get("GIS_LOTES") or list(ext.values())[0]
    cx, cy = (ref[0] + ref[2]) / 2.0, (ref[1] + ref[3]) / 2.0

    L = []
    w = L.append
    w("# Analise dos layers GIS — CRS, extensao e viabilidade de poligonos\n")
    w("Arquivo: `%s` · entidades: %d\n" % (os.path.basename(entrada), total))

    w("## 1. Sistema de coordenadas\n")
    w("Centro das entidades georreferenciadas: **X=%.2f  Y=%.2f**\n" % (cx, cy))
    w("Reprojetando esse centro por cada CRS candidato e comparando com o centro")
    w("conhecido de Primavera do Leste (%.4f, %.4f):\n" % (CIDADE_LAT, CIDADE_LON))
    w("| CRS candidato | EPSG | lat obtida | lon obtida | erro (km) |")
    w("|---|---:|---:|---:|---:|")
    for dkm, epsg, nome, lat, lon in testar_crs(cx, cy):
        w("| %s | %d | %.4f | %.4f | **%.1f** |" % (nome, epsg, lat, lon, dkm))
    w("")

    w("## 2. Extensao por layer (so entidades em faixa UTM)\n")
    w("| Layer | Entidades em faixa | Fora da faixa | X min | X max | Y min | Y max | Largura (m) | Altura (m) |")
    w("|---|---:|---:|---:|---:|---:|---:|---:|---:|")
    interesse = sorted(ext, key=lambda k: -dentro[k])
    for cad in interesse[:40]:
        b = ext[cad]
        w("| `%s` | %d | %d | %.0f | %.0f | %.0f | %.0f | %.0f | %.0f |" %
          (cad, dentro[cad], fora[cad], b[0], b[2], b[1], b[3],
           b[2] - b[0], b[3] - b[1]))
    w("")
    total_fora = sum(fora.values())
    w("Entidades fora da faixa UTM (moldura/legenda/desenho fora de escala): "
      "**%d** de %d (%.1f%%).\n" % (total_fora, total, 100.0 * total_fora / total))

    w("## 3. Polilinhas fechadas nos layers GIS — distribuicao de area\n")
    for cad in sorted(areas, key=lambda k: -len(areas[k])):
        vals = sorted(areas[cad])
        w("### `%s` — %d polilinhas fechadas\n" % (cad, len(vals)))
        w("Tipos: %s\n" % ", ".join("%s: %d" % (k, v)
                                    for k, v in sorted(subtipo_fech[cad].items(),
                                                       key=lambda kv: -kv[1])))
        w("| Faixa de area (m2) | Quantidade |")
        w("|---|---:|")
        for lo, hi in FAIXAS_AREA:
            n = sum(1 for a in vals if lo <= a < hi)
            if n:
                rot = "%d – %d" % (lo, hi) if hi < 1e11 else "> %d" % lo
                w("| %s | %d |" % (rot, n))
        if vals:
            med = vals[len(vals) // 2]
            w("")
            w("Mediana: **%.1f m2** · minimo: %.1f · maximo: %.1f\n" %
              (med, vals[0], vals[-1]))
        w("")

    with open(saida, "w", encoding="utf-8") as fh:
        fh.write("\n".join(L))
    print("OK -> %s" % saida)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1], sys.argv[2]))
