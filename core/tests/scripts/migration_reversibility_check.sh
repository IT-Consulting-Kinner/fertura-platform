#!/bin/sh
# Down-Reversibilität ALLER Core-Migrationen (Kap. 28.14.2, Review-Punkt 3).
#
# Läuft IM core-Container gegen eine Wegwerf-Datenbank: alle Migrationen hoch
# (migrate), dann vollständig zurück (rollback -t 0). Schlägt ein `down()` fehl
# (wie zuvor der Backup-Log-CHECK, E62), bricht der Lauf ab. Beweist, dass der
# Update-Rückbau über Down-Migrationen sauber funktioniert.
#
# Aufruf: docker compose exec -T core sh tests/scripts/migration_reversibility_check.sh
set -eu

cd /var/www/html
SU="${DATABASE_URL%%\?*}"          # ohne ?encoding=… (psql)
BASE="${SU%/*}"
SCRATCH=fertura_revtest
SCRATCH_URL="$BASE/$SCRATCH?encoding=utf8"

cleanup() {
    psql "$SU" -q -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='$SCRATCH' AND pid<>pg_backend_pid()" >/dev/null 2>&1 || true
    psql "$SU" -q -c "DROP DATABASE IF EXISTS $SCRATCH" >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

cleanup
psql "$SU" -v ON_ERROR_STOP=1 -q -c "CREATE DATABASE $SCRATCH"

echo "== 1) schema_init (core-Schema) =="
env DATABASE_URL="$SCRATCH_URL" APP_DATABASE_URL= bin/cake schema_init

echo "== 2) migrate: ALLE Migrationen hoch =="
env DATABASE_URL="$SCRATCH_URL" APP_DATABASE_URL= bin/cake migrations migrate

echo "== 3) rollback -t 0: ALLE Migrationen zurück (Down-Reversibilität) =="
env DATABASE_URL="$SCRATCH_URL" APP_DATABASE_URL= bin/cake migrations rollback -t 0

echo "REVERSIBILITY_OK"
