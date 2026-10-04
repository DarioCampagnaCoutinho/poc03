# Regras de negócio

## RN01 — Tarefas compartilhadas entre usuários logados

> **Para usar as tarefas é obrigatório estar logado. Depois de logado, o usuário vê e altera todas as tarefas, de todos os usuários.**

- Sem login (sem token, com token inválido ou com token revogado no logout), **nenhuma** operação com tarefas é permitida.
- Com login, **qualquer** usuário pode listar, consultar, criar, alterar, excluir e restaurar **qualquer** tarefa, inclusive as criadas por outras pessoas.
- As tarefas **não têm dono**: a API não registra nem verifica quem criou cada tarefa.

### Como a API decide

```mermaid
flowchart TD
    REQ["Requisição para /api/tasks<br/>listar, consultar, criar, alterar, excluir ou restaurar"]
    TOKEN{"Enviou o header<br/>Authorization: Bearer?"}
    VALIDO{"O token é válido?<br/>existe e não foi revogado no logout"}
    NEGADO["401 Unauthenticated<br/>nenhuma operação é permitida"]
    LIBERADO["Acesso liberado a TODAS as tarefas,<br/>de todos os usuários"]
    NOTA["Não existe verificação de dono:<br/>a API não pergunta quem criou a tarefa"]

    REQ --> TOKEN
    TOKEN -- Não --> NEGADO
    TOKEN -- Sim --> VALIDO
    VALIDO -- Não --> NEGADO
    VALIDO -- Sim --> LIBERADO
    LIBERADO -.- NOTA

    classDef negado fill:#fdecea,stroke:#c0392b,color:#7b241c
    classDef liberado fill:#e8f6ec,stroke:#1e8449,color:#145a32
    classDef nota fill:#fff8e1,stroke:#b7950b,color:#7d6608,stroke-dasharray: 4 3
    class NEGADO negado
    class LIBERADO liberado
    class NOTA nota
```

### Exemplo com dois usuários

A Ana cria uma tarefa. O Bruno, logado com a própria conta, enxerga e altera essa tarefa normalmente. Um visitante sem login é bloqueado.

```mermaid
sequenceDiagram
    actor Ana
    actor Bruno
    participant API as API de tarefas
    actor Visitante as Visitante (sem login)

    Ana->>API: POST /api/tasks (token da Ana)
    API-->>Ana: 201 tarefa 1 criada

    Note over Bruno,API: Bruno é outro usuário, logado com o próprio token
    Bruno->>API: GET /api/tasks
    API-->>Bruno: 200 a lista inclui a tarefa 1 da Ana
    Bruno->>API: PATCH /api/tasks/1 (status completed)
    API-->>Bruno: 200 tarefa da Ana alterada
    Bruno->>API: DELETE /api/tasks/1
    API-->>Bruno: 204 tarefa da Ana enviada para a lixeira

    Visitante->>API: GET /api/tasks (sem token)
    API-->>Visitante: 401 Unauthenticated
```

### Quem pode fazer o quê

| Operação | Sem login | Logado (qualquer usuário) |
|---|---|---|
| Listar tarefas (inclusive a lixeira) | ❌ `401` | ✅ todas as tarefas |
| Consultar uma tarefa | ❌ `401` | ✅ qualquer tarefa |
| Criar tarefa | ❌ `401` | ✅ |
| Alterar título, descrição ou status | ❌ `401` | ✅ qualquer tarefa |
| Excluir (enviar para a lixeira) | ❌ `401` | ✅ qualquer tarefa |
| Restaurar da lixeira | ❌ `401` | ✅ qualquer tarefa |

### Onde a regra está implementada

| Parte da regra | Implementação |
|---|---|
| Exigir login | Middleware `auth:sanctum` em todas as rotas de tarefas ([`routes/api.php`](../routes/api.php)) |
| Acesso a todas as tarefas | O `TaskController` consulta as tarefas sem filtrar por usuário, e a tabela `tasks` não tem coluna de dono |
| Testes | `TaskApiTest::test_routes_require_authentication` verifica o `401` em cada uma das 6 rotas de tarefas |
