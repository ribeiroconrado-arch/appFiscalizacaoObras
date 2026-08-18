# -*- coding: utf-8 -*-
"""
Etapa 0/1 — Escolha do bairro piloto, por densidade em grade.

A primeira versao deste script agrupava por RETANGULO ENVOLVENTE do layer de
bairro. Nao funcionou: os retangulos se sobrepoem muito (um layer como `TREVO`
cobre 16 km e engole a cidade inteira), o que produziu cobertura de 1300%.

Esta versao ignora os retangulos e trabalha pelo dado em si:

  1. joga tudo numa GRADE de 250 m: poligonos de lote ja fechados, numeros de
     lote (`GIS_TEXTOS`) e linhas soltas de `GIS_LOTES`;
  2. acha as REGIOES contiguas de celulas que ja tem poligono (flood fill em
     8 vizinhos) — cada regiao e uma area que ja foi organizada no CAD;
  3. nomeia cada regiao pelo layer de bairro com mais entidades ali dentro;
  4. ordena por cobertura (poligonos / numeros de lote).

A regiao com maior cobertura E tamanho suficiente para um teste de campo e a
recomendacao de piloto.

Uso:
    "C:\\Program Files\\QGIS 4.2.1\\bin\\python-qgis.bat" escolher_bairro_piloto.py <entrada.dxf> <saida.md>
Somente leitura.
"""
import sys
import os
from collections import defaultdict, deque

from osgeo import ogr, gdal

gdal.UseExceptions()
ogr.UseExceptions()
gdal.PushErrorHandler("CPLQuietErrorHandler")

CELULA = 250.0            # lado da celula da grade, em metros
LOTE_MIN, LOTE_MAX = 150.0, 800.0
UTM_X = (100000.0, 1000000.0)
UTM_Y = (7000000.0, 9500000.0)

NAO_BAIRRO_PREFIXO = ("GIS_", "UR ", "UR-", "URB", "_C-LAREA", "C-LAREA",
                      "0_", ".CB", "CB-", "PDF_", "TOP_", "PA_", "AL-",
                      "01 -", "ARQ-", "A-VIS", "D-LIMITES")
NAO_BAIRRO_TERMO = ("VEGETA", "HACHUR", "TEXTO", "TXT", "MEDIDA", "COTA",
                    "AUXILIAR", "MOLDURA", "MURO", "PASSEIO", "MEIO-FIO",
                    "MEIO FIO", "MFC", "SIMBOL", "SETA", "MARCOS", "TABELA",
                    "VAGAS", "TOPOGRAFIA", "VIEWPORT", "DIVISA", "RAIO ",
                    "POLIGONAL", "LIMITE", "PERIMETRO", "TREVO", "ENTORNO",
                    "FORA DE ESCALA", "DEFPOINTS", "OBJETOS", "HUMAN", "BANCO")
NAO_BAIRRO_EXATO = {"0", "1", "4", "06", "1_Q", "0 @ 2", "R3", "TG", "OFF",
                    "MAPA", "TEXTO", "LINHA", "AREA", "area", "PROJETO",
                    "OBRAS", "FUTURA", "LOTES1", "num lotes", "ESPECARQ",
                    "Norte", "Lagoas", "Corrego", "MEDIDAS", "VISTA"}


def eh_bairro(n):
    if n in NAO_BAIRRO_EXATO or len(n) < 3:
        return False
    if any(n.startswith(p) for p in NAO_BAIRRO_PREFIXO):
        return False
    u = n.upper()
    return not any(t in u for t in NAO_BAIRRO_TERMO)


def fechada(g, tol=1e-6):
    n = g.GetPointCount()
    return n >= 4 and abs(g.GetX(0) - g.GetX(n - 1)) < tol and \
        abs(g.GetY(0) - g.GetY(n - 1)) < tol


def area_pol(g):
    n = g.GetPointCount()
    if n < 4:
        return 0.0
    s = sum(g.GetX(i) * g.GetY(i + 1) - g.GetX(i + 1) * g.GetY(i)
            for i in range(n - 1))
    return abs(s) / 2.0


def cel(x, y):
    return (int(x // CELULA), int(y // CELULA))


def main(entrada, saida):
    ds = ogr.Open(entrada)
    lyr = ds.GetLayer(0)

    g_pol = defaultdict(int)     # celula -> poligonos de lote fechados
    g_txt = defaultdict(int)     # celula -> numeros de lote
    g_lin = defaultdict(int)     # celula -> linhas soltas de GIS_LOTES
    g_bai = defaultdict(lambda: defaultdict(int))  # celula -> layer bairro -> n

    for f in lyr:
        cad = f.GetField("Layer") or ""
        g = f.GetGeometryRef()
        if g is None:
            continue
        e = g.GetEnvelope()
        cx, cy = (e[0] + e[1]) / 2.0, (e[2] + e[3]) / 2.0
        if UTM_X[0] <= cx <= UTM_X[1] and UTM_Y[0] <= cy <= UTM_Y[1]:
            continue  # fragmento georreferenciado solto, fora da malha local
        c = cel(cx, cy)

        if cad == "GIS_TEXTOS":
            g_txt[c] += 1
        elif cad == "GIS_LOTES":
            sub = (f.GetField("SubClasses") or "").split(":")[-1]
            if g.GetGeometryType() in (ogr.wkbLineString, ogr.wkbLineString25D):
                if fechada(g) and sub in ("AcDbPolyline", "AcDb3dPolyline") \
                        and LOTE_MIN <= area_pol(g) <= LOTE_MAX:
                    g_pol[c] += 1
                elif sub in ("AcDbLine", "AcDbPolyline", "AcDb3dPolyline"):
                    g_lin[c] += 1
        elif eh_bairro(cad):
            g_bai[c][cad] += 1

    # ── regioes contiguas de celulas que ja tem poligono ──
    sementes = {c for c, n in g_pol.items() if n >= 3}
    visto, regioes = set(), []
    for s in sementes:
        if s in visto:
            continue
        fila, grupo = deque([s]), []
        visto.add(s)
        while fila:
            c = fila.popleft()
            grupo.append(c)
            for dx in (-1, 0, 1):
                for dy in (-1, 0, 1):
                    v = (c[0] + dx, c[1] + dy)
                    if v in sementes and v not in visto:
                        visto.add(v)
                        fila.append(v)
        regioes.append(grupo)

    linhas = []
    w = linhas.append
    w("# Bairro piloto — regioes ja organizadas no CAD\n")
    w("Arquivo: `%s` · grade de %d m\n" % (os.path.basename(entrada), CELULA))
    w("Uma **regiao** e um bloco contiguo de celulas que ja tem poligono de lote")
    w("fechado. **Lotes** = numeros de lote (`GIS_TEXTOS`) na regiao. **Prontos** =")
    w("poligonos fechados com area entre %d e %d m2. **Cobertura** = prontos/lotes." % (LOTE_MIN, LOTE_MAX))
    w("**Bairro provavel** = layer de bairro com mais entidades na regiao.\n")

    w("## Regioes candidatas, por cobertura\n")
    w("| # | Bairro provavel | Lotes | Prontos | Cobertura | Linhas a poligonizar | Celulas | Area (ha) | Centro (x, y) |")
    w("|---:|---|---:|---:|---:|---:|---:|---:|---|")

    tabela = []
    for grupo in regioes:
        pol = sum(g_pol[c] for c in grupo)
        txt = sum(g_txt[c] for c in grupo)
        lin = sum(g_lin[c] for c in grupo)
        nomes = defaultdict(int)
        for c in grupo:
            for b, n in g_bai[c].items():
                nomes[b] += n
        bairro = max(nomes, key=nomes.get) if nomes else "(indefinido)"
        cx = sum(c[0] for c in grupo) / len(grupo) * CELULA + CELULA / 2
        cy = sum(c[1] for c in grupo) / len(grupo) * CELULA + CELULA / 2
        cob = 100.0 * pol / txt if txt else 0.0
        tabela.append((cob, pol, txt, lin, len(grupo), bairro, cx, cy, nomes))

    tabela.sort(key=lambda r: (-min(r[0], 100), -r[1]))
    for i, (cob, pol, txt, lin, nc, bairro, cx, cy, _n) in enumerate(tabela[:20], 1):
        w("| %d | `%s` | %d | %d | **%.0f%%** | %d | %d | %.1f | %.0f, %.0f |" %
          (i, bairro, txt, pol, cob, lin, nc, nc * CELULA * CELULA / 10000.0, cx, cy))
    w("")

    w("## Detalhe das 5 primeiras regioes\n")
    for i, (cob, pol, txt, lin, nc, bairro, cx, cy, nomes) in enumerate(tabela[:5], 1):
        top = sorted(nomes.items(), key=lambda kv: -kv[1])[:6]
        w("**Regiao %d — `%s`** · %d lotes · %d prontos (%.0f%%) · %d linhas · %.1f ha"
          % (i, bairro, txt, pol, cob, lin, nc * CELULA * CELULA / 10000.0))
        w("  Layers de bairro presentes: %s\n" %
          ", ".join("`%s` (%d)" % (b, n) for b, n in top))

    tot_pol = sum(g_pol.values())
    tot_txt = sum(g_txt.values())
    w("\n## Total do municipio\n")
    w("- Numeros de lote: **%d**" % tot_txt)
    w("- Poligonos de lote prontos: **%d** (%.1f%%)" %
      (tot_pol, 100.0 * tot_pol / max(tot_txt, 1)))
    w("- Linhas soltas em `GIS_LOTES`: **%d**" % sum(g_lin.values()))
    w("- Regioes ja organizadas: **%d**" % len(regioes))

    with open(saida, "w", encoding="utf-8") as fh:
        fh.write("\n".join(linhas))
    print("OK -> %s" % saida)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1], sys.argv[2]))
