<?php

namespace App\Cadastro;

use RuntimeException;

/**
 * O JSON do app do cadastro chega COMPACTADO (gzip) pelo navegador.
 *
 * Na primeira carga pelo app o JSON leva o município inteiro — dezenas de MB,
 * acima do limite de envio do servidor (o 413). Compactado ele fica menor que
 * a própria planilha .xlsx, e quem anexa não percebe nada: a tela compacta
 * sozinha (ver public/js/cadastro-municipal.js).
 *
 * Descompacta em fluxo, com o mesmo teto do LeitorXlsx contra o "zip bomb":
 * poucos MB compactados podem se abrir em gigabytes.
 */
final class ArquivoCompactado
{
    public const TETO_DESCOMPACTADO = 200 * 1024 * 1024;

    private const BLOCO = 1024 * 1024;

    /** Descompacta `$origem` (gzip) em `$destino`; devolve os bytes escritos. */
    public static function descompactar(string $origem, string $destino, int $teto = self::TETO_DESCOMPACTADO): int
    {
        $entrada = fopen($origem, 'rb');
        $saida = fopen($destino, 'wb');
        if (! $entrada || ! $saida) {
            throw new RuntimeException('Não foi possível ler o arquivo compactado.');
        }

        try {
            if (fread($entrada, 2) !== "\x1f\x8b") {
                throw new RuntimeException('O arquivo compactado está corrompido.');
            }
            rewind($entrada);

            $z = inflate_init(ZLIB_ENCODING_GZIP);
            $escritos = 0;
            while (! feof($entrada)) {
                $pedaco = fread($entrada, self::BLOCO);
                $texto = @inflate_add($z, (string) $pedaco, ZLIB_SYNC_FLUSH);
                if ($texto === false) {
                    throw new RuntimeException('O arquivo compactado está corrompido.');
                }
                $escritos += strlen($texto);
                if ($escritos > $teto) {
                    throw new RuntimeException('O arquivo descompactado passa de ' . intdiv($teto, 1048576) . ' MB.');
                }
                fwrite($saida, $texto);
                if (inflate_get_status($z) === ZLIB_STREAM_END) {
                    break;
                }
            }
            if (inflate_get_status($z) !== ZLIB_STREAM_END) {
                throw new RuntimeException('O arquivo compactado está corrompido (incompleto).');
            }

            return $escritos;
        } finally {
            fclose($entrada);
            fclose($saida);
        }
    }
}
