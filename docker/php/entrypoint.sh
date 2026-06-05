#!/bin/sh
# Entrypoint für core/worker: macht frische Klone "clone & up"-fähig.
set -e
cd /var/www/html

ROLE="${ROLE:-core}"

# 1. Abhängigkeiten: bei fehlendem vendor installieren (nur core),
#    worker wartet, bis core das vendor-Verzeichnis erstellt hat (kein Race).
if [ "$ROLE" = "core" ]; then
  if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] vendor fehlt -> composer install"
    composer install --no-interaction --no-progress --prefer-dist
  fi
else
  echo "[entrypoint] role=$ROLE: warte auf vendor (von core erstellt) ..."
  while [ ! -f vendor/autoload.php ]; do sleep 2; done
fi

# 2. Auf die Datenbank warten
echo "[entrypoint] warte auf Datenbank ${DB_HOST:-db}:${DB_PORT:-5432} ..."
until pg_isready -h "${DB_HOST:-db}" -p "${DB_PORT:-5432}" >/dev/null 2>&1; do
  sleep 1
done
echo "[entrypoint] Datenbank erreichbar"

# 3. Migrationen (nur core; geguardet, solange kein migrations-Plugin vorhanden)
if [ "$ROLE" = "core" ]; then
  if bin/cake migrations --help >/dev/null 2>&1; then
    echo "[entrypoint] migrations migrate"
    bin/cake migrations migrate || echo "[entrypoint] WARN: migrate fehlgeschlagen"
  else
    echo "[entrypoint] migrations-Plugin (noch) nicht verfuegbar -> uebersprungen"
  fi
fi

echo "[entrypoint] starte: $*"
exec "$@"
