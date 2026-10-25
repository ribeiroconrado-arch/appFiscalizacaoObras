# Backup

O que não pode se perder: o **banco** e os **anexos** (`storage/app`: fotos, documentos e
arquivos das vistorias). Os dois saem juntos, todo dia, para dois lugares:

| Onde | Quanto tempo | Quem abre |
|---|---|---|
| No próprio servidor, `/var/backups/fiscobras/` | 7 dias | só o root (arquivos `600`) |
| Fora do servidor, no armazenamento de objetos (hoje: Oracle Object Storage, bucket `fiscobras-backup`) | diário 31 dias, mensal 12 meses | só quem tem a chave privada |

A cópia de fora vai **cifrada** com [age](https://age-encryption.org). O servidor só tem a
chave pública, que serve para cifrar e não abre nada. A **chave privada fica fora do
servidor**, com o responsável pelo sistema. Se o servidor for invadido, quem entrou não
consegue abrir os backups antigos. **Se a chave privada se perder, nenhum backup de fora
pode ser aberto.** Guarde duas cópias dela em lugares diferentes, por exemplo um pendrive
e um gerenciador de senhas.

## Como funciona

- **Cron:** `/etc/cron.d/backup-banco`, às 3h30, como root. Chama o script abaixo.
- **Script:** `/usr/local/bin/backup-banco.sh`.
  1. `mysqldump` do banco `fiscalizacao_obras`, compactado com gzip, e `tar.gz` de
     `storage/app`.
  2. Guarda as duas cópias em `/var/backups/fiscobras/` e apaga as que têm mais de 7 dias.
  3. Cifra cada arquivo com a chave pública (`/etc/fiscobras/backup-chave-publica.age`) e
     envia para `destino:diario/`. No dia 1 de cada mês envia também para `destino:mensal/`.
  4. Apaga as cópias de `diario/` com mais de 31 dias e as de `mensal/` com mais de 370.
- **Log:** `/var/log/backup-fiscobras.log`. Se o envio para fora falhar, a cópia local
  continua valendo e o log registra `ERRO: cópia externa falhou`.

Espaço usado hoje: uns 14 MB por dia (banco 8 MB + anexos 5 MB), uns 0,6 GB no total com a
retenção acima.

### O "destino"

O script não conhece o provedor. Ele envia para `destino:`, que é um apelido no rclone do
root. Hoje o apelido aponta para o bucket da Oracle:

```
destino  →  alias de  oci:fiscobras-backup
oci      →  S3 compatível, região sa-saopaulo-1
```

As chaves de acesso ficam em `/root/.config/rclone/rclone.conf` (`600`), nunca no
repositório.

## Conferir se está rodando

```bash
sudo tail -n 20 /var/log/backup-fiscobras.log     # cada dia: "local ok", "externo ok"
sudo ls -la /var/backups/fiscobras/
sudo rclone ls destino:                             # o que está fora do servidor
sudo rclone size destino:
```

Rodar um backup fora de hora: `sudo /usr/local/bin/backup-banco.sh`.

## Trocar de provedor (ex.: provedor próprio da prefeitura)

Só o `destino` muda. O script de backup continua igual.

1. Criar o bucket no provedor novo e uma chave de acesso S3 (Access Key + Secret) com
   permissão só nesse bucket.
2. No servidor, editar `/usr/local/bin/configurar-destino-backup`: trocar `region`,
   `endpoint` e o nome do bucket (`oci:fiscobras-backup`) pelos do provedor novo. Se o
   provedor não for compatível com S3, configure o remote com `sudo rclone config` (o
   rclone aceita dezenas de tipos) e mantenha o nome `destino`.
3. Rodar `sudo configurar-destino-backup`. Ele pergunta a chave e o segredo (o segredo não
   aparece na tela nem fica no histórico) e termina com `CONFIGURADO`.
4. Rodar um backup fora de hora e conferir com `sudo rclone ls destino:`.
5. Copiar os backups antigos, se quiser mantê-los:
   `sudo rclone copy oci:fiscobras-backup novo:bucket` (antes de apagar o remote antigo).

Se o sistema mudar de servidor, levar `/usr/local/bin/backup-banco.sh`,
`/usr/local/bin/configurar-destino-backup`, `/etc/fiscobras/backup-chave-publica.age` e
`/etc/cron.d/backup-banco`, instalar `rclone` e `age` (`sudo apt install rclone age`) e
rodar o passo 3.

## Restaurar

Precisa do `age` e da chave privada no computador que vai abrir o backup. No Windows,
instale com `winget install FiloSottile.age`.

1. **Baixar** a cópia do dia desejado (`AAAA-MM-DD`) do console do provedor, ou pelo
   servidor:

   ```bash
   ssh ... 'sudo rclone cat destino:diario/banco-AAAA-MM-DD.sql.gz.age' > banco.sql.gz.age
   ssh ... 'sudo rclone cat destino:diario/anexos-AAAA-MM-DD.tar.gz.age' > anexos.tar.gz.age
   ```

2. **Abrir** com a chave privada:

   ```bash
   age -d -i caminho/da/chave-privada.txt -o banco.sql.gz  banco.sql.gz.age
   age -d -i caminho/da/chave-privada.txt -o anexos.tar.gz anexos.tar.gz.age
   ```

3. **Conferir** antes de usar:

   ```bash
   gzip -t banco.sql.gz && zcat banco.sql.gz | tail -n 1    # termina com "-- Dump completed"
   tar -tzf anexos.tar.gz | head
   ```

4. **Carregar.** Primeiro num banco de teste, nunca direto em cima do de produção:

   ```bash
   mysql -e "CREATE DATABASE restauracao_teste CHARACTER SET utf8mb4"
   zcat banco.sql.gz | mysql restauracao_teste
   ```

   Para voltar a produção de verdade, faça uma cópia do banco atual
   (`sudo /usr/local/bin/backup-banco.sh`), pare o site
   (`php artisan down`), carregue o dump em `fiscalizacao_obras`, extraia os anexos em
   `storage/` (`tar -xzf anexos.tar.gz -C /var/www/fiscobras/storage`, dono `www-data`) e
   suba o site (`php artisan up`).

Backup que nunca foi restaurado não é backup: repita o teste de restauração de vez em
quando, e sempre depois de mexer no script ou no destino.

### Histórico de testes

| Data | Cópia | Resultado |
|---|---|---|
| 25/10/2026 | `diario/*-2026-10-25` (cron das 3h30) | OK. As duas cópias abriram com a chave privada; dump inteiro ("Dump completed"); anexos com 36 entradas. Carregado num MySQL 8.0.46 temporário em 31 s: 61 tabelas, 236 mil linhas, 35.661 lotes, geometrias e campos idênticos byte a byte aos de produção (checksum). As únicas diferenças foram de registros criados depois das 3h30. |

Num teste assim, compare checksums (`SUM(CRC32(ST_AsBinary(geom)))`) e não a soma das áreas:
`ST_Area` em SRID 4326 dá resultados um pouco diferentes no Linux e no Windows, mesmo com
geometria idêntica (31 m² em 12 milhões de m², em 25/10/2026).
