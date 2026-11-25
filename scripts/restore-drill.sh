#!/usr/bin/env bash
# Restore drill (spec Part 23 "Backups: PITR + restore drill", docs/RUNBOOK.md §4). Run monthly and before launch.
#
#   DRILL_DATABASE_URL=postgres://user:pass@drill-host:5432/postgres ./scripts/restore-drill.sh /var/backups/x2key/x2key-<stamp>.dump
#
# Restores the dump into a throwaway database on a SEPARATE server, then proves it is usable: migrations are
# current, the double-entry ledger balances (ledger:verify) and row counts are printed for the drill log.
# Never point DRILL_DATABASE_URL at production.
set -euo pipefail

DUMP="${1:?path to a .dump file}"
: "${DRILL_DATABASE_URL:?set DRILL_DATABASE_URL (a non-production server)}"
DB="x2key_drill_$(date -u +%Y%m%d%H%M%S)"
START="$(date +%s)"

if [ -f "${DUMP}.sha256" ]; then
  sha256sum --check "${DUMP}.sha256"
fi

psql "${DRILL_DATABASE_URL}" -v ON_ERROR_STOP=1 -c "CREATE DATABASE ${DB};"
TARGET="${DRILL_DATABASE_URL%/*}/${DB}"
pg_restore --no-owner --no-privileges --exit-on-error --dbname="${TARGET}" "${DUMP}"

# Point the app at the restored copy and check it.
export DB_URL="${TARGET}"
php artisan migrate:status --no-interaction | tail -n 3
php artisan ledger:verify --no-interaction

psql "${TARGET}" -At -c "SELECT 'users', COUNT(*) FROM users UNION ALL SELECT 'orders', COUNT(*) FROM orders UNION ALL SELECT 'ledger_journals', COUNT(*) FROM ledger_journals;"

echo "restore drill ok: ${DB} in $(( $(date +%s) - START )) s — record the time (RTO) and drop the database when done:"
echo "  psql \"${DRILL_DATABASE_URL}\" -c 'DROP DATABASE ${DB};'"
