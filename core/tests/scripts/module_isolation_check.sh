#!/bin/sh
# Out-of-Process-Modulisolation – Verifikations-Harness (Kap. 23.16, Phase 1).
#
# Läuft IM core-Container. Beweist die Isolationsgrenze des Managed-Subprocess-
# Modells (Entscheidung E60):
#   1. provisioniert eine EINGESCHRÄNKTE DB-Rolle (LOGIN, NOBYPASSRLS) mit
#      ausschließlich eigenem Schema `mod_sample_module` – KEINE Core-Grants;
#   2. startet `bin/module-host.php` mit BEREINIGTER Umgebung (`env -i`), sodass
#      weder Core-`DATABASE_URL` (Superuser) noch `BACKUP_PASSWORD` erreichbar
#      sind, und gibt ihm nur die Modul-Rolle als `MODULE_DB_URL`;
#   3. ruft über den Unix-Socket (RemoteInvoker) `__probe` + den echten
#      Service-Contract `sample_module.service.echo` auf und prüft:
#        sees_core_database_url=false, sees_backup_password=false,
#        can_read_core_users=false, can_read_own_schema=true, echo korrekt.
#
# Aufruf:  docker compose exec -T core sh tests/scripts/module_isolation_check.sh
set -eu

KEY=sample_module
FIX=/var/www/html/tests/Fixture/sample_module
SOCKDIR=/tmp/fertura-mod
SOCK="$SOCKDIR/$KEY.sock"
MODPW="modpw_$$"
SU_DSN="${DATABASE_URL%%\?*}"   # Superuser-Connection (ohne ?encoding=… für psql)
DBNAME="$(psql "$SU_DSN" -tAc 'select current_database()' | tr -d '[:space:]')"

HOSTPID=""
cleanup() {
    [ -n "$HOSTPID" ] && kill "$HOSTPID" 2>/dev/null || true
    psql "$SU_DSN" -q -c "DROP SCHEMA IF EXISTS mod_${KEY} CASCADE;" 2>/dev/null || true
    psql "$SU_DSN" -q -c "DROP ROLE IF EXISTS mod_${KEY};" 2>/dev/null || true
    rm -f "$SOCK"
}
trap cleanup EXIT INT TERM

mkdir -p "$SOCKDIR"

echo "== 1) Eingeschränkte Modul-Rolle + eigenes Schema (keine Core-Grants) =="
psql "$SU_DSN" -v ON_ERROR_STOP=1 -q <<SQL
DROP SCHEMA IF EXISTS mod_${KEY} CASCADE;
DROP ROLE IF EXISTS mod_${KEY};
CREATE ROLE mod_${KEY} LOGIN PASSWORD '${MODPW}' NOBYPASSRLS;
CREATE SCHEMA mod_${KEY} AUTHORIZATION mod_${KEY};
SET ROLE mod_${KEY};
CREATE TABLE mod_${KEY}.ping_log (id bigserial primary key, note text);
INSERT INTO mod_${KEY}.ping_log(note) VALUES ('hello');
RESET ROLE;
SQL

echo "== 2) Modul-Host mit BEREINIGTER Umgebung starten (env -i) =="
env -i PATH="$PATH" \
    MODULE_KEY="$KEY" \
    MODULE_SOCKET="$SOCK" \
    MODULE_SRC="$FIX/src" \
    MODULE_NAMESPACE="SampleModule" \
    MODULE_MANIFEST="$FIX/manifest.json" \
    MODULE_DB_URL="pgsql:host=db;port=5432;dbname=${DBNAME};user=mod_${KEY};password=${MODPW}" \
    php /var/www/html/bin/module-host.php "$KEY" &
HOSTPID=$!

i=0
while [ ! -S "$SOCK" ] && [ "$i" -lt 50 ]; do i=$((i+1)); sleep 0.1; done
[ -S "$SOCK" ] || { echo "FEHLER: Modul-Host-Socket nicht erschienen"; exit 3; }

echo "== 3) RPC: __probe + sample_module.service.echo =="
php -d error_reporting=E_ALL -r '
require "/var/www/html/vendor/autoload.php";
$r = new App\Service\Module\RemoteInvoker("/tmp/fertura-mod");
$p = $r->invoke("sample_module", "__probe", []);
echo "PROBE " . json_encode($p) . "\n";
$e = $r->invoke("sample_module", "sample_module.service.echo", ["msg" => "hallo welt"]);
echo "ECHO  " . json_encode($e) . "\n";
$ok = true;
$expect = static function (string $n, $got, $want) use (&$ok) {
    if ($got !== $want) { echo "  FAIL $n: " . json_encode($got) . " (erwartet " . json_encode($want) . ")\n"; $ok = false; }
    else { echo "  OK   $n = " . json_encode($got) . "\n"; }
};
$expect("sees_core_database_url", $p["sees_core_database_url"] ?? null, false);
$expect("sees_backup_password",  $p["sees_backup_password"] ?? null, false);
$expect("can_read_core_users",   $p["can_read_core_users"] ?? null, false);
$expect("can_read_own_schema",   $p["can_read_own_schema"] ?? null, true);
$expect("echo",                  $e["echo"] ?? null, "hallo welt");
$expect("length",                $e["length"] ?? null, 10);
echo $ok ? "ISOLATION_OK\n" : "ISOLATION_FAIL\n";
exit($ok ? 0 : 1);
'
