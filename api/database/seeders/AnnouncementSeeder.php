<?php

namespace Database\Seeders;

use App\Models\Announcement;
use Illuminate\Database\Seeder;

/**
 * Pengumuman contoh untuk screensaver alat, satu per ikon supaya semua ikon bisa dicek di layar.
 * Aman dijalankan berulang: pengumuman dengan judul yang sama tidak dibuat dua kali.
 *
 *   php artisan db:seed --class=AnnouncementSeeder
 */
class AnnouncementSeeder extends Seeder
{
    /** @var list<array{title: string, description: string, icon: string}> */
    private const ITEMS = [
        ['title' => 'Selamat Datang', 'description' => 'Tempelkan kartu Anda ke pembaca untuk mencatat kehadiran.', 'icon' => 'info'],
        ['title' => 'Apel Pagi', 'description' => 'Apel pagi setiap Senin pukul 07.00 di halaman depan. Harap hadir tepat waktu.', 'icon' => 'pengumuman'],
        ['title' => 'Rapat Bulanan', 'description' => 'Jumat, 10 Oktober 2026 pukul 13.00 di ruang rapat lantai 2.', 'icon' => 'rapat'],
        ['title' => 'Jam Kerja', 'description' => 'Senin-Jumat 08.00-16.00. Istirahat 12.00-13.00.', 'icon' => 'jam'],
        ['title' => 'Libur Nasional', 'description' => 'Kantor tutup pada hari libur nasional sesuai kalender pemerintah.', 'icon' => 'libur'],
        ['title' => 'Jadwal Kegiatan', 'description' => 'Lihat jadwal kegiatan bulan ini di papan informasi.', 'icon' => 'kalender'],
        ['title' => 'Jaga Kesehatan', 'description' => 'Cuci tangan, minum air putih, dan istirahat yang cukup.', 'icon' => 'kesehatan'],
        ['title' => 'Pelatihan Karyawan', 'description' => 'Pendaftaran pelatihan dibuka sampai akhir bulan di bagian HRD.', 'icon' => 'buku'],
        ['title' => 'Kartu Hilang?', 'description' => 'Segera lapor ke bagian HRD agar kartu lama dinonaktifkan.', 'icon' => 'peringatan'],
        ['title' => 'Selamat Ulang Tahun', 'description' => 'Selamat ulang tahun untuk rekan-rekan yang berulang tahun bulan ini!', 'icon' => 'selamat'],
    ];

    public function run(): void
    {
        foreach (self::ITEMS as $index => $item) {
            Announcement::firstOrCreate(
                ['title' => $item['title']],
                $item + ['is_active' => true, 'sort_order' => $index + 1],
            );
        }

        $this->command?->info(count(self::ITEMS).' pengumuman contoh siap (satu per ikon). Alat mengambilnya dalam ±1 menit.');
    }
}
