<?php

namespace App\Console\Commands;

use App\Models\Device;
use Illuminate\Console\Command;

class DeviceCreate extends Command
{
    protected $signature = 'absensi:device-create {name : Nama perangkat} {--location= : Lokasi perangkat}';

    protected $description = '[USANG, API /api/v1] Daftarkan alat lama dan tampilkan X-Device-Key (hanya sekali)';

    public function handle(): int
    {
        [$device, $plainKey] = Device::register($this->argument('name'), $this->option('location'));

        $this->info("Perangkat #{$device->id} \"{$device->name}\" dibuat.");
        $this->newLine();
        $this->line("  X-Device-Key: <fg=yellow>{$plainKey}</>");
        $this->newLine();
        $this->warn('Simpan key ini sekarang. Key tidak bisa ditampilkan lagi (hanya hash yang disimpan).');

        return self::SUCCESS;
    }
}
