# POC 03 — API REST com Laravel 13 + Docker

Prova de conceito de uma API REST em **Laravel 13**, rodando em **PHP 8.3** com **PostgreSQL 18** em containers Docker (PHP-FPM + Nginx + PostgreSQL).
O projeto está sendo construído por etapas; o histórico do que já foi entregue está em [Status do projeto](#status-do-projeto).

---

## Tecnologias e versões

| Tecnologia | Versão | Observação |
|---|---|---|
| PHP | 8.3.35 | Imagem oficial `php:8.3-fpm` (Debian 13 "trixie") |
| Laravel Framework | 13.34.0 | Restrição no `composer.json`: `^13.17` |
| Symfony (componentes) | 7.4 | Resolvido para manter compatibilidade com PHP 8.3 |
| PostgreSQL | 18.6 | Imagem `postgres:18-alpine` |
| Laravel Sanctum | 4.3.3 | Autenticação de API (instalado, ainda não utilizado) |
| Composer | 2.10.3 | Copiado da imagem `composer:2` |
| Nginx | 1.31.6 | Imagem `nginx:alpine` |
| PHPUnit | 12.5.37 | Testes |
| Laravel Pint | 1.32.1 | Formatação de código |
| Docker Engine / Compose | 29.7.2 / v5.4.0 | Versões usadas no desenvolvimento |

**Extensões PHP adicionadas na imagem:** `bcmath`, `intl`, `opcache`, `pdo_pgsql`, `zip` (além das que já vêm na imagem oficial, como `mbstring` e `openssl`).

> As tags `nginx:alpine`, `php:8.3-fpm` e `postgres:18-alpine` podem trazer versões de patch diferentes em um novo build. Os valores acima foram registrados em 03/10/2026.

---

## Arquitetura

```
        Cliente HTTP
             │  :8000
             ▼
   ┌───────────────────┐   FastCGI :9000   ┌────────────────────┐   :5432   ┌────────────────────┐
   │   poc03-nginx     │ ────────────────▶ │     poc03-app      │ ────────▶ │   poc03-postgres   │
   │   nginx:alpine    │                   │  php:8.3-fpm       │           │ postgres:18-alpine │
   │   serve /public   │ ◀──────────────── │  Laravel 13        │           │ volume persistente │
   └───────────────────┘  health check     └────────────────────┘           └────────────────────┘
                          (/nginx-health)
```

- **app**: PHP-FPM com Composer. Executa a aplicação Laravel e os comandos `artisan`/`composer`. Só sobe depois que o Postgres está saudável.
- **nginx**: recebe as requisições na porta `8000` e repassa os scripts PHP para o `app`.
- **postgres**: banco da aplicação. Os dados ficam no volume `postgres-data`. Também é usado pelo cache, pela sessão e pela fila (drivers `database` do Laravel).
- O código-fonte é montado como volume nos containers `app` e `nginx`, então alterações no código têm efeito imediato, sem rebuild.

### Bancos de dados

| Banco | Uso |
|---|---|
| `poc03` | Aplicação |
| `testing` | Suíte de testes (`phpunit.xml`), criado pelo script em `docker/postgres/init/` |

Credenciais de desenvolvimento (definidas no `.env`): usuário `poc03`, senha `secret`, acessível do host em `localhost:5432`.

---

## Estrutura relevante

```
.
├── Dockerfile                          # Imagem PHP 8.3-FPM + extensões + Composer
├── compose.yaml                        # Serviços app, nginx e postgres
├── .dockerignore
├── docker/
│   ├── nginx/
│   │   └── default.conf                # Virtual host do Nginx + endpoint /nginx-health
│   └── postgres/
│       └── init/
│           └── 01-create-testing-database.sql   # Cria o banco "testing"
├── app/
│   ├── Http/Controllers/Api/
│   │   ├── HelloWorldController.php    # GET /api/hello
│   │   └── HealthCheckController.php   # GET /api/health e /api/health/{service}
│   └── Services/
│       └── HealthCheckService.php      # Lógica de verificação de cada serviço
├── routes/
│   └── api.php                         # Rotas da API (prefixo /api)
└── tests/Feature/
    └── HealthCheckTest.php             # Testes das rotas de health check
```

---

## Como executar

### Pré-requisitos

- Docker Desktop (ou Docker Engine) com Docker Compose
- Portas livres no host: `8000` (Nginx) e `5432` (PostgreSQL)

Não é necessário ter PHP, Composer ou PostgreSQL instalados na máquina: tudo roda nos containers.

### Primeira execução (após clonar)

```bash
# 1. Variáveis de ambiente (o compose.yaml também lê as credenciais do banco daqui)
cp .env.example .env

# 2. Build e subida dos containers
docker compose up -d --build

# 3. Dependências PHP
docker compose exec app composer install

# 4. Chave da aplicação
docker compose exec app php artisan key:generate

# 5. Migrations no PostgreSQL
docker compose exec app php artisan migrate
```

A API fica disponível em **http://localhost:8000**. Para conferir se tudo subiu: `curl http://localhost:8000/api/health`.

### Uso diário

```bash
docker compose up -d        # subir os containers
docker compose down         # parar e remover os containers (os dados do banco são mantidos)
docker compose down -v      # parar e APAGAR o volume do banco
docker compose logs -f      # acompanhar os logs
```

---

## Endpoints

Todas as rotas da API ficam sob o prefixo `/api`.

| Método | Rota | Descrição | Autenticação |
|---|---|---|---|
| `GET` | `/api/hello` | Retorna uma mensagem de Hello World | Não |
| `GET` | `/api/health` | Status de todos os serviços | Não |
| `GET` | `/api/health/app` | PHP-FPM / Laravel: versões e ambiente | Não |
| `GET` | `/api/health/webserver` | Nginx: requisição do app ao endpoint `/nginx-health` | Não |
| `GET` | `/api/health/database` | PostgreSQL: conexão, query de teste e versão | Não |
| `GET` | `/api/health/cache` | Cache: grava, lê e remove uma chave (store `database`) | Não |
| `GET` | `/api/user` | Retorna o usuário autenticado (rota padrão do Sanctum) | `auth:sanctum` |
| `GET` | `/up` | Health check padrão do Laravel | Não |
| `GET` | `/nginx-health` | Health check do Nginx (não passa pelo PHP) | Não |

### Health check

As rotas `/api/health*` retornam **HTTP 200** quando o serviço verificado está ok e **HTTP 503** quando algum falha. Cada serviço informa também o tempo de verificação em `latency_ms`.

```bash
curl http://localhost:8000/api/health
```

```json
{
  "status": "ok",
  "services": {
    "app":       { "status": "ok", "php": "8.3.35", "laravel": "13.34.0", "environment": "local", "latency_ms": 0.01 },
    "webserver": { "status": "ok", "server": "nginx/1.31.6", "latency_ms": 44.7 },
    "database":  { "status": "ok", "driver": "pgsql", "database": "poc03", "version": "18.6", "latency_ms": 18.08 },
    "cache":     { "status": "ok", "store": "database", "latency_ms": 31.08 }
  }
}
```

Exemplo de falha (Postgres parado). Como o cache usa a tabela `cache` do banco, ele também falha:

```json
{
  "status": "error",
  "services": {
    "app":       { "status": "ok", "...": "..." },
    "webserver": { "status": "ok", "...": "..." },
    "database":  { "status": "error", "error": "SQLSTATE[08006] [7] could not translate host name \"postgres\" ...", "latency_ms": 12.3 },
    "cache":     { "status": "error", "error": "SQLSTATE[08006] [7] could not translate host name \"postgres\" ...", "latency_ms": 9.8 }
  }
}
```

A mensagem detalhada do erro só é exibida com `APP_DEBUG=true`; caso contrário, a API retorna `"Service unavailable."`.

### Hello World

```bash
curl http://localhost:8000/api/hello
```

```json
{
  "message": "Hello World"
}
```

Erros em rotas `/api/*` são sempre devolvidos em JSON (configurado em `bootstrap/app.php`).

---

## Comandos úteis

```bash
# Artisan
docker compose exec app php artisan route:list --path=api
docker compose exec app php artisan db:show
docker compose exec app php artisan make:controller Api/NomeController

# Composer
docker compose exec app composer require vendor/pacote

# Testes (usam o banco "testing")
docker compose exec app php artisan test

# Formatação de código (Pint)
docker compose exec app ./vendor/bin/pint

# Cliente psql dentro do container do banco
docker compose exec postgres psql -U poc03 -d poc03

# Shell dentro do container da aplicação
docker compose exec app bash
```

---

## Status do projeto

### Etapa 1 — Estrutura base e Hello World ✅

- [x] Projeto Laravel 13 criado com Composer rodando em PHP 8.3, para que as dependências fossem resolvidas para essa versão
- [x] `Dockerfile` com PHP 8.3-FPM, extensões e Composer
- [x] `compose.yaml` com os serviços `app` (PHP-FPM) e `nginx`
- [x] Configuração do Nginx para o Laravel
- [x] Scaffolding de API instalado (`php artisan install:api`): `routes/api.php` e Laravel Sanctum
- [x] `HelloWorldController` (controller invocável) com a rota `GET /api/hello`
- [x] Endpoint validado via `curl` (HTTP 200, `application/json`) e suíte de testes padrão passando

### Etapa 2 — PostgreSQL e health check dos serviços ✅

- [x] Serviço `postgres` (PostgreSQL 18) no `compose.yaml`, com volume persistente e healthcheck (`pg_isready`)
- [x] Extensão `pdo_pgsql` adicionada à imagem PHP
- [x] Laravel configurado para `pgsql` (`.env`, `.env.example` e default em `config/database.php`)
- [x] SQLite removido: arquivo `database/database.sqlite` apagado e script de criação retirado do `composer.json`
- [x] Testes migrados de SQLite em memória para o banco `testing` no PostgreSQL
- [x] Migrations executadas no PostgreSQL (users, cache, jobs, personal_access_tokens)
- [x] Endpoint `/nginx-health` no Nginx
- [x] Rotas de health check: `/api/health` e `/api/health/{app|webserver|database|cache}`
- [x] Testes automatizados das rotas de health check (`tests/Feature/HealthCheckTest.php`)
- [x] Validado com o Postgres parado: a API retorna 503 apontando `database` e `cache` com erro

### Pendências conhecidas

- Adicionar o trait `Laravel\Sanctum\HasApiTokens` ao model `User` quando a autenticação for implementada.
- A fila usa o driver `database`, mas ainda não há um container de worker (`queue:work`).

---

## Decisões técnicas

- **Composer executado em PHP 8.3:** a imagem oficial `composer` usa uma versão mais nova do PHP, o que poderia levar o Composer a escolher pacotes que exigem PHP 8.4+ (por exemplo, Symfony 8). Por isso o projeto foi criado dentro da própria imagem PHP 8.3, garantindo um `composer.lock` compatível.
- **Nginx + PHP-FPM em vez de `php artisan serve`:** é mais próximo de um ambiente real e suporta requisições concorrentes.
- **Controller invocável:** cada endpoint simples tem um controller de ação única (`__invoke`), o que mantém as rotas enxutas.
- **Volume do PostgreSQL 18 em `/var/lib/postgresql`:** a partir da versão 18 a imagem oficial guarda os dados em um subdiretório por versão (`/var/lib/postgresql/18/docker`), e montar o volume no antigo `/var/lib/postgresql/data` gera erro.
- **Credenciais em um só lugar:** o `compose.yaml` lê `DB_DATABASE`, `DB_USERNAME` e `DB_PASSWORD` do `.env` do Laravel para configurar o Postgres.
- **Testes em PostgreSQL:** os testes rodam no mesmo banco usado em produção (banco `testing`), evitando diferenças de comportamento entre SQLite e PostgreSQL. O script de `docker/postgres/init/` só roda na primeira inicialização do volume; se o volume já existir sem esse banco, crie-o com `docker compose exec postgres createdb -U poc03 testing`.
- **Health check do Nginx sem PHP:** o app chama `/nginx-health`, respondido diretamente pelo Nginx, para testar o servidor web sem gerar uma requisição recursiva ao PHP-FPM.

---

## Referências

- [Documentação do Laravel 13](https://laravel.com/docs/13.x)
- [Laravel — Banco de dados](https://laravel.com/docs/13.x/database)
- [Laravel — API e Sanctum](https://laravel.com/docs/13.x/sanctum)
- [Imagem oficial do PHP no Docker Hub](https://hub.docker.com/_/php)
- [Imagem oficial do PostgreSQL no Docker Hub](https://hub.docker.com/_/postgres)
