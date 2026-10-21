<?php

namespace App\Services;

use App\Models\Parametro;
use Illuminate\Support\Facades\Storage;

/**
 * O cabeçalho institucional das peças impressas — de onde quer que elas saiam.
 *
 * Nasceu dentro de DocumentoImpressao, porque só o documento imprimia. Quando
 * a ordem de serviço passou a imprimir também, copiar estas vinte linhas para
 * lá criaria dois cabeçalhos que envelhecem separados: mudar o nome da
 * secretaria em Parâmetros passaria a corrigir um papel e não o outro.
 *
 * Nada aqui é fixo no código: tudo vem de Parâmetros, porque outra prefeitura
 * — ou a mesma, depois de uma reforma administrativa — muda o cabeçalho sem
 * tocar em arquivo nenhum.
 */
class CabecalhoOficial
{
    /**
     * @param string $modulo de qual fiscalização é a peça: o selo e a divisão
     *                       podem ser próprios do módulo (Parametro::doModulo).
     */
    public function orgao(string $modulo = 'obras'): array
    {
        $p = fn (string $chave) => Parametro::doModulo($chave, $modulo);

        return [
            'nome'         => $p('orgao_nome'),
            'secretaria'   => $p('orgao_secretaria'),
            'departamento' => $p('orgao_departamento'),
            'divisao'      => $p('orgao_divisao'),
            'municipio'    => $p('orgao_municipio'),
            'endereco'     => $p('orgao_endereco'),
            'telefone'     => $p('orgao_telefone'),
            'cnpj'         => $p('orgao_cnpj'),
            'selo'         => $p('impressao_selo'),
        ];
    }

    /**
     * Brasão do município. Ausente, o cabeçalho fecha sem ele — travar a
     * emissão de uma peça porque falta uma imagem seria pior do que emiti-la
     * sem o símbolo.
     *
     * O dompdf não segue URL: recebe o caminho do arquivo no disco.
     */
    public function brasao(bool $paraPdf): ?string
    {
        $url = Parametro::get('brasao_url');
        if (! $url) {
            return null;
        }

        // Uploads de Parâmetros → Formulários ficam no disco público.
        // O PDF recebe a imagem embutida, sem depender de URL, sessão ou symlink.
        $nome = basename(parse_url($url, PHP_URL_PATH) ?: '');
        $disco = Storage::disk('public');
        $arquivo = 'orgao/' . $nome;
        if (! $nome || ! $disco->exists($arquivo)) {
            return null;
        }

        return $paraPdf
            ? 'data:' . ($disco->mimeType($arquivo) ?: 'image/png') . ';base64,' . base64_encode($disco->get($arquivo))
            : $url;
    }

    /** @return array<int, string> */
    public function rodape(): array
    {
        return array_values(array_filter([
            Parametro::get('rodape_protocolo'),
            Parametro::get('rodape_ouvidoria'),
        ]));
    }
}
