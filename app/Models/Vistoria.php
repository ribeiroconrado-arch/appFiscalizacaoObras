<?php

namespace App\Models;

use App\Models\Concerns\RegistraAuditoria;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vistoria extends Model
{
    use RegistraAuditoria;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            // Sem cast para datetime com fuso: o valor é o horário LOCAL que o
            // fiscal viu na tela, gravado como está. Converter aqui reintroduz
            // o deslocamento de dia que a migration explica.
            'data_hora' => 'datetime',
            'latitude'  => 'float',
            'longitude' => 'float',
            'accuracy'  => 'float',
            'area_construida_aferida_m2' => 'float',
            'ano_construcao_estimado'    => 'integer',
        ];
    }

    /**
     * A FINALIDADE decide o que a vistoria pergunta.
     *
     * Este mapa é a fonte única dessa regra: a tela monta os passos a partir
     * dele, e a gravação descarta o que não pertence à finalidade escolhida.
     * Duas listas separadas — uma no JavaScript, outra no controller — é como
     * um campo passa a ser oferecido na tela e ignorado no servidor (ou o
     * contrário), e ninguém descobre até faltar dado numa peça.
     *
     * `campos` diz quais blocos o segundo passo mostra. Finalidade sem campo
     * nenhum não tem segundo passo: o auto de constatação registra o que se
     * vê, e não mede nada — inventar um passo vazio para ele seria manter a
     * forma da fiscalização de obra onde ela não faz sentido.
     */
    public const FINALIDADES = [
        'obras' => [
            'rotulo' => 'Fiscalização de obras',
            'obs'    => 'Obra em andamento: alvará, área e fase.',
            'passo'  => 'A obra',
            'campos' => ['alvara', 'area', 'fase'],
        ],
        'cadastral' => [
            'rotulo' => 'Atualização cadastral',
            'obs'    => 'Conferir em campo os dados do imóvel no cadastro.',
            'passo'  => 'O imóvel',
            'campos' => ['area', 'uso', 'ano'],
        ],
        'habite_se' => [
            'rotulo' => 'Habite-se',
            'obs'    => 'Obra concluída: confere com o projeto aprovado?',
            'passo'  => 'A conclusão',
            'campos' => ['alvara', 'area', 'projeto', 'fase'],
        ],
        'regularizacao' => [
            'rotulo' => 'Regularização de imóvel pronto',
            'obs'    => 'Construção que já existe, sem alvará.',
            'passo'  => 'A construção',
            'campos' => ['alvara', 'area', 'ano', 'uso', 'projeto'],
        ],
        'constatacao' => [
            'rotulo' => 'Auto de constatação',
            'obs'    => 'Só registrar o que se vê. Sem medição.',
            'passo'  => null,
            'campos' => [],
        ],
    ];

    /** Cada bloco do segundo passo e as colunas que ele preenche. */
    public const CAMPOS_POR_BLOCO = [
        'alvara'  => ['alvara_situacao', 'alvara_numero'],
        'area'    => ['area_construida_aferida_m2', 'area_metodo'],
        'fase'    => ['fase_obra'],
        'projeto' => ['conforme_projeto'],
        'ano'     => ['ano_construcao_estimado'],
        'uso'     => ['uso_constatado'],
    ];

    /**
     * "Sem projeto" é resposta legítima, e diferente de "não confere": é
     * justamente o caso da regularização de imóvel pronto.
     */
    public const CONFORMIDADES = [
        'sim'            => 'Confere com o projeto',
        'nao'            => 'Diverge do projeto',
        'sem_projeto'    => 'Não há projeto aprovado',
        'nao_verificado' => 'Não verificado',
    ];

    /** O uso REAL constatado, que costuma divergir do declarado no cadastro. */
    public const USOS = [
        'residencial'   => 'Residencial',
        'comercial'     => 'Comercial',
        'industrial'    => 'Industrial',
        'misto'         => 'Misto',
        'institucional' => 'Institucional',
        'religioso'     => 'Religioso',
        'vago'          => 'Terreno vago',
    ];

    /**
     * Como a área foi obtida. Vai IMPRESSO junto do número, sempre.
     *
     * Perito que contesta multa por metro quadrado contesta a medição, e
     * "estimativa visual" precisa aparecer como o que é — não como um número
     * seco que finge precisão de trena.
     */
    public const METODOS_AREA = [
        'trena'      => 'Medida com trena',
        'estimativa' => 'Estimativa visual',
        'projeto'    => 'Conforme projeto apresentado',
        'croqui'     => 'Calculada pelo croqui',
    ];

    /**
     * "Não verificado" é estado legítimo, e diferente de "não possui": o fiscal
     * pode não ter conseguido conferir. Obrigar a escolher entre os outros dois
     * produziria afirmação falsa dentro de peça de processo.
     */
    public const ALVARA = [
        'possui'         => 'Possui alvará',
        'nao_possui'     => 'Não possui alvará',
        'nao_verificado' => 'Não verificado',
    ];

    public const FASES_OBRA = [
        'fundacao'   => 'Fundação',
        'alvenaria'  => 'Alvenaria',
        'cobertura'  => 'Cobertura',
        'acabamento' => 'Acabamento',
        'concluida'  => 'Concluída',
        'parada'     => 'Parada',
    ];

    public const QUALIFICACOES = [
        'proprietario' => 'Proprietário',
        'responsavel'  => 'Responsável pela obra',
        'encarregado'  => 'Encarregado / pedreiro',
        'inquilino'    => 'Inquilino',
        'outro'        => 'Outro',
        'ninguem'      => 'Ninguém no local',
    ];

    public const SITUACOES = [
        'regular'           => 'Regular',
        'irregular'         => 'Irregular',
        'em_acompanhamento' => 'Em acompanhamento',
        'nao_localizado'    => 'Não localizado',
    ];

    public function lote(): BelongsTo          { return $this->belongsTo(Lote::class); }
    public function obra(): BelongsTo          { return $this->belongsTo(Obra::class); }
    public function fiscal(): BelongsTo        { return $this->belongsTo(User::class, 'fiscal_id'); }
    /** As fotos, na ordem do relatório — e não na de chegada ao servidor. */
    public function evidencias(): HasMany
    {
        return $this->hasMany(Evidencia::class)->orderBy('ordem')->orderBy('id');
    }

    /** As providências exigidas, na ordem em que o fiscal as escreveu. */
    public function exigencias(): HasMany
    {
        return $this->hasMany(VistoriaExigencia::class)->orderBy('ordem')->orderBy('id');
    }

    /**
     * Enquadramento legal CONSTATADO em campo.
     *
     * Tabela própria, e não a do documento: aqui é o que o fiscal viu e citou;
     * lá é o que foi efetivamente lavrado. Os dois podem divergir, inclusive
     * porque o auto costuma ser lavrado dias depois, às vezes por outra pessoa.
     */
    public function artigos(): BelongsToMany
    {
        return $this->belongsToMany(Artigo::class, 'vistoria_artigos');
    }

    /**
     * Os itens de lei do relatório — citações e pareceres, na ordem escrita.
     *
     * Convive com `artigos()` acima de propósito: aquela responde "quais
     * dispositivos esta vistoria envolve?", que é o que a lavratura pergunta;
     * esta responde "o que o fiscal escreveu sobre cada um?", que é o que o
     * relatório mostra. Uma é conjunto, a outra é texto.
     */
    public function itensDeArtigo(): HasMany
    {
        return $this->hasMany(VistoriaArtigo::class)->orderBy('ordem')->orderBy('id');
    }

    /**
     * O relatório inteiro em UMA sequência: fotos e itens de lei intercalados,
     * na ordem em que o fiscal montou.
     *
     * A ordem é o conteúdo. Uma foto depois do artigo que ela ilustra diz algo
     * que a mesma foto no fim de uma lista de fotos não diz.
     *
     * @return \Illuminate\Support\Collection<int, array>
     */
    public function relatorio()
    {
        $fotos = $this->evidencias->map(fn (Evidencia $e) => [
            'tipo' => 'foto', 'ordem' => (int) $e->ordem, 'id' => $e->id,
            'titulo' => $e->titulo, 'texto' => $e->descricao,
            'fachada' => (bool) $e->fachada, 'marcacoes' => $e->marcacoes ?? [],
        ]);

        $leis = $this->itensDeArtigo->map(fn (VistoriaArtigo $i) => [
            'tipo' => $i->tipo, 'ordem' => (int) $i->ordem, 'id' => $i->id,
            'artigo_id' => $i->artigo_id, 'titulo' => $i->artigo?->numero,
            'texto' => $i->observacao,
        ]);

        return $fotos->concat($leis)->sortBy(['ordem', 'tipo'])->values();
    }

    /** A foto que responde "como está o imóvel hoje". */
    public function fachada(): ?Evidencia
    {
        return $this->evidencias()->where('fachada', true)->first();
    }

    /** Os blocos que o segundo passo mostra nesta finalidade. */
    public function camposDaFinalidade(): array
    {
        return self::FINALIDADES[$this->finalidade]['campos'] ?? [];
    }

    /**
     * As colunas que NÃO pertencem a esta finalidade.
     *
     * Usada na gravação para zerar o que veio sobrando. Campo fora da
     * finalidade é dado que ninguém conferiu em campo — e num processo
     * administrativo, dado não conferido que parece conferido é pior do que
     * campo vazio.
     *
     * @param string $finalidade
     * @return array<int, string>
     */
    public static function colunasForaDa(string $finalidade): array
    {
        $blocos = self::FINALIDADES[$finalidade]['campos'] ?? [];
        $fora = [];

        foreach (self::CAMPOS_POR_BLOCO as $bloco => $colunas) {
            if (! in_array($bloco, $blocos, true)) {
                $fora = array_merge($fora, $colunas);
            }
        }

        return $fora;
    }

    public function finalidadeRotulo(): string
    {
        return self::FINALIDADES[$this->finalidade]['rotulo'] ?? 'Vistoria';
    }

    /** "88,00 m² (medida com trena)" — o número nunca anda sem o método. */
    public function areaAferidaRotulo(): ?string
    {
        if ($this->area_construida_aferida_m2 === null) {
            return null;
        }

        $metodo = self::METODOS_AREA[$this->area_metodo] ?? null;

        return number_format((float) $this->area_construida_aferida_m2, 2, ',', '.') . ' m²'
            . ($metodo ? ' (' . mb_strtolower($metodo) . ')' : '');
    }

    public function irregularidades(): BelongsToMany
    {
        return $this->belongsToMany(Irregularidade::class, 'vistoria_irregularidades')
                    ->withPivot('observacao')
                    ->withTimestamps();
    }

    /** Rótulo da situação para exibição. */
    public function situacaoRotulo(): string
    {
        return self::SITUACOES[$this->situacao] ?? $this->situacao;
    }

    /**
     * Classe da tag "Modelo D" correspondente à situação.
     * Mantida no modelo, e não na view, para que dashboard, lista e ficha
     * pintem o mesmo status da mesma cor sem repetir o mapeamento.
     */
    public function situacaoBadge(): string
    {
        return match ($this->situacao) {
            'regular'           => 'bd-ok',
            'irregular'         => 'bd-in',
            'em_acompanhamento' => 'bd-pe',
            default             => 'bd-pe',
        };
    }
}
