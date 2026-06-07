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

# Managed Locale Store (i18n) fuer den Laufzeit-Nutzer (www-data) beschreibbar machen.
mkdir -p /var/www/html/language-store 2>/dev/null || true
chown -R www-data:www-data /var/www/html/language-store 2>/dev/null || true
mkdir -p /var/www/html/backups 2>/dev/null || true
chown -R www-data:www-data /var/www/html/backups 2>/dev/null || true

# 3. Schema-Bootstrap + Migrationen (nur core; geguardet)
#    Bootstrap-Schritte laufen als Superuser: APP_DATABASE_URL wird geleert,
#    sodass die Default-Connection auf DATABASE_URL (Superuser) zurueckfaellt.
#    DDL/Migrationen brauchen Superuser; die App-Rolle (NOBYPASSRLS) wird hier
#    erst provisioniert. php-fpm startet danach mit vollem Env (App-Rolle).
if [ "$ROLE" = "core" ]; then
  if bin/cake migrations --help >/dev/null 2>&1; then
    # core-Schema bereitstellen, BEVOR der Runner seine Trackingtabelle anlegt.
    echo "[entrypoint] schema_init"
    env APP_DATABASE_URL= bin/cake schema_init || echo "[entrypoint] WARN: schema_init fehlgeschlagen"
    echo "[entrypoint] migrations migrate"
    env APP_DATABASE_URL= bin/cake migrations migrate || echo "[entrypoint] WARN: migrate fehlgeschlagen"
    # NOBYPASSRLS-App-Rolle + Rechte (idempotent; nur wenn APP_DB_PASSWORD gesetzt).
    echo "[entrypoint] db_provision_app_role"
    env APP_DATABASE_URL= bin/cake db_provision_app_role || echo "[entrypoint] WARN: provisioning fehlgeschlagen"
    # Audit-Log-Monatspartitionen sicherstellen (vor dem ersten Schreiben).
    echo "[entrypoint] audit_partition"
    env APP_DATABASE_URL= bin/cake audit_partition || echo "[entrypoint] WARN: audit_partition fehlgeschlagen"
  else
    echo "[entrypoint] migrations-Plugin (noch) nicht verfuegbar -> uebersprungen"
  fi
fi

echo "[entrypoint] starte: $*"
exec "$@"
