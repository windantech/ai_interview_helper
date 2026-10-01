#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Full automated test run in Docker: MySQL 8.4 + PHP 8.3 (pdo_mysql, zip) + mock OpenAI.
#   bash tests/run-all.sh
# Uses an isolated database; never touches your real .env or OpenAI account.
# ---------------------------------------------------------------------------
set -euo pipefail
cd "$(dirname "$0")/.."
ROOT="$PWD"
NET=icp-test-net
DB=icp-test-mysql

docker network create "$NET" >/dev/null 2>&1 || true
docker build -q -t icp-php-test tests/docker >/dev/null

if ! docker ps --format '{{.Names}}' | grep -q "^$DB$"; then
  docker rm -f "$DB" >/dev/null 2>&1 || true
  docker run -d --name "$DB" --network "$NET" -e MYSQL_ROOT_PASSWORD=rootpw -e MYSQL_DATABASE=interview_copilot_test \
    -e MYSQL_USER=icp -e MYSQL_PASSWORD=icp_pw mysql:8.4 >/dev/null
  echo "Waiting for MySQL…"
  for i in $(seq 1 60); do
    docker logs "$DB" 2>&1 | grep -q "ready for connections.*port: 3306" && break
    sleep 2
  done
  sleep 2
fi

echo "Importing schema…"
docker exec -i "$DB" mysql -uicp -picp_pw interview_copilot_test < database/schema.sql 2>&1 | grep -v "Using a password" || true

docker run --rm --network "$NET" --user "$(id -u):$(id -g)" -v "$ROOT":/app \
  -e APP_ENV=development -e APP_URL=http://127.0.0.1:8000 -e STORAGE_PATH=/tmp/icp-storage \
  -e DB_HOST="$DB" -e DB_NAME=interview_copilot_test -e DB_USER=icp -e DB_PASS=icp_pw \
  -e OPENAI_API_KEY=sk-test-key -e OPENAI_BASE_URL=http://127.0.0.1:9100/v1 \
  -e OPENAI_ANSWER_MODEL=gpt-6-luna -e MAIL_ENABLED=false \
  -e MOCK_OPENAI=http://127.0.0.1:9100 -e MOCK_LOG=/tmp/mock.log -e PHP_CLI_SERVER_WORKERS=4 \
  icp-php-test sh -c '
    php -d upload_max_filesize=25M -d post_max_size=26M -S 127.0.0.1:9100 tests/mock-openai.php >/tmp/mock-server.log 2>&1 &
    php -d upload_max_filesize=25M -d post_max_size=26M -S 127.0.0.1:8000 router.php >/tmp/app-server.log 2>&1 &
    sleep 1
    php tests/run.php && php tests/integration.php
    status=$?
    if [ $status -ne 0 ]; then echo "--- app server log ---"; grep -vE "Accepted|Closing" /tmp/app-server.log | tail -40; fi
    exit $status
  '
