<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Daftar semua tap per tanggal, dan hapus tap (misalnya untuk keperluan tes).
 */
class AttendanceController extends Controller
{
    public function index(Request $request): View
    {
        [$date, $q] = $this->filters($request);

        return view('admin.attendances.index', [
            'date' => $date,
            'q' => $q,
            'taps' => $this->query($date, $q)
                ->with(['member', 'device'])
                ->latest('tapped_at')
                ->paginate(50)
                ->withQueryString(),
        ]);
    }

    public function destroy(Request $request, Attendance $attendance): RedirectResponse
    {
        $attendance->delete();

        return back()->with('success', 'Satu data kehadiran dihapus.');
    }

    /** Hapus semua tap pada tanggal yang dipilih (ikut filter pencarian kalau ada). */
    public function destroyDay(Request $request): RedirectResponse
    {
        [$date, $q] = $this->filters($request);
        $count = $this->query($date, $q)->delete();

        return redirect()
            ->route('admin.attendances.index', array_filter(['tanggal' => $date->toDateString(), 'q' => $q]))
            ->with('success', "{$count} data kehadiran tanggal {$date->translatedFormat('d F Y')} dihapus.");
    }

    /** @return array{0: Carbon, 1: string} */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'tanggal' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:60'],
        ]);

        $date = isset($validated['tanggal'])
            ? Carbon::createFromFormat('!Y-m-d', $validated['tanggal'])
            : now()->startOfDay();

        return [$date, trim($validated['q'] ?? '')];
    }

    /** Tap pada tanggal itu, disaring nama anggota atau nomor kartu. */
    private function query(Carbon $date, string $q): Builder
    {
        return Attendance::query()
            ->whereBetween('tapped_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->when($q !== '', function (Builder $query) use ($q) {
                $query->where(function (Builder $w) use ($q) {
                    $w->where('card_uid', 'ilike', "%{$q}%")
                        ->orWhereHas('member', fn (Builder $m) => $m->where('name', 'ilike', "%{$q}%"));
                });
            });
    }
}
