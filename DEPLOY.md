# Deploy no Railway

## Arquitetura

```
 Navegador ──HTTPS──▶  frontend  (dominio publico)
                       Caddy: serve o PWA e repassa /api/*
                          │  rede privada (*.railway.internal)
                          │  + header X-Internal-Token
                          ▼
                       backend  (SEM dominio publico)
                       Apache + PHP (Slim)
                          │
                ┌─────────┴─────────┐
                ▼                   ▼
            Postgres              Redis
          (sem TCP proxy)     (sem TCP proxy)
```

- O navegador fala **somente** com o frontend. As chamadas vão para `/api` na mesma origem
  (`VITE_API_URL=/api`), e o Caddy repassa ao backend pela rede privada.
- O backend não tem domínio público: da internet ele é inalcançável.
- Segunda camada: o backend recusa (403) qualquer requisição sem o `X-Internal-Token`
  correto. O Caddy sempre sobrescreve esse header, então um cliente não consegue forjá-lo.
  Isso também bloqueia outros serviços do projeto e um domínio público criado por engano.

> **Por que um projeto só?** A rede privada do Railway existe apenas *dentro de um
> projeto* (e ambiente). Se o frontend e o backend ficarem em projetos Railway diferentes,
> o backend precisa de um domínio público. Por isso: **um projeto, um serviço por
> repositório**. Cada serviço continua ligado ao seu próprio repo e faz deploy sozinho.

## Arquivos de deploy

| Repositório | Arquivo | Função |
|---|---|---|
| backend | `Dockerfile` | PHP 8.2 + Apache + pdo_pgsql; escuta em `$PORT` (IPv4 e IPv6) |
| backend | `docker-entrypoint.sh` + `database/migrate.php` | aplica migrations pendentes a cada deploy |
| backend | `railway.json` | build por Dockerfile, health check em `/api/health` |
| frontend | `Dockerfile` | build do Vite + Caddy |
| frontend | `Caddyfile` | arquivos estáticos, fallback do SPA, cache do PWA e proxy de `/api` |
| frontend | `railway.json` | build por Dockerfile, health check em `/` |

## Passo a passo

### 0. Repositórios no GitHub

Os dois repositórios precisam estar no GitHub, com os arquivos acima commitados.

### 1. Criar o projeto com o backend

1. No Railway: **New Project → Deploy from GitHub repo → `PurchasingOrganization-backend`**.
2. Clique no serviço → **Settings** → renomeie para **`backend`**. As referências abaixo
   usam esse nome.
3. Em **Settings → Networking**, confira que **não há domínio público**. Não clique em
   *Generate Domain*.

O primeiro deploy vai falhar por falta de banco. Isso é esperado.

### 2. Banco e cache (mesmo projeto)

1. **+ Create → Database → PostgreSQL**. Mantenha o nome `Postgres`.
2. **+ Create → Database → Redis**. Mantenha o nome `Redis`. Ele é opcional: sem Redis a
   API funciona, só não usa cache.
3. Em cada um, abra **Settings → Networking** e **remova o TCP Proxy** (endereço público
   `*.proxy.rlwy.net`). Assim o banco e o cache só ficam acessíveis pela rede privada.
   Se precisar importar dados depois, ative o proxy temporariamente.

### 3. Token interno compartilhado

1. Gere um valor aleatório no PowerShell:
   ```powershell
   $b = New-Object byte[] 32; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b); ($b | % { $_.ToString('x2') }) -join ''
   ```
2. No projeto: **Settings → Shared Variables → New Variable**, com nome `INTERNAL_TOKEN` e
   o valor gerado.

### 4. Variáveis do backend

No serviço `backend` → **Variables → Raw Editor**, cole:

```env
PORT=8080
APP_ENV=production
APP_DEBUG=false
INTERNAL_TOKEN=${{shared.INTERNAL_TOKEN}}

DB_HOST=${{Postgres.PGHOST}}
DB_PORT=${{Postgres.PGPORT}}
DB_NAME=${{Postgres.PGDATABASE}}
DB_USER=${{Postgres.PGUSER}}
DB_PASS=${{Postgres.PGPASSWORD}}

REDIS_HOST=${{Redis.REDISHOST}}
REDIS_PORT=${{Redis.REDISPORT}}
REDIS_USER=${{Redis.REDISUSER}}
REDIS_PASSWORD=${{Redis.REDISPASSWORD}}
REDIS_PREFIX=pt:
```

`PGHOST` e `REDISHOST` já apontam para os endereços privados (`*.railway.internal`).
Salve e clique em **Deploy**. Nos logs devem aparecer `[migrate] 001_create_tables.sql
aplicada` e depois o Apache iniciando. O health check em `/api/health` deve ficar verde.

### 5. Frontend

1. No mesmo projeto: **+ Create → GitHub Repo → `PurchasingOrganization-frontend`**.
   Renomeie o serviço para **`frontend`**.
2. **Variables → Raw Editor**:
   ```env
   PORT=8080
   BACKEND_URL=http://${{backend.RAILWAY_PRIVATE_DOMAIN}}:${{backend.PORT}}
   INTERNAL_TOKEN=${{shared.INTERNAL_TOKEN}}
   ```
3. **Settings → Networking → Generate Domain**, com a porta **8080**. Se tiver um domínio
   próprio, use *Custom Domain*.
4. Faça o deploy.

### 6. Conferir

| Teste | Esperado |
|---|---|
| `https://<frontend>.up.railway.app` | app abre e é instalável (HTTPS do Railway) |
| `https://<frontend>.up.railway.app/api/health` | `{"success":true,...}` (passou pelo proxy) |
| criar uma remessa no app | salva e aparece após recarregar |
| serviço `backend` → Settings → Networking | nenhum domínio público |
| `Postgres` / `Redis` → Networking | sem TCP proxy |

### 7. Deploys seguintes

Cada `git push` na branch `master` de um repositório faz o deploy só daquele serviço.
Novas migrations: crie `database/migrations/002_xxx.sql`. O próximo deploy do backend
aplica o arquivo uma única vez, e o controle fica na tabela `schema_migrations`.

## Desenvolvimento local

Nada muda. `INTERNAL_TOKEN` vazio (ou ausente) no `.env` desativa a verificação do token,
e o `vite.config.ts` continua fazendo proxy de `/api` para `localhost:8080`.

Para testar as imagens localmente, simulando a rede privada:

```bash
docker network create po-private
docker build -t po-backend .
docker build -t po-frontend ../PurchasingOrganization-frontend
docker run -d --name po-backend --network po-private --network-alias backend -e INTERNAL_TOKEN=teste -e DB_HOST=PurchasingOrganization-db -e REDIS_HOST=PurchasingOrganization-redis -v "$PWD/.env:/var/www/html/.env:ro" po-backend
docker network connect purchasingorganization_default po-backend
docker run -d --name po-frontend --network po-private -p 4180:8080 -e BACKEND_URL=http://backend:8080 -e INTERNAL_TOKEN=teste po-frontend
# http://localhost:4180
```
