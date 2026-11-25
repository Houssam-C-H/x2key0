#!/usr/bin/env bash
# X2KEY logical backup (docs/RUNBOOK.md §4). Complements the provider's continuous PITR, it does not replace it.
#
#   DATABASE_URL=postgres://user:pass@host:5432/x2key ./scripts/backup.sh [/backups]
#
# Writes a compressed custom-format dump plus its SHA-256, keeps the last 14 days, and exits non-zero on any error
# so cron / the scheduler can alert.
set -euo pipefail

: "${DATABASE_URL:?set DATABASE_URL}"
DIR="${1:-/var/backups/x2key}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
FILE="${DIR}/x2key-${STAMP}.dump"

mkdir -p "${DIR}"
pg_dump --format=custom --compress=9 --no-owner --no-privileges --file="${FILE}" "${DATABASE_URL}"
sha256sum "${FILE}" > "${FILE}.sha256"

# Retention: 14 days of logical dumps (PITR covers the finer-grained window).
find "${DIR}" -name 'x2key-*.dump*' -mtime +14 -delete

echo "backup ok: ${FILE} ($(du -h "${FILE}" | cut -f1))"
