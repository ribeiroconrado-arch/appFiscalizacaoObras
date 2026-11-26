<?php

namespace App\Models\Vg;

use App\Models\Artigo;
use App\Models\Documento;
use App\Models\Lote;
use App\Models\Protocolo;
use App\Models\Sinalizacao;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Uma vistoria guiada — desde 26/11/2026, A vistoria do sistema.
 *
 * O que o resto do sistema lê dela sai daqui, e não da tela: a área da multa,
 * os artigos citados, as exigências, as fotos e a situação. Assim a peça, o
 * histórico do lote, o painel e o protocolo cadastral leem a mesma coisa.
 */
class VgVistoria extends Model
{
    protected $table = 'vg_vistorias';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'respostas' => 'array', 'iniciada_em' => 'datetime', 'concluida_em' => 'datetime',
            'gps_lat' => 'float', 'gps_lng' => 'float', 'mapa_lat' => 'float', 'mapa_lng' => 'float', 'gps_precisao' => 'integer',
        ];
    }

    public function versao(): BelongsTo   { return $this->belongsTo(VgModeloVersao::class, 'versao_id'); }
    public function autor(): BelongsTo    { return $this->belongsTo(User::class, 'user_id'); }
    public function arquivos(): HasMany   { return $this->hasMany(VgArquivo::class, 'vistoria_id'); }
    public function livres(): HasMany     { return $this->hasMany(VgLivre::class, 'vistoria_id'); }
    public function vinculos(): HasMany   { return $this->hasMany(VgVinculo::class, 'vistoria_id'); }
    public function fiscais(): HasMany    { return $this->hasMany(VgFiscal::class, 'vistoria_id'); }
    public function sinalizacao(): BelongsTo { return $this->belongsTo(Sinalizacao::class); }
    /** As peças que esta vistoria fundamentou. */
    public function documentos(): HasMany { return $this->hasMany(Documento::class, 'vg_vistoria_id')->orderBy('id'); }
    /** As fotos, na ordem em que foram tiradas. */
    public function fotos(): HasMany      { return $this->arquivos()->where('papel', 'foto')->orderBy('id'); }

    public function concluida(): bool { return $this->estado === 'concluida'; }

    public function numeroFormatado(): string
    {
        return sprintf('VG %d/%04d', $this->exercicio, $this->numero);
    }

    /** Só os fiscais relacionados mexem na vistoria. */
    public function relacionado(?User $u): bool
    {
        return $u && $u->canEdit() && $this->fiscais->contains('user_id', $u->id);
    }

    /** As vistorias ligadas a um imóvel (vínculo "imovel"). */
    public function scopeDoLote(Builder $q, int $loteId): Builder
    {
        return $q->whereHas('vinculos', fn ($v) => $v->where('tipo', 'imovel')->where('ref_id', $loteId));
    }

    /** As ligadas a um tipo de vínculo qualquer. */
    public function scopeLigadaA(Builder $q, string $tipo, int $id): Builder
    {
        return $q->whereHas('vinculos', fn ($v) => $v->where('tipo', $tipo)->where('ref_id', $id));
    }

    /** O imóvel da vistoria — o primeiro ligado. */
    public function loteId(): ?int
    {
        $l = $this->vinculos->firstWhere('tipo', 'imovel');
        return $l ? (int) $l->ref_id : null;
    }

    /** Ids de um tipo de vínculo (protocolo, denuncia, imovel, pessoa). */
    public function idsLigados(string $tipo): array
    {
        return $this->vinculos->where('tipo', $tipo)->pluck('ref_id')->map(fn ($x) => (int) $x)->values()->all();
    }

    /** Quando aconteceu: o início em campo; sem ele, a criação. */
    public function dataHora(): ?\Illuminate\Support\Carbon
    {
        return $this->iniciada_em ?? $this->created_at;
    }

    /** Os itens do roteiro desta vistoria, normalizados. */
    public function itens(): Collection
    {
        return VgItem::itensDe($this->versao?->definicao ?? []);
    }

    /**
     * A ÁREA DA MULTA: o item numérico que o modelo marcou com esse papel.
     *
     * @return array{valor: float, metodo: ?string, rotulo: string}|null
     */
    public function areaDaMulta(): ?array
    {
        $i = $this->itens()->first(fn ($i) => ($i['papel'] ?? '') === 'area');
        $x = $i ? ($this->respostas[$i['id']] ?? null) : null;
        if (! $x || ! isset($x['v']) || $x['v'] === null) { return null; }
        return ['valor' => (float) $x['v'], 'metodo' => $x['m'] ?? null, 'rotulo' => VgItem::textoResposta($i, $x)];
    }

    /** Os artigos citados nos "não confere", na ordem do roteiro, sem repetir. */
    public function artigosCitadosIds(): array
    {
        $r = $this->respostas ?? [];
        $ids = [];
        foreach ($this->itens() as $i) {
            foreach ((array) ($r[$i['id']]['art'] ?? []) as $id) { $ids[(int) $id] = true; }
        }
        return array_keys($ids);
    }

    /** @return Collection<int, Artigo> na ordem do roteiro */
    public function artigosCitados(): Collection
    {
        $ids = $this->artigosCitadosIds();
        $a = Artigo::with('legislacao:id,numero,nome')->whereIn('id', $ids)->get()->keyBy('id');
        return collect($ids)->map(fn ($id) => $a->get($id))->filter()->values();
    }

    /**
     * As exigências escritas nos "não confere", na ordem do roteiro.
     *
     * @return list<array{texto: string, prazo_dias: ?int, rotulo: string, item: string}>
     */
    public function exigencias(): array
    {
        $r = $this->respostas ?? [];
        $out = [];
        foreach ($this->itens() as $i) {
            $x = $r[$i['id']] ?? null;
            if (($x['v'] ?? null) !== 'nao' || empty($x['exig'])) { continue; }
            $p = $x['prazo'] ?? null;
            $out[] = ['texto' => $x['exig'], 'prazo_dias' => $p, 'item' => $i['rotulo'],
                'rotulo' => $x['exig'] . ($p ? ' — prazo de ' . $p . ' dia' . ($p > 1 ? 's' : '') : '')];
        }
        return $out;
    }

    /** Quantos itens "não confere". */
    public function naoConfere(): int
    {
        $r = $this->respostas ?? [];
        return $this->itens()->filter(fn ($i) => $i['tipo'] === 'confere' && (($r[$i['id']]['v'] ?? null) === 'nao'))->count();
    }

    /**
     * A SITUAÇÃO, lida da conclusão — que é texto livre do modelo.
     *
     * Os modelos escrevem a conclusão como querem ("Regular", "Apto ao
     * habite-se", "Procedente"…), e o resto do sistema precisa de uma das
     * situações de sempre: a cor no histórico, o status do imóvel, o "Regular"
     * que libera o ato cadastral. A leitura é pelas palavras:
     *
     *   irregular    "irregular", "não apto", "procedente" (a denúncia
     *                confirmou o problema);
     *   acompanhar   "com ressalvas", "em acompanhamento";
     *   nao_localiz. "não localizado";
     *   regular      "regular", "apto…", "improcedente", "não constatado";
     *   em_andamento enquanto não concluída;
     *   acompanhar   qualquer outra conclusão — na dúvida, não se diz regular.
     */
    public function situacao(): string
    {
        if (! $this->concluida()) { return 'em_andamento'; }
        $c = Str::of((string) $this->conclusao)->ascii()->lower()->squish()->value();
        return match (true) {
            str_contains($c, 'ressalva') || str_contains($c, 'acompanhamento') => 'em_acompanhamento',
            str_contains($c, 'nao localizado') => 'nao_localizado',
            str_contains($c, 'irregular') || str_starts_with($c, 'nao apto') || $c === 'procedente' => 'irregular',
            $c === 'regular' || str_starts_with($c, 'apto') || $c === 'improcedente' || $c === 'nao constatado' => 'regular',
            default => 'em_acompanhamento',
        };
    }

    public const SITUACOES = [
        'regular' => ['Regular', 'bd-ok'], 'irregular' => ['Irregular', 'bd-er'],
        'em_acompanhamento' => ['Em acompanhamento', 'bd-pe'], 'nao_localizado' => ['Não localizado', 'bd-pe'],
        'em_andamento' => ['Em andamento', 'bd-pe'],
    ];

    /** [texto, classe] para a etiqueta — o texto é a conclusão escrita no modelo. */
    public function situacaoBadge(): array
    {
        $s = self::SITUACOES[$this->situacao()];
        return [$this->concluida() ? ($this->conclusao ?: $s[0]) : $s[0], $s[1]];
    }

    /** Ponto da vistoria: o marcado no mapa; sem ele, o GPS. */
    public function ponto(): ?array
    {
        if ($this->mapa_lat !== null) { return ['lat' => $this->mapa_lat, 'lng' => $this->mapa_lng]; }
        if ($this->gps_lat !== null) { return ['lat' => $this->gps_lat, 'lng' => $this->gps_lng]; }
        return null;
    }

    /** Os protocolos de ato cadastral que esta vistoria atende. */
    public function protocolosCadastrais(): Collection
    {
        return Protocolo::where('vg_vistoria_id', $this->id)
            ->whereIn('tipo', ['desmembramento', 'unificacao', 'fracao_ideal'])->get();
    }
}
