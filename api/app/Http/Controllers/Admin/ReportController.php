<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AttendanceReport;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public const MAX_DAYS = 92;

    public function index(Request $request, AttendanceReport $report): View
    {
        [$from, $to, $presentOnly] = $this->filters($request);

        $rows = $report->rows($from, $to, $presentOnly);
        $perPage = 100;
        $page = LengthAwarePaginator::resolveCurrentPage();

        $paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        return view('admin.reports.index', [
            'rows' => $paginator,
            'from' => $from,
            'to' => $to,
            'presentOnly' => $presentOnly,
            'summary' => [
                'present' => $rows->where('note', '!=', 'Tidak hadir')->count(),
                'absent' => $rows->where('note', 'Tidak hadir')->count(),
                'no_checkout' => $rows->where('note', 'Tanpa tap pulang')->count(),
            ],
        ]);
    }

    public function export(Request $request, AttendanceReport $report): StreamedResponse
    {
        [$from, $to, $presentOnly] = $this->filters($request);

        $rows = $report->rows($from, $to, $presentOnly);
        $filename = 'absensi_'.$from->toDateString().'_'.$to->toDateString().'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8
            fputcsv($out, ['Tanggal', 'Nama', 'NIS/NIP', 'Nomor Kartu', 'Jam Masuk', 'Jam Pulang', 'Keterangan'], escape: '');

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['date'],
                    self::csvSafe($row['name']),
                    self::csvSafe($row['identifier'] ?? ''),
                    $row['card_uid'],
                    $row['check_in'] ?? '',
                    $row['check_out'] ?? '',
                    $row['note'],
                ], escape: '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: bool}
     */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'dari' => ['nullable', 'date_format:Y-m-d'],
            'sampai' => ['nullable', 'date_format:Y-m-d'],
            'hadir_saja' => ['nullable', 'boolean'],
        ], [], [
            'dari' => 'tanggal awal',
            'sampai' => 'tanggal akhir',
        ]);

        $today = now()->startOfDay();
        $from = isset($validated['dari']) ? Carbon::createFromFormat('!Y-m-d', $validated['dari']) : $today->copy();
        $to = isset($validated['sampai']) ? Carbon::createFromFormat('!Y-m-d', $validated['sampai']) : ($from->gt($today) ? $from->copy() : $today->copy());

        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            throw ValidationException::withMessages([
                'sampai' => 'Rentang tanggal maksimal '.self::MAX_DAYS.' hari.',
            ]);
        }

        return [$from, $to, (bool) ($validated['hadir_saja'] ?? false)];
    }

    /** Cegah injeksi formula saat CSV dibuka di spreadsheet. */
    private static function csvSafe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
