#!/usr/bin/env bash
#
# Worker end to end in Docker (docs/spec/07-worker.md "Pflicht-Tests",
# issue #55): a web host container (Apache + PHP 8.5 + MariaDB, HTTPS) and
# the worker image, set up from worker/docker-compose.yml. Checks:
#
#   - pairing over verified TLS, the same fingerprint on both sides;
#   - after the confirmation the worker reports `ocr`, claims the fixture
#     scan's OCR job, runs OCRmyPDF on it and uploads text + PDF/A, which the
#     web host takes over into the inbox document;
#   - docker stop ends the worker cleanly within stop_grace_period;
#   - no text of the scan in any container log.
#
# Usage (repository root, vendor/ installed with dev dependencies):
#   tests/e2e/worker/run.sh
# WORKER_IMAGE=<image> uses an image that is already there instead of
# building worker/Dockerfile (CI and the release workflow build it first).
#
# Needs docker with compose and openssl. Leaves nothing behind.

set -euo pipefail

hier="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "$hier/../../.." && pwd)"
lauf="$hier/.lauf"
bauen=0
if [ -z "${WORKER_IMAGE:-}" ]; then
    bauen=1
fi
export WORKER_IMAGE="${WORKER_IMAGE:-vereinsbelege-worker:e2e}"

dc() {
    docker compose -p vb-worker-e2e --project-directory "$hier" \
        -f "$root/worker/docker-compose.yml" -f "$hier/docker-compose.yml" "$@"
}

treiber() {
    dc exec -T -u www-data webhoster php -d max_execution_time=0 tests/e2e/worker/treiber.php "$@"
}

# Apache (www-data) creates directories below shared/ the runner's user
# cannot remove - removed as root inside a container instead.
weg() {
    if [ -e "$lauf" ]; then
        rm -rf "$lauf" 2> /dev/null \
            || docker run --rm -v "$hier:/e2e" --entrypoint rm mariadb:10.11 -rf /e2e/.lauf
    fi
}

schritt() {
    printf '\n==> %s\n' "$*"
}

aufraeumen() {
    local status=$?
    if [ "$status" -ne 0 ]; then
        echo "E2E fehlgeschlagen (Status $status) - Logs:" >&2
        dc logs --no-color --tail=300 >&2 || true
        if [ -f "$lauf/shared/var/log/app.log" ]; then
            echo '--- shared/var/log/app.log' >&2
            tail -n 100 "$lauf/shared/var/log/app.log" >&2 || true
        fi
    fi
    dc down -v --remove-orphans > /dev/null 2>&1 || true
    weg || status=1
    exit "$status"
}
trap aufraeumen EXIT

schritt "Test-CA und Server-Zertifikat für https://webhoster"
weg
mkdir -p "$lauf/tls" "$lauf/shared/var"
(
    cd "$lauf/tls"
    openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=Vereinsbelege E2E Test-CA" \
        -addext "basicConstraints=critical,CA:TRUE" -addext "keyUsage=critical,keyCertSign,cRLSign" \
        -keyout ca.key -out ca.pem 2> /dev/null
    openssl req -newkey rsa:2048 -nodes -subj "/CN=webhoster" -keyout server.key -out server.csr 2> /dev/null
    printf 'subjectAltName=DNS:webhoster\nbasicConstraints=CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\n' > server.ext
    openssl x509 -req -in server.csr -CA ca.pem -CAkey ca.key -CAcreateserial -days 2 -extfile server.ext -out server.pem 2> /dev/null
    # Only the certificates leave this directory; the container's Apache
    # (root) reads the server key, the CA key stays here.
    rm ca.key server.csr
    chmod 0644 ca.pem server.pem server.key
)
cp "$hier/config.php" "$lauf/shared/config.php"
chmod -R a+rwX "$lauf/shared"

if [ "$bauen" = "1" ]; then
    schritt "Worker-Image bauen"
    docker build -f "$root/worker/Dockerfile" -t "$WORKER_IMAGE" "$root"
fi

schritt "Webhoster und Datenbank starten"
dc up -d --build --wait webhoster

schritt "Schema, Tresor, Worker-Modul; Kopplungscode"
code="$(treiber vorbereiten)"

schritt "Worker koppeln (über TLS mit Prüfung gegen die Test-CA)"
dc run --rm -T worker pair https://webhoster "$code" E2E

schritt "Fingerabdruck vergleichen und bestätigen"
fp_webhoster="$(treiber bestaetigen)"
fp_worker="$(dc run --rm -T worker status | sed -n 's/^Fingerabdruck: //p')"
echo "Webhoster: $fp_webhoster"
echo "Worker:    $fp_worker"
if [ -z "$fp_webhoster" ] || [ "$fp_webhoster" != "$fp_worker" ]; then
    echo "Fingerabdrücke weichen ab" >&2
    exit 1
fi

schritt "Worker starten, Scan einreichen"
dc up -d worker
treiber einreichen

# The worker sees the job with its next heartbeat (poll interval 60 s).
schritt "Auf das Ergebnis warten und prüfen"
treiber pruefen 600

schritt "Worker beenden (docker stop, innerhalb stop_grace_period)"
start=$(date +%s)
dc stop worker
dauer=$(( $(date +%s) - start ))
exit_code="$(docker inspect -f '{{.State.ExitCode}}' "$(dc ps -aq worker)")"
echo "Beendet nach ${dauer} s mit Status ${exit_code}"
if [ "$exit_code" != "0" ]; then
    echo "Der Worker hat sich nicht sauber beendet (Status $exit_code)" >&2
    exit 1
fi

schritt "Kein Text des Belegs in den Logs"
logs="$(dc logs --no-color)"
if [ -f "$lauf/shared/var/log/app.log" ]; then
    logs+="$(cat "$lauf/shared/var/log/app.log")"
fi
for wort in Testrechnung Gesamtbetrag Sporthandel; do
    if grep -q "$wort" <<< "$logs"; then
        echo "„$wort“ steht in einem Log" >&2
        exit 1
    fi
done

schritt "E2E bestanden"
