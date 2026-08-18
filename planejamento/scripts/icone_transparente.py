# -*- coding: utf-8 -*-
"""
Remove o fundo branco do ícone do aplicativo e gera os tamanhos usados.

Motivo: o PNG do ícone vem com o quadrado âmbar sobre um fundo BRANCO. Sobre o
gradiente do login isso desenha um quadrado claro em volta do ícone. Tornando o
branco transparente, sobra só a forma âmbar.

Como a forma tem cantos arredondados, não basta trocar branco por transparente:
os pixels da borda são uma MISTURA de branco e laranja (antialiasing). Trocar
só o branco puro deixaria uma franja clara serrilhada. Por isso o script usa
transparência PROPORCIONAL: quanto mais perto do branco, mais transparente —
o que preserva a suavidade da curva.

Uso:
    "C:\\Program Files\\QGIS 4.2.1\\bin\\python-qgis.bat" icone_transparente.py <entrada.png> [saida.png]

Sem argumentos, procura `icone-original.png` na mesma pasta.
"""
import os
import sys

from PIL import Image

# Tamanhos gerados. 512 para o login, os menores para favicon e ícone de PWA
# (Etapa 8), que vão precisar deles de qualquer forma.
TAMANHOS = [512, 192, 180, 64, 32]

# Acima deste valor por canal o pixel é considerado "fundo". 235 e não 255
# porque JPEG/PNG exportado de editor costuma ter branco levemente sujo.
LIMIAR = 235


def remover_fundo(img: Image.Image) -> Image.Image:
    """Torna o branco transparente, proporcionalmente à sua clareza."""
    img = img.convert('RGBA')
    px = img.load()
    larg, alt = img.size

    for y in range(alt):
        for x in range(larg):
            r, g, b, a = px[x, y]
            if a == 0:
                continue
            # Só mexe em pixels acinzentados/brancos: se houver cor (saturação),
            # é a marca, não o fundo.
            if max(r, g, b) - min(r, g, b) > 22:
                continue
            claro = min(r, g, b)
            if claro >= 250:
                px[x, y] = (r, g, b, 0)
            elif claro >= LIMIAR:
                # Faixa de transição: alfa proporcional, para não serrilhar.
                fator = (250 - claro) / (250 - LIMIAR)
                px[x, y] = (r, g, b, int(a * fator))

    return img


def recortar(img: Image.Image) -> Image.Image:
    """Corta a moldura transparente que sobra em volta da marca."""
    caixa = img.getbbox()
    return img.crop(caixa) if caixa else img


def main() -> int:
    aqui = os.path.dirname(os.path.abspath(__file__))
    entrada = sys.argv[1] if len(sys.argv) > 1 else os.path.join(aqui, 'icone-original.png')

    if not os.path.isfile(entrada):
        print('Arquivo não encontrado: %s' % entrada)
        print('Salve o PNG oficial nesse caminho, ou passe o caminho como argumento.')
        return 1

    destino = sys.argv[2] if len(sys.argv) > 2 else os.path.join(aqui, 'icone.png')

    img = Image.open(entrada)
    print('entrada: %s  %dx%d  modo %s' % (os.path.basename(entrada), img.width, img.height, img.mode))

    img = recortar(remover_fundo(img))
    print('após remover fundo e recortar: %dx%d' % (img.width, img.height))

    img.save(destino)
    print('gravado: %s' % destino)

    base, _ = os.path.splitext(destino)
    for t in TAMANHOS:
        # LANCZOS preserva a curva do canto arredondado ao reduzir.
        img.resize((t, t), Image.LANCZOS).save('%s-%d.png' % (base, t))
        print('  %s-%d.png' % (os.path.basename(base), t))

    print()
    print('Copiar para a aplicação:')
    print(r'  copy icone-512.png C:\Users\Avell_A52ION\Herd\fiscalizacao-obras\public\img\icone.png')
    return 0


if __name__ == '__main__':
    sys.exit(main())
