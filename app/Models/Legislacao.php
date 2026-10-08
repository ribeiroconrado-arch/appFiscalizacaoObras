<?php

namespace App\Models;

use App\Models\Concerns\RegistraAuditoria;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Legislacao extends Model
{
    use RegistraAuditoria;

    protected $table = 'legislacoes';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['ativa' => 'boolean', 'data_publicacao' => 'date'];
    }

    public function artigos(): HasMany
    {
        return $this->hasMany(Artigo::class);
    }

    public function scopeAtivas(Builder $q): Builder
    {
        return $q->where('ativa', true)->orderBy('nome');
    }

    /** "Lei Complementar 1/2023 — Código de Obras" */
    public function rotulo(): string
    {
        return trim($this->numero . ' — ' . $this->nome);
    }

    /**
     * A citação oficial da lei: "Lei Complementar 1/2023, de 15 de dezembro de
     * 2023". É o que o marcador {lei oficial} põe nos textos de ciência. Sem a
     * data cadastrada, só o número.
     */
    public function citacaoOficial(): string
    {
        $meses = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto',
            'setembro', 'outubro', 'novembro', 'dezembro'];
        $d = $this->data_publicacao;

        return $this->numero . ($d ? ', de ' . $d->day . ($d->day === 1 ? 'º' : '') . ' de ' . $meses[$d->month - 1] . ' de ' . $d->year : '');
    }

    /**
     * Texto de ciência do documento, com os marcadores resolvidos:
     *
     *   {prazo}        "no prazo de N dias" ou "de imediato" — o prazo de
     *                  CUMPRIMENTO, que varia por documento (notificações);
     *   {lei oficial}  a citação desta lei (citacaoOficial);
     *   {origem}       o documento de que a peça nasceu: "a Notificação de
     *                  Embargo nº 12/2026", "o Auto de Embargo nº 3/2026". Já
     *                  vem com o artigo. Peça sem origem: o marcador some.
     *
     * O negrito (**texto**) NÃO é resolvido aqui: é da impressão
     * (DocumentoImpressao::negrito), porque depende de escapar o HTML antes.
     */
    public function ciencia(string $tipo, ?int $prazoDias = null, ?Documento $origem = null): ?string
    {
        $txt = in_array($tipo, Documento::COM_DEFESA, true)
            ? $this->ciencia_auto
            : $this->ciencia_notificacao;

        if (! $txt) {
            return null;
        }

        $prazo = $prazoDias === 0 ? 'de imediato' : 'no prazo de ' . $prazoDias . ' dias';
        $txt = str_replace(['{prazo}', '{lei oficial}', '{origem}'],
            [$prazo, $this->citacaoOficial(), $origem?->referencia() ?? ''], $txt);

        // Marcador que sumiu não deixa espaço dobrado nem espaço antes da pontuação.
        return trim(preg_replace(['/[ \t]{2,}/', '/ +([,.;:])/'], [' ', '$1'], $txt));
    }
}
