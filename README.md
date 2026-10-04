# POC 03 — API REST com Laravel 13 + Docker

Prova de conceito de uma API REST em **Laravel 13**, rodando em **PHP 8.3** dentro de containers Docker (PHP-FPM + Nginx).
O projeto está sendo construído por etapas; o histórico do que já foi entregue está em [Status do projeto](#status-do-projeto).

---

## Tecnologias e versões

| Tecnologia | Versão | Observação |
|---|---|---|
| PHP | 8.3.35 | Imagem oficial `php:8.3-fpm` (Debian 13 "trixie") |
| Laravel Framework | 13.34.0 | Restrição no `composer.json`: `^13.17` |
| Symfony (componentes) | 7.4 | Resolvido para manter compatibilidade com PHP 8.3 |
| Laravel Sanctum | 4.3.3 | Autenticação de API (instalado, ainda não utilizado) |
| Composer | 2.10.3 | Copiado da imagem `composer:2` |
| Nginx | 1.31.6 | Imagem `nginx:alpine` |
| SQLite | — | Banco padrão (`database/database.sqlite`) |
| PHPUnit | 12.5.37 | Testes |
| Laravel Pint | 1.32.1 | Formatação de código |
| Docker Engine / Compose | 29.7.2 / v5.4.0 | Versões usadas no desenvolvimento |

**Extensões PHP adicionadas na imagem:** `bcmath`, `intl`, `opcache`, `zip` (além das que já vêm na imagem oficial, como `pdo_sqlite`, `mbstring` e `openssl`).

> As versões do Nginx (tag `nginx:alpine`) e do patch do PHP (tag `php:8.3-fpm`) podem mudar em um novo build. Os valores acima foram registrados em 03/10/2026.

---

## Arquitetura

```
        Cliente HTTP
             │  :8000
             ▼
   ┌───────────────────┐   FastCGI :9000   ┌────────────────────┐
   │   poc03-nginx     │ ────────────────▶ │     poc03-app      │
   │   nginx:alpine    │                   │  php:8.3-fpm       │
   │   serve /public   │                   │  Laravel 13        │
   └───────────────────┘                   └────────────────────┘
             │                                       │
             └──────────── bind mount: . → /var/www/html
```

- **app**: PHP-FPM com Composer. Executa a aplicação Laravel e os comandos `artisan`/`composer`.
- **nginx**: recebe as requisições na porta `8000` e repassa os scripts PHP para o `app`.
- O código-fonte é montado como volume nos dois containers, então alterações no código têm efeito imediato, sem rebuild.

---

## Estrutura relevante

```
.
├── Dockerfile                      # Imagem PHP 8.3-FPM + extensões + Composer
├── compose.yaml                    # Serviços app (PHP-FPM) e nginx
├── .dockerignore
├── docker/
│   └── nginx/
│       └── default.conf            # Virtual host do Nginx para o Laravel
├── app/Http/Controllers/Api/
│   └── HelloWorldController.php    # Controller do endpoint /api/hello
└── routes/
    └── api.php                     # Rotas da API (prefixo /api)
```

---

## Como executar

### Pré-requisitos

- Docker Desktop (ou Docker Engine) com Docker Compose

Não é necessário ter PHP ou Composer instalados na máquina: tudo roda dentro do container.

### Primeira execução (após clonar)

```bash
# 1. Variáveis de ambiente
cp .env.example .env

# 2. Build e subida dos containers
docker compose up -d --build

# 3. Dependências PHP
docker compose exec app composer install

# 4. Chave da aplicação
docker compose exec app php artisan key:generate

# 5. Banco SQLite + migrations
touch database/database.sqlite
docker compose exec app php artisan migrate
```

A API fica disponível em **http://localhost:8000**.

### Uso diário

```bash
docker compose up -d        # subir os containers
docker compose down         # parar e remover os containers
docker compose logs -f      # acompanhar os logs
```

---

## Endpoints

Todas as rotas da API ficam sob o prefixo `/api`.

| Método | Rota | Descrição | Autenticação |
|---|---|---|---|
| `GET` | `/api/hello` | Retorna uma mensagem de Hello World | Não |
| `GET` | `/api/user` | Retorna o usuário autenticado (rota padrão do Sanctum) | `auth:sanctum` |
| `GET` | `/up` | Health check da aplicação | Não |

### Exemplo

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
docker compose exec app php artisan make:controller Api/NomeController

# Composer
docker compose exec app composer require vendor/pacote

# Testes
docker compose exec app php artisan test

# Formatação de código (Pint)
docker compose exec app ./vendor/bin/pint

# Shell dentro do container
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

### Pendências conhecidas

- Adicionar o trait `Laravel\Sanctum\HasApiTokens` ao model `User` quando a autenticação for implementada.
- O banco atual é SQLite; um serviço de MySQL/PostgreSQL pode ser adicionado ao `compose.yaml` em uma próxima etapa.

---

## Decisões técnicas

- **Composer executado em PHP 8.3:** a imagem oficial `composer` usa uma versão mais nova do PHP, o que poderia levar o Composer a escolher pacotes que exigem PHP 8.4+ (por exemplo, Symfony 8). Por isso o projeto foi criado dentro da própria imagem PHP 8.3, garantindo um `composer.lock` compatível.
- **Nginx + PHP-FPM em vez de `php artisan serve`:** é mais próximo de um ambiente real e suporta requisições concorrentes.
- **Controller invocável:** cada endpoint simples tem um controller de ação única (`__invoke`), o que mantém as rotas enxutas.

---

## Referências

- [Documentação do Laravel 13](https://laravel.com/docs/13.x)
- [Laravel — API e Sanctum](https://laravel.com/docs/13.x/sanctum)
- [Imagem oficial do PHP no Docker Hub](https://hub.docker.com/_/php)
