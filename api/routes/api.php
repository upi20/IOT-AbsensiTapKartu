<?php

use App\Http\Controllers\Api\AbsensiController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Middleware\AuthenticateAbsensiDevice;
use App\Http\Middleware\AuthenticateDevice;
use Illuminate\Support\Facades\Route;

// API standar alat absensi (doc/spesifikasi-api.md). Base URL alat: {APP_URL}/api/absensi
// Header wajib: X-API-Key (API key di Pengaturan) dan X-Device-ID.
// Batas: 240 request/menit per alat + IP (lihat AppServiceProvider), API key salah 20x/menit per IP.
Route::prefix('absensi')->middleware(['throttle:absensi-api', AuthenticateAbsensiDevice::class])->group(function () {
    Route::get('ping', [AbsensiController::class, 'ping']);
    Route::post('tap', [AbsensiController::class, 'tap']);
    Route::post('heartbeat', [AbsensiController::class, 'heartbeat']);
    Route::get('announcements', [AbsensiController::class, 'announcements']);
    Route::get('firmware/{release}', [AbsensiController::class, 'firmware'])->whereNumber('release')->name('absensi.firmware');
});

// USANG: API lama untuk firmware lama. Header wajib "X-Device-Key: <kunci per alat>".
Route::prefix('v1')->middleware(AuthenticateDevice::class)->group(function () {
    Route::get('ping', [DeviceController::class, 'ping']);
    Route::post('tap', [DeviceController::class, 'tap']);
    Route::get('attendances/today', [DeviceController::class, 'today']);
});
