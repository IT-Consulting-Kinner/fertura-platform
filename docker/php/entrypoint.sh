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
# Wiederherstellungspunkte (pg_dump vor Migrationen) liegen jetzt auf dem
# PERSISTENTEN Backup-Volume (ueberleben Recreate), nicht im fluechtigen tmp/.
mkdir -p /var/www/html/backups/recovery 2>/dev/null || true
chown -R www-data:www-data /var/www/html/backups/recovery 2>/dev/null || true

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
    # Migrationen mit verpflichtendem Wiederherstellungspunkt: zieht VOR dem
    # Migrieren automatisch einen pg_dump, falls Migrationen ausstehen (Kap.
    # 28.14.2) — der Betreiber muss nicht daran denken. Ohne Schemaaenderung
    # entsteht kein unnoetiger Dump.
    echo "[entrypoint] core_migrate (Wiederherstellungspunkt + Migration)"
    env APP_DATABASE_URL= bin/cake core_migrate || echo "[entrypoint] WARN: core_migrate fehlgeschlagen"
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

# Cache-Verzeichnis (P02) fuer den Laufzeit-Nutzer (www-data) beschreibbar
# machen: die CLI-Bootstrap-Schritte oben laufen als root und legen
# /tmp/cake_cache sonst root-eigen (0755) an -> php-fpm (www-data) koennte den
# Settings-/App-Cache nicht schreiben. CacheStore degradiert zwar still, aber so
# funktioniert das Caching auch im Request-Pfad.
mkdir -p /tmp/cake_cache/persistent /tmp/cake_cache/models 2>/dev/null || true
chown -R www-data:www-data /tmp/cake_cache 2>/dev/null || true
chmod -R 0775 /tmp/cake_cache 2>/dev/null || true

# tmp/cache (the CACHE constant -> /var/www/html/tmp/cache) is mounted on an ext4
# named volume (docker-compose: core_cache), NOT the 9p Windows bind mount. Make it
# owned/writable by the runtime user so the FileEngine can write AND chmod cache
# files; on the 9p mount the chmod fails ("Operation not permitted") and the
# resulting warnings corrupt the HTTP response (headers already sent).
mkdir -p /var/www/html/tmp/cache/persistent /var/www/html/tmp/cache/models 2>/dev/null || true
chown -R www-data:www-data /var/www/html/tmp/cache 2>/dev/null || true
chmod -R 0775 /var/www/html/tmp/cache 2>/dev/null || true

echo "[entrypoint] starte: $*"
exec "$@"
