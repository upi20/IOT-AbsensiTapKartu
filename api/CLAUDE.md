# Absensi RFID Terintegrasi — server Laravel

Laravel Boost sudah terpasang. Panduan pengembangan (konvensi, Artisan, tes, Pint) ada di
[`AGENTS.md`](AGENTS.md); ikuti panduan itu. Gambaran aplikasi, setup, dan cara memasang di server
publik ada di [`README.md`](README.md).

- Jangan menganggap `.env` sebagai lingkungan pengembangan: periksa `APP_ENV`/`APP_DEBUG` dulu. Server
  publik memakai `APP_ENV=production` dan `APP_DEBUG=false`. MCP Boost tetap jalan karena `.mcp.json`
  menjalankannya dengan `APP_ENV=local`.
- Database wajib PostgreSQL. Tes memakai database `absensi_alat_test` (lihat `phpunit.xml`), bukan
  database utama `absensi_alat`.
- Format kode: `vendor/bin/pint --format agent <file...>`.
