<?php

namespace App\Services;

/**
 * Apara a assinatura até o traço.
 *
 * O canvas onde se assina tem a largura da tela e uns 250px de altura; a
 * pessoa assina num pedaço dele. O PNG que sai carrega toda a margem vazia
 * junto — e, no papel, o campo de assinatura fica com uma rubrica minúscula
 * boiando no meio, porque quem manda no tamanho exibido é a moldura da
 * imagem, e não o traço dentro dela.
 *
 * Aparar resolve na origem: guardada só a caixa do traço, qualquer lugar que
 * exiba a assinatura passa a mostrá-la no maior tamanho que couber, sem
 * ninguém precisar adivinhar quanto de margem havia.
 *
 * O corte é feito no SERVIDOR de propósito. Poderia sair pronto do navegador,
 * mas aí dependeria de a tela ter feito a sua parte — e a assinatura chega
 * por uma rota, não por uma tela. Aqui é o ponto por onde toda assinatura
 * passa, venha de onde vier.
 */
class Assinatura
{
    /** Quanto de respiro sobra em volta do traço, em pixels do original. */
    private const MARGEM = 8;

    /**
     * Um pixel só conta como traço se for opaco o bastante E escuro o
     * bastante. O primeiro teste sozinho deixaria passar o anti-serrilhado
     * quase transparente das bordas; o segundo sozinho contaria o fundo
     * branco de uma assinatura que venha achatada, sem canal alfa.
     */
    private const ALFA_MINIMO = 40;      // 0 = opaco, 127 = transparente (GD)
    private const CLARO_DEMAIS = 235;    // média RGB acima disto é fundo

    /** Teto da imagem guardada: mais que isto é peso sem ganho visível. */
    private const LARGURA_MAXIMA = 1200;

    /**
     * @param  string $dataUrl PNG em data URL, como o canvas produz
     * @return string a mesma assinatura, aparada — ou a original, se não der
     */
    public function aparar(string $dataUrl): string
    {
        // Falhar aqui não pode custar a assinatura: se a imagem não for legível
        // ou o corte não fizer sentido, devolve-se o que veio. Uma assinatura
        // com margem sobrando é pior do que uma aparada, e melhor do que
        // nenhuma.
        if (! function_exists('imagecreatefromstring')) { return $dataUrl; }

        $bruto = $this->bytesDe($dataUrl);
        if ($bruto === null) { return $dataUrl; }

        $img = @imagecreatefromstring($bruto);
        if (! $img) { return $dataUrl; }

        $caixa = $this->caixaDoTraco($img);
        if ($caixa === null) { imagedestroy($img); return $dataUrl; }

        [$x1, $y1, $x2, $y2] = $caixa;
        $larg = $x2 - $x1 + 1;
        $alt  = $y2 - $y1 + 1;

        $cortada = imagecreatetruecolor($larg, $alt);
        imagealphablending($cortada, false);
        imagesavealpha($cortada, true);
        imagefill($cortada, 0, 0, imagecolorallocatealpha($cortada, 255, 255, 255, 127));
        imagecopy($cortada, $img, 0, 0, $x1, $y1, $larg, $alt);
        imagedestroy($img);

        if ($larg > self::LARGURA_MAXIMA) {
            $reduzida = imagescale($cortada, self::LARGURA_MAXIMA);
            if ($reduzida) {
                imagedestroy($cortada);
                $cortada = $reduzida;
                imagesavealpha($cortada, true);
            }
        }

        ob_start();
        imagepng($cortada, null, 8);
        $png = ob_get_clean();
        imagedestroy($cortada);

        return 'data:image/png;base64,' . base64_encode($png);
    }

    /**
     * A caixa que contém o traço, já com a margem de respiro.
     *
     * @return array{0:int,1:int,2:int,3:int}|null nulo quando não há traço
     */
    private function caixaDoTraco(\GdImage $img): ?array
    {
        $larg = imagesx($img);
        $alt  = imagesy($img);

        $x1 = $larg; $y1 = $alt; $x2 = -1; $y2 = -1;

        for ($y = 0; $y < $alt; $y++) {
            for ($x = 0; $x < $larg; $x++) {
                $c = imagecolorat($img, $x, $y);
                $a = ($c >> 24) & 0x7F;
                if ($a > self::ALFA_MINIMO) { continue; }

                $media = ((($c >> 16) & 0xFF) + (($c >> 8) & 0xFF) + ($c & 0xFF)) / 3;
                if ($media > self::CLARO_DEMAIS) { continue; }

                if ($x < $x1) { $x1 = $x; }
                if ($x > $x2) { $x2 = $x; }
                if ($y < $y1) { $y1 = $y; }
                if ($y > $y2) { $y2 = $y; }
            }
        }

        // Sem traço nenhum não há o que aparar — e cortar mesmo assim
        // devolveria uma imagem de tamanho zero.
        if ($x2 < 0) { return null; }

        return [
            max(0, $x1 - self::MARGEM),
            max(0, $y1 - self::MARGEM),
            min($larg - 1, $x2 + self::MARGEM),
            min($alt - 1, $y2 + self::MARGEM),
        ];
    }

    /** @return string|null os bytes do PNG, ou nulo se o data URL não servir */
    private function bytesDe(string $dataUrl): ?string
    {
        if (! preg_match('#^data:image/(png|jpe?g);base64,#i', $dataUrl)) { return null; }

        $bruto = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);

        return $bruto === false || $bruto === '' ? null : $bruto;
    }
}
