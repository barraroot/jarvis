SELECT 'CREATE DATABASE wuzapi'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'wuzapi')\gexec
