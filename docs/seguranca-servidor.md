# Segurança do servidor

O que a aplicação **não** consegue fazer sozinha e precisa ser configurado na
VPS (Ubuntu 24.04, Nginx, PHP-FPM 8.4, MySQL 8). Nada aqui é segredo: os
valores sensíveis ficam no `.env` do servidor, nunca neste repositório.

A aplicação já cuida de: limite de tentativas de login, limite por usuário em
`/api/*` (300/min) e nas rotas pesadas (20/min), cabeçalhos `X-Frame-Options`,
`X-Content-Type-Options`, `Referrer-Policy` e `Permissions-Policy`, e queda da
sessão de usuário desativado. O que segue é a camada **antes** do PHP, que é
onde se segura um volume de acessos que derrubaria o servidor (1 GB de RAM).

## 1. `.env` de produção

| Variável | Valor | Por quê |
|---|---|---|
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | Com `true`, a página de erro mostra senhas e chaves do `.env` |
| `SESSION_SECURE_COOKIE` | `true` | Cookie de sessão só trafega em HTTPS |
| `SESSION_ENCRYPT` | `true` | Recomendado; a sessão fica no banco |
| `SEED_SENHA_ADMIN` | *(vazio)* | O seeder sorteia; não rode seeder em produção |

Conferir: `php artisan about --only=environment`.

## 2. Nginx

Dentro do bloco `http { }` (`/etc/nginx/nginx.conf`):

```nginx
# Por IP. O login tem limite próprio na aplicação; este é o teto antes do PHP.
limit_req_zone  $binary_remote_addr zone=geral:10m rate=20r/s;
limit_req_zone  $binary_remote_addr zone=login:10m rate=10r/m;
limit_conn_zone $binary_remote_addr zone=conexoes:10m;
limit_req_status 429;
limit_conn_status 429;
server_tokens off;   # não anuncia a versão do Nginx
```

No `server { }` do site (HTTPS):

```nginx
client_max_body_size 32m;   # maior upload aceito: 30 MB (planilha/GeoJSON)
limit_conn conexoes 30;

# HTTPS sempre. Só depois de confirmar que o certificado renova sozinho.
add_header Strict-Transport-Security "max-age=31536000" always;

location = /entrar {
    limit_req zone=login burst=5 nodelay;
    try_files $uri /index.php?$query_string;
}

location / {
    limit_req zone=geral burst=60 nodelay;
    try_files $uri $uri/ /index.php?$query_string;
}

# Nada de arquivo oculto (.env, .git) servido por engano.
location ~ /\.(?!well-known) { deny all; }
```

Testar e recarregar: `sudo nginx -t && sudo systemctl reload nginx`.

## 3. fail2ban

Bane no firewall o IP que insiste depois de bater no limite do Nginx.

```bash
sudo apt install fail2ban
sudo tee /etc/fail2ban/jail.d/fiscobras.local <<'EOF'
[nginx-limit-req]
enabled  = true
port     = http,https
logpath  = /var/log/nginx/error.log
findtime = 10m
maxretry = 10
bantime  = 1h
EOF
sudo systemctl restart fail2ban
sudo fail2ban-client status nginx-limit-req
```

## 4. Firewall e serviços

```bash
sudo ufw default deny incoming
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
```

Na Oracle Cloud, a *Security List* da VCN também precisa liberar só 22, 80 e
443.

- **MySQL só local:** em `/etc/mysql/mysql.conf.d/mysqld.cnf`,
  `bind-address = 127.0.0.1`. Conferir: `sudo ss -tlnp | grep 3306` deve
  mostrar `127.0.0.1:3306`.
- **PHP-FPM só por socket**, nunca porta aberta: `listen = /run/php/php8.4-fpm.sock`.
- **SSH só por chave:** `PasswordAuthentication no` em `/etc/ssh/sshd_config`.
- **Atualizações automáticas de segurança:** `sudo apt install unattended-upgrades`.

O `trustProxies('*')` da aplicação (ver `bootstrap/app.php`) só é seguro
porque o PHP **não** é alcançável direto da internet — só o Nginx fala com ele.

## 5. Backup

O que não pode se perder: o banco e `storage/app/private` (fotos e anexos das
vistorias). Diário, guardado **fora** da VPS:

```bash
mysqldump --single-transaction fiscalizacao_obras | gzip > /var/backups/fiscobras-$(date +%F).sql.gz
tar czf /var/backups/fiscobras-arquivos-$(date +%F).tgz -C /caminho/do/app storage/app/private
```

Backup que nunca foi restaurado não é backup: restaurar uma vez num banco de
teste e abrir o sistema.

## 6. Antes de entrar em produção

- Apagar usuários, vistorias, documentos, protocolos, OS, evidências e
  auditoria de teste. Ficam lotes, bairros, edificações, cadastro carregado,
  legislação e parâmetros.
- Esvaziar a tabela `sessions` e os `remember_token`.
- Criar os usuários reais pela tela, cada um com a própria senha.
- Rodar `composer audit` e atualizar o que ele apontar.
