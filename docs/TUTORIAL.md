# Tutorial: usando a API da POC 03

Este tutorial mostra, passo a passo, como subir o sistema e usar a API: fazer login, gerenciar tarefas do início ao fim e ver as permissões funcionando. Você pode seguir pelo **terminal** (com `curl`) ou pelo **Postman**.

Tempo estimado: 15 minutos.

> Procurando a referência completa de endpoints, campos e decisões técnicas? Veja o [README](../README.md).

## Sumário

1. [Como o sistema funciona](#1-como-o-sistema-funciona)
2. [Subir o ambiente](#2-subir-o-ambiente)
3. [Fazer login](#3-fazer-login)
4. [Trabalhar com tarefas](#4-trabalhar-com-tarefas)
5. [Sair (logout)](#5-sair-logout)
6. [Usando o Postman](#6-usando-o-postman)
7. [Quando algo dá errado](#7-quando-algo-dá-errado)
8. [Desligar e reiniciar do zero](#8-desligar-e-reiniciar-do-zero)
9. [Referência rápida](#9-referência-rápida)

---

## 1. Como o sistema funciona

A POC 03 é uma **API REST de tarefas** (to-do list). Ela não tem telas: você conversa com ela enviando requisições HTTP e recebe respostas em JSON.

```
Você (curl / Postman)
        │  http://localhost:8000/api/...
        ▼
     Nginx  ──►  PHP / Laravel  ──►  PostgreSQL
```

O uso segue sempre o mesmo caminho:

```
Login (recebe um token)  ──►  Usar as tarefas com o token  ──►  Logout
```

- O **token** é a sua "chave de acesso": todas as rotas de tarefas exigem que ele seja enviado no header `Authorization: Bearer <token>`.
- Não existe cadastro: os usuários são criados pelo **administrador**, que também define o que cada um pode fazer (as **permissões**). Por exemplo, um usuário pode só visualizar as tarefas, enquanto outro pode criar, alterar e excluir.
- As tarefas são **compartilhadas**: quem tem a permissão de uma operação pode realizá-la em qualquer tarefa, inclusive nas criadas por outras pessoas.

Os diagramas dessas regras estão em [Regras de negócio](REGRAS-DE-NEGOCIO.md).

---

## 2. Subir o ambiente

### Pré-requisitos

- **Docker Desktop** instalado e aberto (o ícone da baleia precisa estar ativo)
- Um terminal (os exemplos usam zsh/bash, padrão no macOS e no Linux)
- Portas **8000** e **5432** livres
- Opcional: [Postman](https://www.postman.com/downloads/), para usar a API sem o terminal

Não é preciso instalar PHP, Composer nem PostgreSQL: tudo roda dentro dos containers.

### Primeira vez

Na pasta do projeto, rode:

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed
```

O primeiro `up --build` demora alguns minutos, porque a imagem do PHP é construída. O último comando (`db:seed`) cria as permissões, os grupos e os usuários de desenvolvimento usados neste tutorial.

### Nas próximas vezes

Basta subir os containers:

```bash
docker compose up -d
```

### Conferir se está tudo no ar

Veja se os três containers estão rodando:

```bash
docker compose ps
```

```
NAME             STATUS
poc03-app        Up 2 minutes
poc03-nginx      Up 2 minutes
poc03-postgres   Up 2 minutes (healthy)
```

E pergunte à própria API se os serviços estão saudáveis:

```bash
curl http://localhost:8000/api/health
```

```json
{"status":"ok","services":{"php":"ok","nginx":"ok","postgresql":"ok"}}
```

Se algum serviço aparecer como `"error"`, veja a seção [Quando algo dá errado](#7-quando-algo-dá-errado).

---

## 3. Fazer login

> **Dica:** abra um terminal e use o mesmo durante todo o tutorial. As variáveis e funções criadas aqui (como `TOKEN`) só existem na sessão em que foram definidas.

### 3.1 Usuários disponíveis

O `db:seed` criou três usuários, todos com a senha `password`:

| Usuário | E-mail | Grupo (papel) | Pode |
|---|---|---|---|
| Dario | `dario@example.com` | `super-admin` | Tudo |
| Maria | `maria@example.com` | `leitor` | Fazer login e visualizar tarefas |
| Ana | `ana@example.com` | `leitor` | Fazer login e visualizar tarefas |

Neste tutorial você vai usar o **Dario**, que pode tudo. No [passo 4.8](#48-permissões-na-prática-entrando-como-maria) você entra como Maria para ver as permissões em ação.

### 3.2 Fazer login e guardar o token

O comando abaixo faz o login e guarda o token na variável `TOKEN`:

```bash
TOKEN=$(curl -s -X POST http://localhost:8000/api/auth/login \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"email": "dario@example.com", "password": "password"}' \
  | python3 -c 'import sys, json; print(json.load(sys.stdin)["token"])')

echo $TOKEN
```

```
1|N6zGoFsyulIUyKFtMoc5xnEnUJgqe5lsw89i1uNFbbff78bf
```

> Sem `python3`? Rode o login sem a parte do `| python3 ...`, copie o valor de `"token"` da resposta e guarde manualmente: `TOKEN='1|N6zGo...'`.

Cada login gera um **token novo**. O token é exibido só nesse momento; o banco guarda apenas uma versão criptografada (hash) dele.

### 3.3 Conferir quem está logado

```bash
curl http://localhost:8000/api/auth/me \
  -H 'Accept: application/json' \
  -H "Authorization: Bearer $TOKEN"
```

Resposta (formatada para facilitar a leitura):

```json
{
  "data": {
    "id": 1,
    "name": "Dario",
    "email": "dario@example.com",
    "email_verified_at": null,
    "roles": ["super-admin"],
    "permissions": ["tasks.create", "tasks.delete", "tasks.restore", "tasks.update", "tasks.view"],
    "created_at": "2026-10-04T20:25:47.000000Z",
    "updated_at": "2026-10-04T20:25:47.000000Z"
  }
}
```

- `roles`: os grupos do usuário.
- `permissions`: o que ele pode fazer com as tarefas.

### 3.4 Um atalho para os próximos passos

Toda requisição precisa dos mesmos headers. Para não repeti-los, crie esta função no terminal (copie e cole o bloco inteiro):

```bash
api() {
  local metodo=$1 rota=$2
  shift 2
  curl -s -X "$metodo" "http://localhost:8000/api$rota" \
    -H 'Accept: application/json' \
    -H 'Content-Type: application/json' \
    -H "Authorization: Bearer $TOKEN" \
    -w '\nHTTP %{http_code}\n' \
    "$@"
}
```

A partir de agora, `api GET /tasks` equivale ao `curl` completo, já com o token, e mostra o **código HTTP** no final da resposta.

> **Usuário de zsh:** não renomeie a variável `rota` para `path`. No zsh, `path` é uma variável especial ligada ao `PATH`, e usá-la dentro da função faz o `curl` "desaparecer" (`command not found`).

---

## 4. Trabalhar com tarefas

### 4.1 Criar tarefas

Só o `title` é obrigatório. A tarefa nasce como **pendente**, a não ser que você informe outro `status`.

```bash
api POST /tasks -d '{"title": "Configurar ambiente", "description": "Subir os containers e rodar as migrations"}'
api POST /tasks -d '{"title": "Estudar a API de tarefas"}'
api POST /tasks -d '{"title": "Escrever testes", "status": "in_progress"}'
```

Resposta da primeira (`HTTP 201`):

```json
{
  "data": {
    "id": 1,
    "title": "Configurar ambiente",
    "description": "Subir os containers e rodar as migrations",
    "status": "pending",
    "status_label": "Pendente",
    "created_at": "2026-10-04T19:15:11.000000Z",
    "updated_at": "2026-10-04T19:15:11.000000Z",
    "deleted_at": null
  }
}
HTTP 201
```

- `status` é o valor técnico; `status_label` é o nome em português, pronto para exibir.
- Os horários estão em UTC (o `Z` no final).
- Anote os `id`s: eles serão usados nos próximos passos. **No seu ambiente os números podem ser diferentes**; ajuste os comandos conforme os ids que você recebeu.

### 4.2 Listar tarefas

```bash
api GET /tasks
```

A listagem traz as tarefas da **mais recente para a mais antiga**, em páginas de 15. Além de `data` (as tarefas), a resposta tem dois blocos de paginação:

- `links`: URLs da primeira, última, anterior e próxima página
- `meta`: página atual (`current_page`), total de páginas (`last_page`), itens por página (`per_page`) e total de tarefas (`total`)

Para mudar o tamanho da página e navegar:

```bash
api GET '/tasks?per_page=2'          # 2 por página (de 1 a 100)
api GET '/tasks?per_page=2&page=2'   # segunda página
```

> Use aspas simples na rota quando ela tiver `?` ou `&`; sem elas, o zsh tenta interpretar esses caracteres.

### 4.3 Filtrar por status

```bash
api GET '/tasks?status=in_progress'
```

Valores aceitos em `status`:

| Valor | Significado |
|---|---|
| `pending` | Pendente |
| `in_progress` | Em andamento |
| `completed` | Concluído |
| `cancelled` | Cancelado |
| `deleted` | Excluído (lista a lixeira; veja o passo 4.7) |

### 4.4 Consultar uma tarefa

```bash
api GET /tasks/1
```

### 4.5 Mudar o status

Envie só o campo que quer alterar; o resto da tarefa fica como está.

```bash
api PATCH /tasks/1 -d '{"status": "in_progress"}'
api PATCH /tasks/1 -d '{"status": "completed"}'
api PATCH /tasks/2 -d '{"status": "cancelled"}'
```

```json
{"data":{"id":1,"title":"Configurar ambiente","description":"Subir os containers e rodar as migrations","status":"completed","status_label":"Concluído","created_at":"2026-10-04T19:15:11.000000Z","updated_at":"2026-10-04T19:15:26.000000Z","deleted_at":null}}
HTTP 200
```

> `Concluído` é só a forma como o JSON escreve o "í"; qualquer cliente JSON exibe "Concluído".

O fluxo **sugerido** para uma tarefa é:

```
pending ──► in_progress ──► completed
   │              │
   └──────────────┴──► cancelled
```

A API não obriga essa ordem: você pode mudar para qualquer status a qualquer momento, **exceto `deleted`**, que só é atribuído pela exclusão (passo 4.7). Tentar `{"status": "deleted"}` retorna erro `422`.

### 4.6 Editar título e descrição

```bash
api PATCH /tasks/2 -d '{"title": "Estudar a API de tarefas e o Postman", "description": "Seguir o tutorial"}'
```

Para apagar a descrição, envie `null`:

```bash
api PATCH /tasks/2 -d '{"description": null}'
```

Também existe o `PUT`, com o mesmo comportamento do `PATCH` nesta API.

### 4.7 Excluir, ver a lixeira e restaurar

A exclusão não apaga a tarefa de verdade: ela vai para a **lixeira**, com status `deleted` e o horário da exclusão em `deleted_at`.

**Excluir:**

```bash
api DELETE /tasks/3
```

```
HTTP 204
```

`204` significa "deu certo, sem conteúdo na resposta".

**A tarefa some das consultas normais:**

```bash
api GET /tasks/3
```

```
{"message": "No query results for model [App\\Models\\Task] 3", ...}
HTTP 404
```

> Em ambiente local (`APP_DEBUG=true`), respostas de erro como esta vêm acompanhadas de um rastreamento (`trace`) longo. O que importa é o campo `message`.

**Ver a lixeira:**

```bash
api GET '/tasks?status=deleted'
```

```json
{"data":[{"id":3,"title":"Escrever testes","description":null,"status":"deleted","status_label":"Excluído","created_at":"2026-10-04T19:15:11.000000Z","updated_at":"2026-10-04T19:15:26.000000Z","deleted_at":"2026-10-04T19:15:26.000000Z"}], "links": {...}, "meta": {...}}
HTTP 200
```

**Restaurar:** a tarefa volta como **pendente**, qualquer que fosse o status antes da exclusão.

```bash
api POST /tasks/3/restore
```

```json
{"data":{"id":3,"title":"Escrever testes","description":null,"status":"pending","status_label":"Pendente","created_at":"2026-10-04T19:15:11.000000Z","updated_at":"2026-10-04T19:15:26.000000Z","deleted_at":null}}
HTTP 200
```

### 4.8 Permissões na prática: entrando como Maria

Até aqui você usou o Dario, que pode tudo. A Maria está no grupo `leitor`, que só tem a permissão de **visualizar** tarefas. Guarde o token dela em outra variável:

```bash
TOKEN_DARIO=$TOKEN

TOKEN=$(curl -s -X POST http://localhost:8000/api/auth/login \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"email": "maria@example.com", "password": "password"}' \
  | python3 -c 'import sys, json; print(json.load(sys.stdin)["token"])')
```

Como a função `api` usa a variável `TOKEN`, as próximas requisições saem como Maria. Listar funciona:

```bash
api GET /tasks
```

```
{"data":[...], "links": {...}, "meta": {...}}
HTTP 200
```

Criar, alterar, excluir ou restaurar, não:

```bash
api POST /tasks -d '{"title": "Tarefa da Maria"}'
```

```
{"message": "This action is unauthorized.", ...}
HTTP 403
```

Repare na diferença entre os dois erros de acesso:

| Código | Significado |
|---|---|
| `401` | A API **não sabe quem você é** (sem token, token inválido ou revogado) |
| `403` | A API **sabe quem você é**, mas você **não tem permissão** para essa operação |

Para voltar a usar o Dario:

```bash
TOKEN=$TOKEN_DARIO
```

> Quem define as permissões é o administrador. Por enquanto isso é feito pelo Tinker; os comandos estão na seção [Autorização do README](../README.md#autorização-papéis-e-permissões).

---

## 5. Sair (logout)

O logout invalida o token usado na requisição:

```bash
api POST /auth/logout
```

```
HTTP 204
```

A partir daí, o mesmo token é recusado:

```bash
api GET /auth/me
```

```json
{"message":"Unauthenticated."}
HTTP 401
```

Para continuar usando a API, faça login de novo ([passo 3.2](#32-fazer-login-e-guardar-o-token)). Se você fez login em mais de um lugar, cada um tem seu próprio token, e o logout só invalida aquele que foi enviado.

---

## 6. Usando o Postman

A pasta `api-rest/` traz coleções prontas com todas as requisições deste tutorial.

### Importar

1. Abra o Postman e clique em **Import**.
2. Arraste a pasta `api-rest` do projeto.
3. Três coleções aparecem: **POC 03 - Auth**, **POC 03 - Health** e **POC 03 - Task**.

### Usar

1. Em **POC 03 - Health**, envie **Health check** para confirmar que tudo está no ar.
2. Em **POC 03 - Auth**, envie **Login**. Por padrão ele entra como Dario (`super-admin`).
   O token é salvo automaticamente na variável global `token`; você não precisa copiá-lo.
3. Em **POC 03 - Task**, envie as requisições na ordem em que aparecem: criar, listar, consultar, atualizar, excluir, ver a lixeira e restaurar.
   A requisição **Criar tarefa** guarda o id da tarefa criada, e as seguintes usam esse id sozinhas.
4. As últimas requisições da coleção Task entram como Maria (`leitor`): ela consegue listar (200), mas não consegue criar (403).

Para entrar com outro usuário, troque a variável `email` na aba **Variables** da coleção Auth e envie **Login** de novo.

Para rodar uma coleção inteira de uma vez, clique nos três pontos da coleção → **Run collection**. Cada requisição tem testes automáticos, e o Runner mostra quais passaram.

> Se a coleção Task responder `401`, o token salvo foi invalidado (por exemplo, pela requisição **Logout**) ou ainda não existe. Rode **Login** de novo na coleção Auth.

Para mudar o endereço da API (por exemplo, outra porta), edite a variável `base_url` na aba **Variables** de cada coleção.

---

## 7. Quando algo dá errado

| Sintoma | Causa provável | O que fazer |
|---|---|---|
| `curl: (7) Failed to connect to localhost port 8000` | Containers parados ou Docker fechado | Abra o Docker Desktop e rode `docker compose up -d` |
| `HTTP 401` com `"Unauthenticated."` | Token ausente, digitado errado ou já invalidado pelo logout | Faça login de novo e atualize a variável `TOKEN` |
| `HTTP 403` com `"This action is unauthorized."` | Seu usuário não tem a permissão dessa operação | Confira suas permissões com `api GET /auth/me` e peça ao administrador |
| `HTTP 404` em `/tasks/{id}` | A tarefa não existe ou está na lixeira | Confira o id com `api GET /tasks` ou `api GET '/tasks?status=deleted'` |
| `HTTP 422` | Dados inválidos | Leia o bloco `errors` da resposta: ele indica o campo e o problema (tabela abaixo) |
| `HTTP 422` no login com `"These credentials do not match our records."` | E-mail ou senha errados, ou o usuário não existe | Confira os dados; se o banco foi recriado, rode `docker compose exec app php artisan db:seed` |
| `HTTP 429` | Mais de 10 tentativas de login em 1 minuto | Aguarde um minuto e tente de novo |
| `/api/health` com `HTTP 503` | Algum serviço com `"error"` | Veja `docker compose ps` e os logs: `docker compose logs postgres` ou `storage/logs/laravel.log` |
| `command not found: curl` dentro da função `api` | A função usa uma variável chamada `path` no zsh | Use a função exatamente como no [passo 3.4](#34-um-atalho-para-os-próximos-passos) |
| Erro `port is already allocated` ao subir | Porta 8000 ou 5432 ocupada por outro programa | Feche o programa ou altere a porta em `compose.yaml` |

### Mensagens de validação (422)

As mensagens da API estão em inglês. As mais comuns:

| Mensagem | Significado |
|---|---|
| `The title field is required.` | Faltou o título da tarefa |
| `The selected status is invalid.` | Status inexistente, ou tentativa de usar `deleted` (use o `DELETE`) |
| `These credentials do not match our records.` | E-mail ou senha incorretos no login |
| `The email field is required.` / `The password field is required.` | Faltou o e-mail ou a senha no login |

---

## 8. Desligar e reiniciar do zero

Parar os containers (os dados continuam salvos):

```bash
docker compose down
```

Apagar **todos os dados** (usuários e tarefas) e começar do zero:

```bash
docker compose down -v
docker compose up -d
docker compose exec app php artisan migrate
docker compose exec app php artisan db:seed
```

---

## 9. Referência rápida

```bash
# Ambiente
docker compose up -d
curl http://localhost:8000/api/health

# Login como Dario, super-admin (guarda o token)
TOKEN=$(curl -s -X POST http://localhost:8000/api/auth/login \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email": "dario@example.com", "password": "password"}' \
  | python3 -c 'import sys, json; print(json.load(sys.stdin)["token"])')

# Quem sou eu e o que posso fazer (com a função api do passo 3.4)
api GET /auth/me

# Tarefas (com a função api do passo 3.4)
api POST   /tasks -d '{"title": "Nova tarefa"}'    # criar
api GET    /tasks                                  # listar
api GET    '/tasks?status=pending'                 # filtrar
api GET    /tasks/1                                # consultar
api PATCH  /tasks/1 -d '{"status": "completed"}'   # atualizar
api DELETE /tasks/1                                # excluir (vai para a lixeira)
api GET    '/tasks?status=deleted'                 # ver a lixeira
api POST   /tasks/1/restore                        # restaurar

# Sair
api POST /auth/logout
```

| Código | Significado |
|---|---|
| `200` | Sucesso |
| `201` | Criado com sucesso |
| `204` | Sucesso, sem conteúdo na resposta |
| `401` | Sem token ou token inválido |
| `403` | Sem permissão para a operação |
| `404` | Não encontrado (ou na lixeira) |
| `422` | Dados inválidos; veja `errors` |
| `429` | Muitas tentativas; aguarde um minuto |
| `503` | Algum serviço fora do ar (health check) |
