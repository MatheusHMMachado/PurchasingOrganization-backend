#!/bin/sh
set -e

# Cria as tabelas na primeira subida (nao faz nada se ja existirem)
php database/migrate.php

exec "$@"
