#!/usr/bin/env bash
# Runs the whole Pest suite against real MySQL 8.4 and PostgreSQL 17 servers in throwaway Docker
# containers (the reports use driver-specific date and duration SQL).
set -uo pipefail
cd "$(dirname "$0")/.."

FAILED=0
cleanup() { docker rm -f fpa-it-mysql fpa-it-pg >/dev/null 2>&1; }
trap cleanup EXIT
cleanup

docker run -d --name fpa-it-mysql -e MYSQL_ROOT_PASSWORD=secret -e MYSQL_DATABASE=analytics -p 127.0.0.1:33185:3306 mysql:8.4 >/dev/null
docker run -d --name fpa-it-pg -e POSTGRES_PASSWORD=secret -e POSTGRES_DB=analytics -p 127.0.0.1:54385:5432 postgres:17 >/dev/null

wait_for() { local i; for i in $(seq 1 90); do "$@" >/dev/null 2>&1 && return 0; sleep 1; done; return 1; }
wait_for docker exec fpa-it-mysql mysql -uroot -psecret -e 'select 1' analytics || { echo "MySQL did not start"; exit 1; }
wait_for docker exec fpa-it-pg psql -U postgres -d analytics -c 'select 1' || { echo "PostgreSQL did not start"; exit 1; }

echo "== MySQL 8.4";      FPA_DB=mysql FPA_DB_PORT=33185 vendor/bin/pest || FAILED=1
echo "== PostgreSQL 17";  FPA_DB=pgsql FPA_DB_PORT=54385 vendor/bin/pest || FAILED=1
exit $FAILED
