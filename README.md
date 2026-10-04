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
| Laravel Sanctum | 4.3.3 | Autenticação da API por token (`Authorization: Bearer`) |
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

## Modelo de dados

### Task (`tasks`)

| Coluna | Tipo | Observação |
|---|---|---|
| `id` | bigint | Chave primária |
| `title` | varchar(255) | Obrigatório |
| `description` | text | Opcional |
| `status` | varchar + `CHECK` | Enum `App\Enums\TaskStatus`, padrão `pending`, indexado |
| `created_at` | timestamp | Horário de criação |
| `updated_at` | timestamp | Horário da última atualização |
| `deleted_at` | timestamp | Horário de exclusão (soft delete) |

### Status (`App\Enums\TaskStatus`)

| Case | Valor no banco | Rótulo (`label()`) |
|---|---|---|
| `Pending` | `pending` | Pendente |
| `InProgress` | `in_progress` | Em andamento |
| `Completed` | `completed` | Concluído |
| `Cancelled` | `cancelled` | Cancelado |
| `Deleted` | `deleted` | Excluído |

O enum é validado em duas camadas: no PHP (cast do Eloquent) e no PostgreSQL (`CHECK constraint` gerada pela migration).

### Regras de exclusão

A exclusão é lógica (soft delete): o registro continua na tabela com `deleted_at` preenchido. O model garante que **o status é `deleted` se, e somente se, `deleted_at` estiver preenchido**:

| Ação | Resultado |
|---|---|
| `$task->delete()` | `deleted_at` = agora, `status` = `deleted` |
| `$task->update(['status' => TaskStatus::Deleted])` | `deleted_at` = agora (tarefa vai para a lixeira) |
| `$task->restore()` em tarefa excluída | `deleted_at` = `null`, `status` = `pending` |
| `$task->restore()` em tarefa ativa | Nada muda (o status é mantido) |
| Mudar o status de uma tarefa excluída para outro valor | `deleted_at` = `null` (tarefa sai da lixeira) |

Tarefas excluídas não aparecem nas consultas padrão; use `Task::withTrashed()` ou `Task::onlyTrashed()` para incluí-las. Exclusões em massa via query builder (`Task::where(...)->delete()`) não disparam eventos do model e, por isso, não atualizam o status.

---

## Estrutura relevante

```
.
├── Dockerfile                          # Imagem PHP 8.3-FPM + extensões + Composer
├── compose.yaml                        # Serviços app, nginx e postgres
├── .dockerignore
├── api-rest/                           # Coleções do Postman
│   ├── auth/
│   │   └── auth.postman_collection.json
│   ├── health/
│   │   └── health.postman_collection.json
│   └── task/
│       └── task.postman_collection.json
├── docker/
│   ├── nginx/
│   │   └── default.conf                # Virtual host do Nginx + endpoint /nginx-health
│   └── postgres/
│       └── init/
│           └── 01-create-testing-database.sql   # Cria o banco "testing"
├── app/
│   ├── Enums/
│   │   └── TaskStatus.php              # Enum de status da tarefa
│   ├── Http/
│   │   ├── Controllers/Api/
│   │   │   ├── AuthController.php          # Cadastro, login, logout e usuário autenticado
│   │   │   ├── HelloWorldController.php    # GET /api/hello
│   │   │   ├── HealthCheckController.php   # GET /api/health e /api/health/{service}
│   │   │   └── TaskController.php          # CRUD /api/tasks + restore
│   │   ├── Requests/
│   │   │   ├── LoginRequest.php            # Validação do login
│   │   │   ├── RegisterRequest.php         # Validação do cadastro
│   │   │   ├── StoreTaskRequest.php        # Validação da criação de tarefa
│   │   │   └── UpdateTaskRequest.php       # Validação da atualização de tarefa
│   │   └── Resources/
│   │       ├── TaskResource.php            # Formato JSON da tarefa
│   │       └── UserResource.php            # Formato JSON do usuário
│   ├── Models/
│   │   ├── Task.php                    # Model Task (soft delete + sincronização de status)
│   │   └── User.php                    # Model User (HasApiTokens do Sanctum)
│   └── Services/
│       └── HealthCheckService.php      # Lógica de verificação de cada serviço
├── database/
│   ├── factories/TaskFactory.php
│   └── migrations/…_create_tasks_table.php
├── routes/
│   └── api.php                         # Rotas da API (prefixo /api)
└── tests/Feature/
    ├── AuthApiTest.php                 # Testes das rotas de autenticação
    ├── HealthCheckTest.php             # Testes das rotas de health check
    ├── TaskApiTest.php                 # Testes das rotas de tarefas
    └── TaskTest.php                    # Testes do model Task
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

# 6. (Opcional) Usuário de teste: test@example.com / password
docker compose exec app php artisan db:seed
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
| `POST` | `/api/auth/register` | Cadastra um usuário e retorna um token | Não |
| `POST` | `/api/auth/login` | Troca e-mail e senha por um token | Não |
| `GET` | `/api/auth/me` | Retorna o usuário autenticado | Token |
| `POST` | `/api/auth/logout` | Revoga o token usado na requisição | Token |
| `GET` | `/api/tasks` | Lista as tarefas (paginado, filtro por status) | Token |
| `POST` | `/api/tasks` | Cria uma tarefa | Token |
| `GET` | `/api/tasks/{id}` | Exibe uma tarefa | Token |
| `PUT/PATCH` | `/api/tasks/{id}` | Atualiza uma tarefa | Token |
| `DELETE` | `/api/tasks/{id}` | Exclui uma tarefa (soft delete) | Token |
| `POST` | `/api/tasks/{id}/restore` | Restaura uma tarefa excluída | Token |
| `GET` | `/api/health` | Status de todos os serviços | Não |
| `GET` | `/api/health/app` | PHP-FPM / Laravel: versões e ambiente | Não |
| `GET` | `/api/health/webserver` | Nginx: requisição do app ao endpoint `/nginx-health` | Não |
| `GET` | `/api/health/database` | PostgreSQL: conexão, query de teste e versão | Não |
| `GET` | `/api/health/cache` | Cache: grava, lê e remove uma chave (store `database`) | Não |
| `GET` | `/up` | Health check padrão do Laravel | Não |
| `GET` | `/nginx-health` | Health check do Nginx (não passa pelo PHP) | Não |

"Token" = header `Authorization: Bearer <token>`, obtido no cadastro ou no login. Sem token (ou com um token revogado), a API responde `401 {"message": "Unauthenticated."}`.

### Autenticação (`/api/auth`)

A autenticação usa os **tokens de API do Laravel Sanctum**: o cliente troca e-mail e senha por um token e o envia em todas as requisições protegidas.

**Fluxo**

1. `POST /api/auth/register` (cadastro) ou `POST /api/auth/login` → resposta com o `token`.
2. Enviar `Authorization: Bearer <token>` nas rotas protegidas.
3. `POST /api/auth/logout` → revoga aquele token (os outros tokens do usuário continuam válidos).

**Campos aceitos**

| Rota | Campo | Regras |
|---|---|---|
| `register` | `name` | Obrigatório, até 255 caracteres |
| `register` | `email` | Obrigatório, e-mail válido, minúsculo e único |
| `register` | `password` / `password_confirmation` | Obrigatórios, mínimo de 8 caracteres e iguais |
| `login` | `email` / `password` | Obrigatórios |
| `register` e `login` | `device_name` | Opcional (padrão `api`). Nome que identifica o token, ex.: "iPhone da Maria" |

**Resposta do cadastro (`201`) e do login (`200`)**

```json
{
  "data": {
    "id": 1,
    "name": "Maria",
    "email": "maria@example.com",
    "email_verified_at": null,
    "created_at": "2026-10-04T15:20:11.000000Z",
    "updated_at": "2026-10-04T15:20:11.000000Z"
  },
  "token": "1|Xq3m...",
  "token_type": "Bearer"
}
```

O token só é exibido nesse momento; no banco (`personal_access_tokens`) fica apenas o hash SHA-256.

**Respostas de erro**

| Situação | HTTP |
|---|---|
| Dados inválidos ou e-mail já cadastrado | `422 Unprocessable Content` |
| E-mail ou senha incorretos (erro no campo `email`, como na documentação do Sanctum) | `422 Unprocessable Content` |
| Sem token, token inválido ou revogado | `401 Unauthorized` |
| Mais de 10 tentativas de cadastro/login por minuto no mesmo IP | `429 Too Many Requests` |

**Exemplos**

```bash
# Cadastrar
curl -X POST http://localhost:8000/api/auth/register \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"name": "Maria", "email": "maria@example.com", "password": "password", "password_confirmation": "password"}'

# Login (guarda o token em uma variável do shell)
TOKEN=$(curl -s -X POST http://localhost:8000/api/auth/login \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email": "maria@example.com", "password": "password"}' | python3 -c 'import sys, json; print(json.load(sys.stdin)["token"])')

# Usuário autenticado
curl -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN" http://localhost:8000/api/auth/me

# Logout
curl -X POST -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN" http://localhost:8000/api/auth/logout
```

### Tarefas (`/api/tasks`)

Todas as rotas de tarefas exigem o header `Authorization: Bearer <token>` (veja [Autenticação](#autenticação-apiauth)). Envie também `Accept: application/json` para receber erros de validação em JSON.

**Formato de uma tarefa**

```json
{
  "data": {
    "id": 1,
    "title": "Estudar Laravel",
    "description": "Ler a documentação de Eloquent",
    "status": "in_progress",
    "status_label": "Em andamento",
    "created_at": "2026-10-04T02:05:21.000000Z",
    "updated_at": "2026-10-04T02:10:03.000000Z",
    "deleted_at": null
  }
}
```

**Campos aceitos (`POST` e `PUT/PATCH`)**

| Campo | Criação | Atualização | Regras |
|---|---|---|---|
| `title` | Obrigatório | Opcional | Texto, até 255 caracteres |
| `description` | Opcional | Opcional | Texto ou `null` |
| `status` | Opcional (padrão `pending`) | Opcional | `pending`, `in_progress`, `completed` ou `cancelled` |

Na atualização, só os campos enviados são alterados. O status `deleted` não é aceito em `POST` nem em `PUT/PATCH`: a exclusão é feita apenas pelo `DELETE`.

**Listagem (`GET /api/tasks`)**

| Parâmetro | Descrição |
|---|---|
| `status` | Filtra pelo status. `status=deleted` lista as tarefas excluídas (lixeira) |
| `per_page` | Itens por página, de 1 a 100 (padrão 15) |
| `page` | Página |

Sem filtro, a listagem traz só as tarefas ativas, da mais recente para a mais antiga, com os blocos `links` e `meta` de paginação.

**Respostas**

| Situação | HTTP |
|---|---|
| Tarefa criada | `201 Created` |
| Consulta, atualização ou restauração | `200 OK` |
| Exclusão | `204 No Content` |
| Sem token, token inválido ou revogado | `401 Unauthorized` |
| Tarefa inexistente ou excluída (em `GET`, `PUT/PATCH` e `DELETE`) | `404 Not Found` |
| Dados inválidos | `422 Unprocessable Content` |

**Exemplos** (com `$TOKEN` obtido no [login](#autenticação-apiauth))

```bash
# Criar
curl -X POST http://localhost:8000/api/tasks \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
  -d '{"title": "Estudar Laravel", "description": "Ler a documentação de Eloquent"}'

# Listar as tarefas em andamento
curl -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN" 'http://localhost:8000/api/tasks?status=in_progress'

# Mudar o status
curl -X PATCH http://localhost:8000/api/tasks/1 \
  -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
  -d '{"status": "completed"}'

# Excluir e restaurar
curl -X DELETE -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN" http://localhost:8000/api/tasks/1
curl -X POST   -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN" http://localhost:8000/api/tasks/1/restore
```

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

## Postman

A pasta [`api-rest/`](api-rest/) traz as coleções prontas para importar no Postman (formato Collection v2.1):

| Coleção | Arquivo | Requisições |
|---|---|---|
| POC 03 - Auth | `api-rest/auth/auth.postman_collection.json` | Cadastro, usuário autenticado, logout, login e exemplos de erro (401 e 422) |
| POC 03 - Health | `api-rest/health/health.postman_collection.json` | Health check de todos os serviços, `/up` e `/nginx-health` |
| POC 03 - Task | `api-rest/task/task.postman_collection.json` | CRUD completo de tarefas, lixeira, restauração e exemplos de erro (401 e 422) |

**Importar:** no Postman, clique em **Import** e arraste a pasta `api-rest` (ou os três arquivos `.json`).

**Autenticação no Postman:** execute **"Registrar usuário"** ou **"Login"** da coleção *POC 03 - Auth* antes de usar a coleção *POC 03 - Task*. O token retornado é salvo na variável **global** `token` (visível em *Environments → Globals*) e enviado automaticamente como `Authorization: Bearer {{token}}` pelas coleções Auth e Task.

**Variáveis**

| Variável | Onde | Padrão | Uso |
|---|---|---|---|
| `token` | Global | — | Preenchida por "Registrar usuário" e "Login" |
| `base_url` | Cada coleção | `http://localhost:8000` | Endereço da API |
| `email` | Auth | `test@example.com` | Substituída por um e-mail único a cada "Registrar usuário" |
| `password` | Auth | `password` | Senha usada no cadastro e no login |
| `task_id` | Task | `1` | Preenchida automaticamente pela requisição "Criar tarefa" |

Para fazer login com o usuário de teste (`test@example.com`), rode antes `docker compose exec app php artisan db:seed`.

As requisições de cada coleção estão na ordem de um fluxo completo e todas têm testes. Dá para executar tudo de uma vez pelo **Collection Runner** ou pelo Newman, sem instalar nada além do Docker. Como o Newman roda cada coleção separadamente, o token é passado de uma execução para a outra por um arquivo de variáveis globais:

```bash
newman() { docker run --rm --network poc03_default -v "$PWD/api-rest":/etc/newman postman/newman run "$@" --env-var base_url=http://nginx; }

newman auth/auth.postman_collection.json --export-globals globals.json
newman task/task.postman_collection.json --globals globals.json
rm api-rest/globals.json
```

> Executar as coleções Auth e Task cria um usuário e uma tarefa no banco de desenvolvimento.

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

### Etapa 3 — Model Task ✅

- [x] Enum `TaskStatus` (string-backed) com os status pendente, em andamento, concluído, cancelado e excluído, e rótulos em português
- [x] Migration `tasks` com `title`, `description`, `status` (enum com `CHECK constraint` no PostgreSQL), `created_at`, `updated_at` e `deleted_at`
- [x] Model `Task` com cast do enum, status padrão `pending` e soft delete
- [x] Sincronização entre o status `deleted` e o `deleted_at` (exclusão, restauração e mudança manual de status)
- [x] `TaskFactory` para testes e seeds
- [x] Testes do model (`tests/Feature/TaskTest.php`)

### Etapa 4 — API de tarefas ✅

- [x] `TaskController` com as rotas de CRUD (`Route::apiResource`) e a rota extra `POST /api/tasks/{id}/restore`
- [x] Validação com `StoreTaskRequest` e `UpdateTaskRequest` (o status `deleted` só pode ser atribuído pelo `DELETE`)
- [x] `TaskResource` padronizando o JSON, com o status e o rótulo em português (`status_label`)
- [x] Listagem paginada com filtro por status, incluindo a lixeira (`status=deleted`)
- [x] Parâmetro `{id}` restrito a números (ex.: `/api/tasks/abc` retorna 404, e não erro do PostgreSQL)
- [x] Correção no model: `restore()` em tarefa ativa não altera mais o status
- [x] Testes da API (`tests/Feature/TaskApiTest.php`) e teste de ponta a ponta via `curl`

### Etapa 5 — Coleções do Postman ✅

- [x] Pasta `api-rest/` com as coleções `health` (7 requisições) e `task` (10 requisições)
- [x] Variáveis `base_url` e `task_id` (preenchida automaticamente ao criar uma tarefa)
- [x] Testes em todas as requisições, executáveis pelo Collection Runner
- [x] Coleções validadas com o Newman contra a API rodando

### Etapa 6 — Autenticação com Sanctum ✅

- [x] Trait `HasApiTokens` no model `User`
- [x] `AuthController` com cadastro, login, usuário autenticado e logout (`/api/auth/*`)
- [x] Validação com `RegisterRequest` e `LoginRequest`; credenciais inválidas retornam 422, como na documentação do Sanctum
- [x] `UserResource` padronizando o JSON do usuário; o token vai junto na resposta de cadastro e login
- [x] Rotas de tarefas protegidas com `auth:sanctum`; health check e hello continuam públicos
- [x] Rota padrão `GET /api/user` substituída por `GET /api/auth/me`
- [x] Rate limit de 10 tentativas por minuto por IP no cadastro e no login
- [x] Correção: rotas `api/*` sem token respondem 401 mesmo sem o header `Accept: application/json` (antes: erro 500 "Route [login] not defined")
- [x] Testes de autenticação (`tests/Feature/AuthApiTest.php`) e `TaskApiTest` autenticando com `Sanctum::actingAs`
- [x] Coleção do Postman `auth` e coleção `task` com autenticação Bearer, validadas com o Newman

### Pendências conhecidas

- As tarefas ainda não pertencem a um usuário: qualquer usuário autenticado vê e altera todas as tarefas.
- Os tokens não expiram (`expiration` = `null` em `config/sanctum.php`, padrão do Sanctum); só deixam de valer no logout.
- A fila usa o driver `database`, mas ainda não há um container de worker (`queue:work`).
- As mensagens de validação estão em inglês (`APP_LOCALE=en`).

---

## Decisões técnicas

- **Composer executado em PHP 8.3:** a imagem oficial `composer` usa uma versão mais nova do PHP, o que poderia levar o Composer a escolher pacotes que exigem PHP 8.4+ (por exemplo, Symfony 8). Por isso o projeto foi criado dentro da própria imagem PHP 8.3, garantindo um `composer.lock` compatível.
- **Nginx + PHP-FPM em vez de `php artisan serve`:** é mais próximo de um ambiente real e suporta requisições concorrentes.
- **Controller invocável:** cada endpoint simples tem um controller de ação única (`__invoke`), o que mantém as rotas enxutas.
- **Volume do PostgreSQL 18 em `/var/lib/postgresql`:** a partir da versão 18 a imagem oficial guarda os dados em um subdiretório por versão (`/var/lib/postgresql/18/docker`), e montar o volume no antigo `/var/lib/postgresql/data` gera erro.
- **Credenciais em um só lugar:** o `compose.yaml` lê `DB_DATABASE`, `DB_USERNAME` e `DB_PASSWORD` do `.env` do Laravel para configurar o Postgres.
- **Testes em PostgreSQL:** os testes rodam no mesmo banco usado em produção (banco `testing`), evitando diferenças de comportamento entre SQLite e PostgreSQL. O script de `docker/postgres/init/` só roda na primeira inicialização do volume; se o volume já existir sem esse banco, crie-o com `docker compose exec postgres createdb -U poc03 testing`.
- **Health check do Nginx sem PHP:** o app chama `/nginx-health`, respondido diretamente pelo Nginx, para testar o servidor web sem gerar uma requisição recursiva ao PHP-FPM.
- **Tokens de API em vez de autenticação por cookie (SPA):** a API é consumida por clientes externos (Postman, apps), então foi usado o fluxo de tokens do Sanctum, como na seção *Mobile Application Authentication* da documentação. O modo SPA (cookies + CSRF) não foi habilitado.
- **Visitantes sem redirecionamento:** por padrão o Laravel redireciona usuários não autenticados para a rota `login`, que não existe nesta API. Em `bootstrap/app.php`, `redirectGuestsTo` foi configurado para que rotas `api/*` sempre respondam 401.
- **Token global no Postman:** o token é salvo como variável global para ser compartilhado entre as coleções Auth e Task sem exigir a seleção de um ambiente.

---

## Referências

- [Documentação do Laravel 13](https://laravel.com/docs/13.x)
- [Laravel — Banco de dados](https://laravel.com/docs/13.x/database)
- [Laravel — API e Sanctum](https://laravel.com/docs/13.x/sanctum)
- [Imagem oficial do PHP no Docker Hub](https://hub.docker.com/_/php)
- [Imagem oficial do PostgreSQL no Docker Hub](https://hub.docker.com/_/postgres)
