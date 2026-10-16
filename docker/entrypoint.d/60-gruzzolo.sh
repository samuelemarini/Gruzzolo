#!/usr/bin/env bash
# Avvio di Gruzzolo: migration e primo admin.
#
# L'entrypoint webdevops lo esegue da root a ogni avvio: fa qualcosa solo dove RUN_MIGRATIONS=1.
# Niente "exit" e niente "set -e": lo script puo' essere incluso con "source" dall'entrypoint.

if [ "${RUN_MIGRATIONS:-0}" = "1" ]; then
    console() { gosu application php /app/bin/console "$@"; }

    echo "[gruzzolo] migration del database..."
    for attempt in $(seq 1 30); do
        if console doctrine:migrations:migrate --no-interaction --allow-no-migration; then
            break
        fi
        echo "[gruzzolo] database non ancora pronto, riprovo tra 3 secondi ($attempt/30)"
        sleep 3
    done

    if [ -n "${ADMIN_PASSWORD:-}" ]; then
        if console app:user:create "${ADMIN_USERNAME:-admin}" "Admin" --admin --password="$ADMIN_PASSWORD" --quiet; then
            echo "[gruzzolo] creato l'utente admin '${ADMIN_USERNAME:-admin}'"
        else
            echo "[gruzzolo] utente '${ADMIN_USERNAME:-admin}' gia' presente, non lo tocco"
        fi
    fi
fi
