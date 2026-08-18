# -*- coding: utf-8 -*-
"""
Etapa 1 — Resolve a transformacao local -> UTM por pares de pontos homologos.

Ideia: um loteamento desenhado em coordenadas UTM verdadeiras e o MESMO
loteamento presente no mapa da cidade (em coordenadas locais) tem feicoes
identicas. Se acharmos pares de pontos correspondentes nos dois arquivos,
resolvemos a transformacao de Helmert (translacao + rotacao + escala) por
minimos quadrados e aplicamos ao mapa inteiro.

Os pares sao achados automaticamente pelos ROTULOS DE TEXTO: um nome de rua que
aparece exatamente uma vez em cada arquivo identifica o mesmo ponto de insercao
nos dois — sem clique manual.

Modelo (Helmert / similaridade):
    X = a*u - b*v + c
    Y = b*u + a*v + d
com escala = sqrt(a^2+b^2) e rotacao = atan2(b, a). A escala e resolvida junto
e depois CONFERIDA: se vier ~1.0000, confirma que o desenho local ja esta em
metros reais (era a hipotese da Etapa 0).

Robustez: resolve, mede residuo por par, descarta os piores (rotulos que nao
sao realmente o mesmo ponto) e resolve de novo, ate estabilizar.

Uso:
    python-qgis.bat resolver_transformacao.py <cidade.dxf> <georreferenciado.dxf>
Somente leitura — nao grava nada; imprime a matriz e os residuos.
"""
import sys
from collections import defaultdict

import numpy as np
from osgeo import ogr, gdal

gdal.UseExceptions(); ogr.UseExceptions()
gdal.PushErrorHandler("CPLQuietErrorHandler")

UTM_X = (100000.0, 1000000.0)
UTM_Y = (7000000.0, 9500000.0)
MIN_LEN = 4          # ignora rotulos curtos demais ("1", "AV")
RESIDUO_CORTE = 3.0  # descarta par cujo residuo passe de 3x a mediana


def coletar(path, so_local=False, so_utm=False):
    """nome do texto -> lista de pontos de insercao"""
    ds = ogr.Open(path)
    lyr = ds.GetLayer(0)
    pts = defaultdict(list)
    for f in lyr:
        t = f.GetField("Text")
        if not t:
            continue
        t = " ".join(t.split()).strip().upper()
        if len(t) < MIN_LEN:
            continue
        g = f.GetGeometryRef()
        if g is None or g.GetGeometryType() not in (ogr.wkbPoint, ogr.wkbPoint25D):
            continue
        x, y = g.GetX(0), g.GetY(0)
        em_utm = UTM_X[0] <= x <= UTM_X[1] and UTM_Y[0] <= y <= UTM_Y[1]
        if so_local and em_utm:
            continue
        if so_utm and not em_utm:
            continue
        pts[t].append((x, y))
    return pts


def helmert(P, Q):
    """Resolve Q = R(P) por minimos quadrados. P e Q: (n,2). Retorna a,b,c,d."""
    n = len(P)
    A = np.zeros((2 * n, 4))
    L = np.zeros(2 * n)
    for i, ((u, v), (X, Y)) in enumerate(zip(P, Q)):
        A[2 * i] = [u, -v, 1, 0]
        L[2 * i] = X
        A[2 * i + 1] = [v, u, 0, 1]
        L[2 * i + 1] = Y
    sol, *_ = np.linalg.lstsq(A, L, rcond=None)
    return sol  # a, b, c, d


def aplicar(par, pts):
    a, b, c, d = par
    P = np.asarray(pts)
    return np.column_stack([a * P[:, 0] - b * P[:, 1] + c,
                            b * P[:, 0] + a * P[:, 1] + d])


def main(cidade, geo):
    loc = coletar(cidade, so_local=True)
    utm = coletar(geo, so_utm=True)

    # pares confiaveis: rotulo que aparece UMA vez em cada arquivo
    pares = [(n, loc[n][0], utm[n][0])
             for n in set(loc) & set(utm)
             if len(loc[n]) == 1 and len(utm[n]) == 1]

    print("Rotulos no mapa da cidade (locais): %d" % len(loc))
    print("Rotulos no arquivo georreferenciado: %d" % len(utm))
    print("Rotulos em comum: %d" % len(set(loc) & set(utm)))
    print("Pares univocos utilizaveis: %d\n" % len(pares))
    if len(pares) < 2:
        print("Pares insuficientes (minimo 2). Sera preciso marcar pontos manualmente.")
        return 1

    usados = pares[:]
    for rodada in range(1, 6):
        P = [p[1] for p in usados]
        Q = [p[2] for p in usados]
        par = helmert(P, Q)
        prev = aplicar(par, P)
        res = np.hypot(prev[:, 0] - np.array(Q)[:, 0], prev[:, 1] - np.array(Q)[:, 1])
        med = float(np.median(res))
        a, b, c, d = par
        escala = float(np.hypot(a, b))
        rot = float(np.degrees(np.arctan2(b, a)))
        print("--- rodada %d · %d pares ---" % (rodada, len(usados)))
        print("escala: %.8f   rotacao: %.6f deg" % (escala, rot))
        print("translacao: dX=%.3f  dY=%.3f" % (c, d))
        print("residuo  mediano: %.3f m   maximo: %.3f m   RMS: %.3f m"
              % (med, res.max(), float(np.sqrt((res ** 2).mean()))))
        corte = max(med * RESIDUO_CORTE, 0.05)
        manter = [u for u, r in zip(usados, res) if r <= corte]
        if len(manter) == len(usados) or len(manter) < 2:
            print("\nPares finais e residuo individual:")
            for (nome, _p, _q), r in sorted(zip(usados, res), key=lambda z: z[1]):
                print("  %-42s %8.3f m" % (nome[:42], r))
            print("\n=== TRANSFORMACAO local -> UTM ===")
            print("X = %.10f*u + %.10f*v + %.4f" % (a, -b, c))
            print("Y = %.10f*u + %.10f*v + %.4f" % (b, a, d))
            print("\nPara o QGIS/GDAL (ordem de gdal_edit -a_ullr nao se aplica;")
            print("use como transformacao afim em 'Afinar/Georreferenciar'):")
            print("  a=%.10f  b=%.10f  c=%.4f  d=%.4f" % (a, b, c, d))
            return 0
        print("descartando %d par(es) com residuo > %.3f m\n" % (len(usados) - len(manter), corte))
        usados = manter
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1], sys.argv[2]))
