-- Local development databases for OrthoTrack.
-- Run once as the postgres superuser (psql asks for its password):
--   "C:\Program Files\PostgreSQL\18\bin\psql.exe" -U postgres -h 127.0.0.1 -f deploy/local/create-local-db.sql
-- On Ubuntu / WSL:
--   sudo -u postgres psql -f deploy/local/create-local-db.sql
--
-- The role password matches DB_PASSWORD in .env.example (local only — use a strong one on servers).

DO $$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'orthotrack') THEN
        CREATE ROLE orthotrack LOGIN PASSWORD 'secret';
    END IF;
END
$$;

SELECT 'CREATE DATABASE orthotrack OWNER orthotrack ENCODING ''UTF8'' TEMPLATE template0'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'orthotrack') \gexec

SELECT 'CREATE DATABASE orthotrack_test OWNER orthotrack ENCODING ''UTF8'' TEMPLATE template0'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'orthotrack_test') \gexec

-- pg_trgm is created by the first migration; the owner needs no superuser for it on PG 13+.
\connect orthotrack
CREATE EXTENSION IF NOT EXISTS pg_trgm;
\connect orthotrack_test
CREATE EXTENSION IF NOT EXISTS pg_trgm;
