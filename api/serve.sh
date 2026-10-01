#!/usr/bin/env bash
# Jalankan API absensi di http://127.0.0.1:8133
# (reverse proxy atau Cloudflare Tunnel diarahkan ke http://localhost:8133)
set -euo pipefail
cd "$(dirname "$0")"
exec php artisan serve --host=127.0.0.1 --port=8133
