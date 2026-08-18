<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#B4470D">
<title>Entrar — Fiscalização de Obras</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@600;700;800&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
{{-- PNG e não o SVG oficial: o SVG é um trace de 3.500+ caminhos (430 KB) —
     ótimo para impressão, pesado demais para favicon. Os PNGs vêm do mesmo
     arquivo, com o fundo fora do ícone removido. --}}
<link rel="icon" type="image/png" sizes="32x32" href="@assetv('img/favicon-32.png')">
<link rel="icon" type="image/png" sizes="16x16" href="@assetv('img/favicon-16.png')">
<link rel="apple-touch-icon" sizes="180x180" href="@assetv('img/apple-touch-icon.png')">
<link rel="stylesheet" href="@assetv('css/app.css')">
{{-- Mesmo tema da tela do mapa: trocar de variante é trocar esta linha. --}}
<link rel="stylesheet" href="@assetv('css/tema-f.css')">
</head>
<body class="login-bg">

{{-- Cartão sobre gradiente âmbar, no tema F. --}}
<div class="login-card">
  <div class="login-logo">
    <div class="login-seal">
      {{-- logo-128.png: recorte do ícone oficial (public/img/icone.svg) sem
           o fundo branco fora do squircle — só o quadrado laranja arredondado
           e o miolo branco ficam. 128px cobre 104px em telas retina. --}}
      <img src="@assetv('img/logo-128.png')" alt="" style="width:104px;height:104px;display:block">
    </div>
    <h1>Fiscalização de Obras</h1>
    <p>Prefeitura de Primavera do Leste</p>
  </div>

  @if ($errors->any())
    <div class="alerta-erro">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
           stroke-linecap="round" stroke-linejoin="round">
        <circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16.5v.01"/>
      </svg>
      <span>{{ $errors->first() }}</span>
    </div>
  @endif

  <form method="POST" action="{{ route('login.entrar') }}" autocomplete="on">
    @csrf

    <div class="field">
      <label for="email">E-mail</label>
      <input id="email" name="email" type="email" required autofocus
             autocomplete="username" value="{{ old('email') }}"
             class="{{ $errors->has('email') ? 'campo-invalido' : '' }}">
    </div>

    <div class="field">
      <label for="password">Senha</label>
      <div class="pass-wrap">
        <input id="password" name="password" type="password" required
               autocomplete="current-password">
        <button type="button" class="pass-olho" onclick="alternarSenha(this)"
                aria-label="Mostrar ou ocultar a senha">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
               stroke-linecap="round" stroke-linejoin="round">
            <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/>
          </svg>
        </button>
      </div>
    </div>

    <label class="lembrar">
      <input type="checkbox" name="lembrar" value="1"> Manter conectado neste aparelho
    </label>

    <button type="submit" class="btn primary full" style="margin-top:14px">Entrar</button>
  </form>

  <p class="login-rodape">
    Acesso restrito a servidores autorizados. Esqueceu a senha? Procure o
    administrador do sistema.
  </p>
</div>

<script>
/** Alterna a visibilidade da senha, trocando o ícone entre olho e olho cortado. */
function alternarSenha(btn) {
  const input = document.getElementById('password')
  const visivel = input.type === 'text'
  input.type = visivel ? 'password' : 'text'
  btn.innerHTML = visivel
    ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>'
    : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.9 17.9A10.5 10.5 0 0 1 12 19C5 19 1 12 1 12a19 19 0 0 1 5.1-5.9M9.9 4.2A10.5 10.5 0 0 1 12 4c7 0 11 7 11 7a19 19 0 0 1-2.2 3.2M9.9 9.9a3 3 0 0 0 4.2 4.2"/><path d="M2 2l20 20"/></svg>'
}
</script>
</body>
</html>
