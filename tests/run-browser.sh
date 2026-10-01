#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Headless Chromium UI tests (responsive layout + fake-microphone interview flow).
#   bash tests/run-browser.sh        → screenshots in tests/browser/out/
# Requires the MySQL container from tests/run-all.sh (it is started if missing).
# ---------------------------------------------------------------------------
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
NET=icp-test-net
DB=icp-test-mysql
APP=icp-test-app

docker network create "$NET" >/dev/null 2>&1 || true
docker build -q -t icp-php-test tests/docker >/dev/null
docker build -q -t icp-browser-test tests/browser >/dev/null

if ! docker ps --format '{{.Names}}' | grep -q "^$DB$"; then
  docker rm -f "$DB" >/dev/null 2>&1 || true
  docker run -d --name "$DB" --network "$NET" -e MYSQL_ROOT_PASSWORD=rootpw -e MYSQL_DATABASE=interview_copilot_test \
    -e MYSQL_USER=icp -e MYSQL_PASSWORD=icp_pw mysql:8.4 >/dev/null
  for i in $(seq 1 60); do docker logs "$DB" 2>&1 | grep -q "ready for connections.*port: 3306" && break; sleep 2; done
  sleep 2
fi
docker exec -i "$DB" mysql -uicp -picp_pw interview_copilot_test < database/schema.sql 2>&1 | grep -v "Using a password" || true

docker rm -f "$APP" >/dev/null 2>&1 || true
docker run -d --name "$APP" --network "$NET" --user "$(id -u):$(id -g)" -v "$ROOT":/app \
  -e APP_ENV=development -e APP_URL=http://127.0.0.1:8000 -e STORAGE_PATH=/tmp/icp-storage \
  -e DB_HOST="$DB" -e DB_NAME=interview_copilot_test -e DB_USER=icp -e DB_PASS=icp_pw \
  -e OPENAI_API_KEY=sk-test-key -e OPENAI_BASE_URL=http://127.0.0.1:9100/v1 -e MOCK_LOG=/tmp/mock.log \
  -e PHP_CLI_SERVER_WORKERS=4 icp-php-test sh -c '
    php -S 127.0.0.1:9100 tests/mock-openai.php >/tmp/mock.out 2>&1 &
    exec php -d upload_max_filesize=25M -d post_max_size=26M -S 127.0.0.1:8000 router.php' >/dev/null
sleep 1

mkdir -p tests/browser/out
# Share the app container's network namespace so the page origin is 127.0.0.1 (a secure context for getUserMedia).
set +e
docker run --rm --network "container:$APP" --user "$(id -u):$(id -g)" -e HOME=/tmp -v "$ROOT/tests/browser/ui-test.mjs":/t/ui-test.mjs:ro \
  -v "$ROOT/tests/browser/out":/out icp-browser-test node /t/ui-test.mjs
status=$?
set -e
docker rm -f "$APP" >/dev/null
exit $status
