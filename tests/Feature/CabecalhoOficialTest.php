<?php

namespace Tests\Feature;

use App\Models\Parametro;
use App\Services\CabecalhoOficial;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CabecalhoOficialTest extends TestCase
{
    public function test_dados_institucionais_vem_dos_parametros(): void
    {
        foreach (Parametro::CHAVES as $chave => $config) {
            Cache::forever('parametro:'.$chave, 'Configurado '.$chave);
        }
        $cabecalho = app(CabecalhoOficial::class);
        $orgao = $cabecalho->orgao();
        foreach (['nome', 'secretaria', 'departamento', 'divisao', 'municipio', 'endereco', 'telefone', 'cnpj'] as $campo) {
            $this->assertSame('Configurado orgao_'.$campo, $orgao[$campo]);
        }
        $this->assertSame('Configurado impressao_selo', $orgao['selo']);
        $this->assertSame(['Configurado rodape_protocolo', 'Configurado rodape_ouvidoria'], $cabecalho->rodape());
    }

    public function test_brasao_configurado_aparece_no_pdf_e_no_navegador(): void
    {
        Storage::fake('public');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        Storage::disk('public')->put('orgao/brasao-teste.png', $png);
        Cache::forever('parametro:brasao_url', '/storage/orgao/brasao-teste.png');
        $cabecalho = app(CabecalhoOficial::class);
        $this->assertSame('/storage/orgao/brasao-teste.png', $cabecalho->brasao(false));
        $this->assertSame('data:image/png;base64,'.base64_encode($png), $cabecalho->brasao(true));
        Storage::disk('public')->delete('orgao/brasao-teste.png');
        $this->assertNull($cabecalho->brasao(true));
        Cache::forever('parametro:brasao_url', '');
        $this->assertNull($cabecalho->brasao(false));
    }
}
