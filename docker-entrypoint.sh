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

# Portas: IPv4 sempre; IPv6 quando o container tiver IPv6. A rede privada do Railway
# (*.railway.internal) usa IPv6: sem isso o health check passa, mas o frontend
# recebe "connection refused" e responde 502.
PORT="${PORT:-8080}"
echo "Listen 0.0.0.0:${PORT}" > /etc/apache2/ports.conf
if grep -q . /proc/net/if_inet6 2>/dev/null; then
    echo "Listen [::]:${PORT}" >> /etc/apache2/ports.conf
fi
echo "[entrypoint] $(tr '\n' ' ' < /etc/apache2/ports.conf)"

# Cria as tabelas na primeira subida (nao faz nada se ja existirem)
php database/migrate.php

exec "$@"
