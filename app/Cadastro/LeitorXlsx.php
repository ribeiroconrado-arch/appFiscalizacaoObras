<?php

namespace App\Cadastro;

use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Leitor de planilha .xlsx, sem dependência externa.
 *
 * ── Por que não uma biblioteca ──
 *
 * O .xlsx é um ZIP com XML dentro, e o PHP já traz ZipArchive e XMLReader. Para
 * LER uma exportação de cadastro — texto e número em células simples — isso é o
 * bastante. Trazer PhpSpreadsheet custaria ~30 MB de vendor e um vetor de
 * atualização a mais, para usar 2% dela.
 *
 * ── Por que XMLReader e não SimpleXML ──
 *
 * A exportação do município inteiro tem dezenas de milhares de linhas. SimpleXML
 * carrega o documento inteiro na memória; XMLReader percorre em fluxo. Este
 * leitor devolve um Generator, então uma planilha de 200 MB atravessa o
 * importador sem estourar o limite de memória do PHP.
 *
 * Limites conhecidos e aceitos: lê a primeira aba, ignora fórmulas (usa o valor
 * calculado que o Excel gravou) e devolve tudo como string — quem sabe o que é
 * número é o importador, que conhece a coluna.
 */
class LeitorXlsx
{
    /** @var list<string> */
    private array $textos = [];

    private ZipArchive $zip;

    public function __construct(private string $arquivo)
    {
        if (! is_file($arquivo)) {
            throw new RuntimeException("Planilha não encontrada: {$arquivo}");
        }

        $this->zip = new ZipArchive();
        if ($this->zip->open($arquivo) !== true) {
            // Acontece de verdade: exportação de sistema legado costuma sair com
            // extensão .xls e conteúdo .xlsx — ou o contrário, e aí não abre.
            throw new RuntimeException(
                "Não consegui abrir {$arquivo} como .xlsx. Se for .xls antigo (formato "
                . 'binário do Excel 97), reabra no Excel e salve como .xlsx.'
            );
        }

        $this->carregarTextos();
    }

    public function __destruct()
    {
        @$this->zip->close();
    }

    /**
     * Percorre as linhas da primeira aba.
     *
     * @return \Generator<int, list<string>> índice da linha (1 = primeira) => células
     */
    public function linhas(): \Generator
    {
        $xml = $this->zip->getFromName($this->caminhoDaPrimeiraAba());
        if ($xml === false) {
            throw new RuntimeException('A planilha não tem aba legível.');
        }

        $leitor = new XMLReader();
        $leitor->XML($xml);

        $numero = 0;
        while ($leitor->read()) {
            if ($leitor->nodeType !== XMLReader::ELEMENT || $leitor->name !== 'row') {
                continue;
            }

            $numero = (int) ($leitor->getAttribute('r') ?: $numero + 1);
            yield $numero => $this->celulasDaLinha($leitor->readOuterXml());
        }

        $leitor->close();
    }

    /**
     * Células de uma linha, já indexadas por posição (0 = coluna A).
     *
     * A posição vem da REFERÊNCIA da célula (A1, C1…), e não da ordem em que
     * elas aparecem: o Excel omite células vazias, e contar por ordem
     * deslocaria a linha inteira uma coluna para a esquerda a cada vazio.
     *
     * @return list<string>
     */
    private function celulasDaLinha(string $xmlDaLinha): array
    {
        $linha = @simplexml_load_string($xmlDaLinha);
        if ($linha === false) {
            return [];
        }

        $saida = [];
        foreach ($linha->c as $c) {
            $ref = (string) $c['r'];
            $i = $this->indiceDaColuna($ref);
            $tipo = (string) $c['t'];

            if ($tipo === 's') {
                $saida[$i] = $this->textos[(int) $c->v] ?? '';
            } elseif ($tipo === 'inlineStr') {
                $saida[$i] = trim((string) $c->is->t);
            } else {
                $saida[$i] = trim((string) $c->v);
            }
        }

        if (! $saida) {
            return [];
        }

        // Preenche os buracos: quem consome espera uma lista contínua.
        $max = max(array_keys($saida));
        $lista = [];
        for ($i = 0; $i <= $max; $i++) {
            $lista[] = $saida[$i] ?? '';
        }

        return $lista;
    }

    /** "BC12" => 54. Base 26 com letras, que é como o Excel numera colunas. */
    private function indiceDaColuna(string $ref): int
    {
        $letras = rtrim($ref, '0123456789');
        $n = 0;
        foreach (str_split($letras) as $l) {
            $n = $n * 26 + (ord(strtoupper($l)) - 64);
        }

        return max(0, $n - 1);
    }

    /**
     * Tabela de textos compartilhados.
     *
     * O Excel não repete a mesma palavra em cada célula: guarda uma vez em
     * sharedStrings.xml e a célula aponta para o índice. Sem carregar isto, uma
     * planilha de texto sai inteira em branco.
     */
    private function carregarTextos(): void
    {
        $xml = $this->zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return;   // planilha só de números — existe, e é válida
        }

        $sst = @simplexml_load_string($xml);
        if ($sst === false) {
            return;
        }

        foreach ($sst->si as $si) {
            // Texto com formatação vem partido em vários <r><t>; juntar os
            // pedaços é o que devolve a palavra inteira.
            $this->textos[] = isset($si->t)
                ? (string) $si->t
                : implode('', array_map(fn ($r) => (string) $r->t, iterator_to_array($si->r ?? [])));
        }
    }

    /** Caminho interno da primeira aba, seguindo o relacionamento do workbook. */
    private function caminhoDaPrimeiraAba(): string
    {
        $wb = @simplexml_load_string((string) $this->zip->getFromName('xl/workbook.xml'));
        $rels = @simplexml_load_string((string) $this->zip->getFromName('xl/_rels/workbook.xml.rels'));

        if ($wb !== false && $rels !== false) {
            $id = (string) $wb->sheets->sheet[0]->attributes('r', true)->id;
            foreach ($rels->Relationship as $rel) {
                if ((string) $rel['Id'] === $id) {
                    return 'xl/' . ltrim((string) $rel['Target'], '/');
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';   // o caminho de sempre, quando o rel falha
    }
}
