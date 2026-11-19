#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
site=$(mktemp -d /tmp/craft-blurhash.XXXXXXXX)
server=''
cleanup() {
    if [[ -n "$server" ]]; then kill "$server" 2>/dev/null || :; wait "$server" || :; fi
    rm -rf "$site"
}
trap cleanup EXIT
mkdir -p "$site"/{config,storage,web,db,images,home}
socket="$site/db.sock"
# Never use a configured service, a project .env file, or TCP.
mysqld --no-defaults --initialize-insecure --datadir="$site/db" >"$site/mysql.log" 2>&1 || { cat "$site/mysql.log"; exit 1; }
mysqld --no-defaults --datadir="$site/db" --socket="$socket" --pid-file="$site/mysql.pid" --skip-networking --mysqlx=OFF >>"$site/mysql.log" 2>&1 &
server=$!
for ((i=0; i<120; ++i)); do
    [[ -S "$socket" ]] && mysqladmin --no-defaults --protocol=socket --socket="$socket" -u root ping >/dev/null 2>&1 && break
    kill -0 "$server" 2>/dev/null || { cat "$site/mysql.log"; exit 1; }
    sleep 0.5
done
mysql --no-defaults --protocol=socket --socket="$socket" -u root -e 'CREATE DATABASE blurhash_test CHARACTER SET utf8mb4'
touch "$site/.env"
run() {
    env -i PATH="$PATH" HOME="$site/home" CRAFT_BASE_PATH="$site" CRAFT_WEB_ROOT="$site/web" CRAFT_DEV_MODE=1 \
        CRAFT_DB_DSN="mysql:unix_socket=$socket;dbname=blurhash_test" CRAFT_DB_USER=root CRAFT_DB_TABLE_PREFIX=bh_ \
        CRAFT_SECURITY_KEY=isolated-integration-test-security-key CRAFT_APP_ID=blurhash-test \
        php tests/integration.php "$@"
}
run install/craft --interactive=0 --username=testadmin --password=integration-only-123 --email=test@example.org --site-name=Test --site-url=http://localhost --language=en-US >"$site/install.log" || { tail -60 "$site/install.log"; exit 1; }
run assets
run utility || { grep -hE 'Failed to (encode blurhash|get local image)|No thumbnail available' "$site"/storage/logs/*.log || :; exit 1; }
run generate | grep -F 'Processed: 1, Skipped: 0'
run generate | grep -F 'Processed: 0, Skipped: 1'
echo 'Craft integration checks passed.'
