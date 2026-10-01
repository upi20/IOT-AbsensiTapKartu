<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Jendela duplikat (detik)
    |--------------------------------------------------------------------------
    |
    | Jika kartu yang sama di-tap lagi dalam rentang ini sejak tap sukses
    | terakhirnya, tap dianggap duplikat: tidak menghasilkan check-in /
    | check-out baru (hanya dicatat dengan status "duplicate").
    |
    */

    'duplicate_window_seconds' => (int) env('ABSENSI_DUPLICATE_WINDOW_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Batas item pada GET /api/v1/attendances/today
    |--------------------------------------------------------------------------
    */

    'today_default_limit' => 20,
    'today_max_limit' => 100,

];
