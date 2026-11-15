#!/bin/sh
set -e

# Garante um unico MPM (prefork, exigido pelo mod_php). Evita o erro
# "AH00534: More than one MPM loaded" caso outro MPM apareca habilitado.
for f in /etc/apache2/mods-enabled/mpm_*.load; do
    [ -e "$f" ] || continue
    name=$(basename "$f" .load)
    if [ "$name" != "mpm_prefork" ]; then
        echo "[entrypoint] desativando $name"
        rm -f "/etc/apache2/mods-enabled/$name.load" "/etc/apache2/mods-enabled/$name.conf"
    fi
done
if [ ! -e /etc/apache2/mods-enabled/mpm_prefork.load ]; then
    ln -s ../mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load
    ln -s ../mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf
fi

# Cria as tabelas na primeira subida (nao faz nada se ja existirem)
php database/migrate.php

exec "$@"
