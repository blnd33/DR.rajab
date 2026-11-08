-- OrthoTrack TEST SITE database (separate from the real site's database).
-- Edit the password, then run once on the server:
--   sudo -u postgres psql -f deploy/test-site/create-db.sql
-- The password must match DB_PASSWORD in /var/www/orthotrack-test/.env.

DO $$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'orthotrack_testsite') THEN
        CREATE ROLE orthotrack_testsite LOGIN PASSWORD 'CHANGE-ME-strong-password';
    END IF;
END
$$;

SELECT 'CREATE DATABASE orthotrack_testsite OWNER orthotrack_testsite ENCODING ''UTF8'' TEMPLATE template0'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'orthotrack_testsite') \gexec

\connect orthotrack_testsite
CREATE EXTENSION IF NOT EXISTS pg_trgm;
