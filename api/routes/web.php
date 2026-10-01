<?php

use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\Admin\AttendanceController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeviceController;
use App\Http\Controllers\Admin\FirmwareController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UnknownCardController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Panel admin web
Route::prefix('admin')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('login', [AuthController::class, 'create'])->name('login');
        Route::post('login', [AuthController::class, 'store'])
            ->middleware('throttle:admin-login')
            ->name('login.attempt');
    });

    Route::middleware('auth')->name('admin.')->group(function () {
        Route::post('logout', [AuthController::class, 'destroy'])->name('logout');

        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('dasbor/live', [DashboardController::class, 'live'])->name('dashboard.live');

        Route::get('anggota', [MemberController::class, 'index'])->name('members.index');
        Route::get('anggota/tambah', [MemberController::class, 'create'])->name('members.create');
        Route::post('anggota', [MemberController::class, 'store'])->name('members.store');
        Route::get('anggota/{member}/ubah', [MemberController::class, 'edit'])->name('members.edit');
        Route::put('anggota/{member}', [MemberController::class, 'update'])->name('members.update');
        Route::delete('anggota/{member}', [MemberController::class, 'destroy'])->name('members.destroy');

        Route::get('kehadiran', [AttendanceController::class, 'index'])->name('attendances.index');
        Route::delete('kehadiran', [AttendanceController::class, 'destroyDay'])->name('attendances.destroy-day');
        Route::delete('kehadiran/{attendance}', [AttendanceController::class, 'destroy'])->name('attendances.destroy');

        Route::get('kartu-belum-terdaftar', [UnknownCardController::class, 'index'])->name('unknown-cards');

        Route::get('rekap', [ReportController::class, 'index'])->name('reports.index');
        Route::get('rekap/csv', [ReportController::class, 'export'])->name('reports.export');

        Route::get('alat', [DeviceController::class, 'index'])->name('devices.index');
        Route::put('alat/{device}', [DeviceController::class, 'update'])->name('devices.update');

        Route::get('firmware', [FirmwareController::class, 'index'])->name('firmware.index');
        Route::post('firmware', [FirmwareController::class, 'store'])->name('firmware.store');
        Route::post('firmware/{release}/terapkan', [FirmwareController::class, 'applyAll'])->name('firmware.apply-all');
        Route::delete('firmware/{release}', [FirmwareController::class, 'destroy'])->name('firmware.destroy');

        Route::get('pengumuman', [AnnouncementController::class, 'index'])->name('announcements.index');
        Route::get('pengumuman/tambah', [AnnouncementController::class, 'create'])->name('announcements.create');
        Route::post('pengumuman', [AnnouncementController::class, 'store'])->name('announcements.store');
        Route::patch('pengumuman/massal', [AnnouncementController::class, 'bulk'])->name('announcements.bulk');
        Route::put('pengumuman/screensaver', [AnnouncementController::class, 'updateScreensaver'])->name('announcements.screensaver');
        Route::get('pengumuman/{announcement}/ubah', [AnnouncementController::class, 'edit'])->whereNumber('announcement')->name('announcements.edit');
        Route::put('pengumuman/{announcement}', [AnnouncementController::class, 'update'])->whereNumber('announcement')->name('announcements.update');
        Route::delete('pengumuman/{announcement}', [AnnouncementController::class, 'destroy'])->whereNumber('announcement')->name('announcements.destroy');

        Route::get('pengaturan', [SettingsController::class, 'index'])->name('settings');
        Route::put('pengaturan/judul', [SettingsController::class, 'updateTitle'])->name('settings.title');
        Route::put('pengaturan/layar', [SettingsController::class, 'updateScreen'])->name('settings.screen');
        Route::post('pengaturan/api-key', [SettingsController::class, 'regenerateApiKey'])->name('settings.api-key');
        Route::put('pengaturan/password', [SettingsController::class, 'updatePassword'])->name('settings.password');
    });
});
