# Fertura Dev-Autostart
# Bootet die WSL-Distro und startet die Dev-Umgebung (docker compose up -d).
# Wird per Windows-Anmelde-Aufgabe ausgefuehrt, damit kein Terminal offen bleiben muss.
# Voraussetzung in der Distro: systemd=true und 'systemctl enable docker' (docker.service).
$ErrorActionPreference = 'SilentlyContinue'

$Distro = 'Ubuntu'

# Repo-Wurzel = zwei Ebenen ueber diesem Skript (docker/autostart -> <repo>)
$Repo = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)

# WSL booten (--cd nimmt einen Windows-Pfad an) und auf den Docker-Daemon warten,
# danach die Compose-Umgebung hochfahren. Idempotent: bereits laufende Container bleiben.
$cmd = 'i=0; while [ $i -lt 30 ]; do docker info >/dev/null 2>&1 && break; i=$((i+1)); sleep 2; done; docker compose up -d'
wsl -d $Distro --cd "$Repo" -e sh -lc "$cmd"
