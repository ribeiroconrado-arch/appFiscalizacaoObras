# -*- coding: utf-8 -*-
"""
Etapa 0 — Diagnostico do DXF exportado do DWG municipal.

Faz uma unica passagem sobre a camada 'entities' do DXF (driver DXF do GDAL,
que achata tudo em uma camada so, com o nome do layer do CAD no campo 'Layer')
e produz um inventario em Markdown:

  - quantidade de entidades por layer do CAD x tipo de geometria;
  - quantas polilinhas fecham (1o ponto == ultimo ponto) por layer  <-- e este
    numero que decide se da pra montar poligono de lote;
  - textos por layer (candidatos a inscricao imobiliaria / numero de lote);
  - extensao das coordenadas (para inferir o CRS provavel).

Uso:
    "C:\\Program Files\\QGIS 4.2.1\\bin\\python-qgis.bat" diagnostico_dxf.py <entrada.dxf> <saida.md>

Somente leitura. Nao altera o DXF nem o DWG de origem.
"""
import sys
import os
from collections import defaultdict

from osgeo import ogr, gdal

gdal.UseExceptions()
ogr.UseExceptions()

TOL = 1e-6  # tolerancia para considerar polilinha fechada (unidades do desenho)


def fechada(geom):
    """LINESTRING cujo primeiro ponto coincide com o ultimo, dentro de TOL."""
    if geom.GetPointCount() < 4:
        return False
    x0, y0 = geom.GetX(0), geom.GetY(0)
    xn, yn = geom.GetX(geom.GetPointCount() - 1), geom.GetY(geom.GetPointCount() - 1)
    return abs(x0 - xn) < TOL and abs(y0 - yn) < TOL


def main(entrada, saida):
    ds = ogr.Open(entrada)
    if ds is None:
        print("ERRO: nao foi possivel abrir %s" % entrada)
        return 1
    lyr = ds.GetLayer(0)

    # stats[layer_cad] = {tipo_geom: n}, fechadas/abertas, textos, exemplos de texto
    stats = defaultdict(lambda: defaultdict(int))
    fech = defaultdict(int)
    abert = defaultdict(int)
    textos = defaultdict(int)
    exemplos = defaultdict(list)
    subclasses = defaultdict(lambda: defaultdict(int))
    minx = miny = float("inf")
    maxx = maxy = float("-inf")
    total = 0
    sem_geom = 0

    for f in lyr:
        total += 1
        if total % 200000 == 0:
            print("  ... %d entidades" % total, flush=True)

        cad = f.GetField("Layer") or "(sem layer)"
        sub = f.GetField("SubClasses") or ""
        subclasses[cad][sub.split(":")[-1] if sub else "?"] += 1

        txt = f.GetField("Text") if "Text" in [
            f.GetFieldDefnRef(i).GetName() for i in range(f.GetFieldCount())
        ] else None
        if txt:
            textos[cad] += 1
            if len(exemplos[cad]) < 5:
                exemplos[cad].append(txt.strip()[:40])

        g = f.GetGeometryRef()
        if g is None:
            sem_geom += 1
            stats[cad]["(sem geometria)"] += 1
            continue

        tipo = ogr.GeometryTypeToName(g.GetGeometryType())
        stats[cad][tipo] += 1

        if g.GetGeometryType() in (ogr.wkbLineString, ogr.wkbLineString25D):
            if fechada(g):
                fech[cad] += 1
            else:
                abert[cad] += 1

        env = g.GetEnvelope()  # (minx, maxx, miny, maxy)
        minx = min(minx, env[0]); maxx = max(maxx, env[1])
        miny = min(miny, env[2]); maxy = max(maxy, env[3])

    # ── relatorio ──
    linhas = []
    w = linhas.append
    w("# Diagnostico do DXF — base cartografica municipal\n")
    w("Arquivo: `%s`  " % os.path.basename(entrada))
    w("Entidades lidas: **%d**  (sem geometria: %d)\n" % (total, sem_geom))

    w("## Extensao das coordenadas\n")
    w("| | minimo | maximo | amplitude |")
    w("|---|---|---|---|")
    w("| X | %.3f | %.3f | %.1f |" % (minx, maxx, maxx - minx))
    w("| Y | %.3f | %.3f | %.1f |" % (miny, maxy, maxy - miny))
    w("")
    if 100000 < minx < 1000000 and 1000000 < miny < 10000000:
        w("Faixa compativel com **UTM em metros** (E de 6 digitos, N de 7 digitos).")
        w("Primavera do Leste/MT fica no **fuso 22S** — candidatos: "
          "EPSG:31982 (SIRGAS 2000 / UTM 22S) ou EPSG:32722 (WGS84 / UTM 22S).\n")
    elif abs(minx) < 180 and abs(miny) < 180:
        w("Faixa compativel com **graus decimais** (geografico, EPSG:4326).\n")
    else:
        w("**Faixa nao reconhecida** — provavel sistema local/arbitrario; "
          "vai precisar de georreferenciamento por pontos de controle.\n")

    w("## Entidades por layer do CAD\n")
    w("| Layer (CAD) | Total | Fechadas | Abertas | %% fechadas | Textos | Tipos de geometria |")
    w("|---|---:|---:|---:|---:|---:|---|")
    for cad in sorted(stats, key=lambda k: -sum(stats[k].values())):
        tot = sum(stats[cad].values())
        fe, ab = fech[cad], abert[cad]
        pct = ("%.1f%%" % (100.0 * fe / (fe + ab))) if (fe + ab) else "—"
        tipos = ", ".join("%s: %d" % (t, n) for t, n in
                          sorted(stats[cad].items(), key=lambda kv: -kv[1]))
        w("| `%s` | %d | %d | %d | %s | %d | %s |" %
          (cad, tot, fe, ab, pct, textos[cad], tipos))
    w("")

    w("## Tipos de entidade DXF por layer\n")
    for cad in sorted(subclasses, key=lambda k: -sum(subclasses[k].values())):
        det = ", ".join("%s: %d" % (t, n) for t, n in
                        sorted(subclasses[cad].items(), key=lambda kv: -kv[1]))
        w("- `%s` — %s" % (cad, det))
    w("")

    w("## Amostras de texto por layer\n")
    w("Candidatos a inscricao imobiliaria, numero de lote/quadra e nome de logradouro.\n")
    for cad in sorted(exemplos, key=lambda k: -textos[k]):
        w("- `%s` (%d textos): %s" % (cad, textos[cad],
                                      " · ".join(repr(t) for t in exemplos[cad])))
    w("")

    with open(saida, "w", encoding="utf-8") as fh:
        fh.write("\n".join(linhas))
    print("OK -> %s" % saida)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1], sys.argv[2]))
