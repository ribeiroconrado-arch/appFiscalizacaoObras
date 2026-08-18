# -*- coding: utf-8 -*-
"""
Etapa 1 — Converte lotes de um DXF georreferenciado para GeoJSON em EPSG:4326.

Le as polilinhas fechadas da camada de lotes, fecha em poligono, junta o numero
do lote por ponto-em-poligono a partir da camada de textos, calcula a area no
sistema projetado (metros) e reprojeta para 4326, que e o que o Leaflet consome.

Cada loteamento do acervo usa uma convencao de layer diferente
(`UR - Polyline Lote`, `_C-LAREA-LOTES`, `PMV - Lote`, `BURITIS V - LOTES`...),
por isso as camadas entram por parametro em vez de estarem fixas no codigo.

Uso:
    python-qgis.bat dxf_para_geojson.py <entrada.dxf> <saida.geojson> \
        --lotes "UR - Polyline Lote" --textos "UR - Txt - n. lote" \
        [--epsg 31981] [--bairro "Jardim Europa"] [--dx 0 --dy 0]

--dx/--dy aplicam a translacao local->UTM (ver docs/etapa1-georreferenciamento.md)
quando a entrada estiver em coordenadas locais. Para arquivos ja em UTM, omitir.
Somente leitura sobre o DXF.
"""
import argparse
import json
import re
import sys

from osgeo import ogr, osr, gdal

gdal.UseExceptions(); ogr.UseExceptions()
gdal.PushErrorHandler("CPLQuietErrorHandler")

AREA_MIN, AREA_MAX = 80.0, 5000.0        # descarta hachura/simbolo e gleba inteira
QUADRA_MIN, QUADRA_MAX = 500.0, 60000.0  # faixa de area de quadra urbana
DIST_QUADRA_MAX = 200.0                  # raio do plano B de atribuicao de quadra (m)

# Um rotulo de lote e um numero curto, as vezes com letra ("12", "12A"). Serve
# para separar o NUMERO da AREA quando os dois convivem no mesmo layer — em
# `BURITIS V - TEXTO LOTES`, por exemplo, alternam "215,00 m2" e "1".
RE_NUMERO = re.compile(r"^\d{1,4}[A-Za-z]?$")


def fechada(g, tol=1e-6):
    n = g.GetPointCount()
    return n >= 4 and abs(g.GetX(0) - g.GetX(n - 1)) < tol and \
        abs(g.GetY(0) - g.GetY(n - 1)) < tol


def para_poligono(g, dx, dy):
    """LINESTRING fechada -> POLYGON, aplicando a translacao local->UTM.
    Devolve None para geometria que nao da poligono valido."""
    anel = ogr.Geometry(ogr.wkbLinearRing)
    for i in range(g.GetPointCount()):
        anel.AddPoint_2D(g.GetX(i) + dx, g.GetY(i) + dy)
    # `fechada()` aceita coincidencia dentro de tolerancia; o anel do OGR exige
    # primeiro == ultimo EXATO, senao o Buffer(0) estoura.
    anel.CloseRings()
    if anel.GetPointCount() < 4:
        return None
    pol = ogr.Geometry(ogr.wkbPolygon)
    pol.AddGeometry(anel)
    if not pol.IsValid():
        try:
            pol = pol.Buffer(0)   # conserta auto-intersecao simples
        except RuntimeError:
            return None           # degenerada demais: descarta
        if pol is None or pol.IsEmpty() or \
                pol.GetGeometryType() not in (ogr.wkbPolygon, ogr.wkbPolygon25D):
            return None
    return pol


def rotular(pol, rotulos, so_numero=True):
    """Devolve o texto cujo ponto de insercao cai dentro do poligono.
    Prefere rotulos com cara de numero; se nao houver, aceita o primeiro."""
    env = pol.GetEnvelope()
    candidatos = []
    for rx, ry, t in rotulos:
        if not (env[0] <= rx <= env[1] and env[2] <= ry <= env[3]):
            continue
        p = ogr.Geometry(ogr.wkbPoint)
        p.AddPoint_2D(rx, ry)
        if pol.Contains(p):
            candidatos.append(t)
    if not candidatos:
        return None
    if so_numero:
        for t in candidatos:
            if RE_NUMERO.match(t):
                return t
    return candidatos[0]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("entrada")
    ap.add_argument("saida")
    ap.add_argument("--lotes", required=True, help="layer das polilinhas de lote")
    ap.add_argument("--textos", default=None, help="layer dos numeros de lote")
    ap.add_argument("--quadras", default=None, help="layer das polilinhas de quadra")
    ap.add_argument("--textos-quadra", dest="textos_quadra", default=None,
                    help="layer dos numeros de quadra")
    ap.add_argument("--epsg", type=int, default=31981)
    ap.add_argument("--bairro", default=None)
    # O DXF e um intermediario descartavel (gerado do DWG pelo accoreconsole),
    # entao o nome dele nao serve de procedencia. `--fonte` registra o DWG de
    # origem, que e o que alguem vai querer conferir daqui a um ano.
    ap.add_argument("--fonte", default=None)
    ap.add_argument("--dx", type=float, default=0.0)
    ap.add_argument("--dy", type=float, default=0.0)
    a = ap.parse_args()

    ds = ogr.Open(a.entrada)
    if ds is None:
        print("ERRO: nao abriu %s" % a.entrada)
        return 1
    lyr = ds.GetLayer(0)

    poligonos, rotulos = [], []
    quadras, rot_quadra = [], []
    descartadas = 0
    for f in lyr:
        cad = f.GetField("Layer") or ""
        g = f.GetGeometryRef()
        if g is None:
            continue

        if cad in (a.textos, a.textos_quadra):
            t = (f.GetField("Text") or "").strip()
            if t and g.GetGeometryType() in (ogr.wkbPoint, ogr.wkbPoint25D):
                item = (g.GetX(0) + a.dx, g.GetY(0) + a.dy, t)
                if cad == a.textos:
                    rotulos.append(item)
                if cad == a.textos_quadra:
                    rot_quadra.append(item)
            continue

        if cad not in (a.lotes, a.quadras):
            continue
        if g.GetGeometryType() not in (ogr.wkbLineString, ogr.wkbLineString25D):
            continue
        if not fechada(g):
            continue

        pol = para_poligono(g, a.dx, a.dy)
        if pol is None:
            descartadas += 1
            continue
        area = pol.GetArea()
        if cad == a.quadras and QUADRA_MIN <= area <= QUADRA_MAX:
            quadras.append(pol)
        if cad == a.lotes and AREA_MIN <= area <= AREA_MAX:
            poligonos.append((pol, area))

    print("poligonos de lote aceitos: %d" % len(poligonos))
    print("rotulos de numero de lote: %d" % len(rotulos))
    if descartadas:
        print("geometrias descartadas (degeneradas): %d" % descartadas)

    # ── numera as quadras (uma vez), para depois herdar nos lotes ──
    quadras_num = []
    for q in quadras:
        quadras_num.append((q, rotular(q, rot_quadra)))
    if quadras:
        comnum = sum(1 for _q, n in quadras_num if n)
        print("quadras: %d (com numero: %d)" % (len(quadras), comnum))

    casados = 0
    com_quadra = 0
    feats = []
    src = osr.SpatialReference(); src.ImportFromEPSG(a.epsg)
    src.SetAxisMappingStrategy(osr.OAMS_TRADITIONAL_GIS_ORDER)
    dst = osr.SpatialReference(); dst.ImportFromEPSG(4326)
    dst.SetAxisMappingStrategy(osr.OAMS_TRADITIONAL_GIS_ORDER)
    tr = osr.CoordinateTransformation(src, dst)

    for pol, area in poligonos:
        numero = rotular(pol, rotulos)
        if numero:
            casados += 1

        # Quadra do lote: preferencialmente a quadra que CONTEM o centroide.
        # Nem todo loteamento desenha a quadra como poligono (no Jardim Europa
        # o layer `QUADRAS` sao so arcos), entao ha o plano B: o rotulo de
        # quadra mais proximo, dentro de DIST_QUADRA_MAX. Com ~19 lotes por
        # quadra, o rotulo mais proximo e o da propria quadra em toda parte
        # menos, eventualmente, na divisa entre duas.
        quadra = None
        c = pol.Centroid()
        for q, qn in quadras_num:
            if q.Contains(c):
                quadra = qn
                break
        if quadra is None and rot_quadra:
            cx, cy = c.GetX(0), c.GetY(0)
            melhor, dmin = None, DIST_QUADRA_MAX
            for rx, ry, t in rot_quadra:
                if not RE_NUMERO.match(t):
                    continue
                dist = ((rx - cx) ** 2 + (ry - cy) ** 2) ** 0.5
                if dist < dmin:
                    melhor, dmin = t, dist
            quadra = melhor
        if quadra:
            com_quadra += 1

        g4326 = pol.Clone()
        g4326.Transform(tr)
        feats.append({
            "type": "Feature",
            "geometry": json.loads(g4326.ExportToJson()),
            "properties": {
                "bairro": a.bairro,
                "quadra": quadra,
                "numero_lote": numero,
                # Chave de integracao com o cadastro imobiliario. O DWG nao tem
                # inscricao imobiliaria (ver docs/etapa0-conclusoes.md, item 4),
                # entao a identidade do lote e a tripla bairro+quadra+lote.
                "chave": "|".join([a.bairro or "?", quadra or "?", numero or "?"]),
                "area_gis_m2": round(area, 2),
                "fonte": a.fonte or a.entrada.replace("\\", "/").split("/")[-1],
            },
        })

    n = len(poligonos) or 1
    print("lotes com numero identificado: %d (%.1f%%)" % (casados, 100.0 * casados / n))
    print("lotes com quadra identificada:  %d (%.1f%%)" % (com_quadra, 100.0 * com_quadra / n))
    chaves = [f["properties"]["chave"] for f in feats]
    print("chaves distintas: %d de %d %s" %
          (len(set(chaves)), len(chaves),
           "(OK — identidade unica)" if len(set(chaves)) == len(chaves) else "(ATENCAO: ha chave repetida)"))

    with open(a.saida, "w", encoding="utf-8") as fh:
        json.dump({"type": "FeatureCollection",
                   "name": a.bairro or "lotes",
                   "crs": {"type": "name",
                           "properties": {"name": "urn:ogc:def:crs:OGC:1.3:CRS84"}},
                   "features": feats}, fh, ensure_ascii=False)
    print("OK -> %s  (%d feicoes)" % (a.saida, len(feats)))
    return 0


if __name__ == "__main__":
    sys.exit(main())
