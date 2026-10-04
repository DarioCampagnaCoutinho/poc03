-- Banco usado pela suíte de testes (phpunit.xml).
-- Executado apenas na primeira inicialização do volume do Postgres.
SELECT 'CREATE DATABASE testing'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'testing')\gexec
