<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AnnouncementRequest;
use App\Models\Announcement;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Pengumuman untuk screensaver alat. Alat mengambil ulang daftar ini saat config.announcements_rev
 * berubah (dicek tiap heartbeat), jadi perubahan tampil di alat dalam ±1 menit.
 */
class AnnouncementController extends Controller
{
    /** Aksi massal di daftar pengumuman => kata kerja untuk pesan sukses. */
    private const BULK_ACTIONS = [
        'show' => 'ditampilkan',
        'hide' => 'disembunyikan',
        'delete' => 'dihapus',
    ];

    public function index(): View
    {
        return view('admin.announcements.index', [
            'announcements' => Announcement::query()->ordered()->get(),
            'interval' => Setting::screensaverInterval(),
            'idle' => Setting::screensaverIdle(),
        ]);
    }

    /** Form tambah. Nomor urut diisi setelah pengumuman terakhir. */
    public function create(): View
    {
        $nextOrder = min(Announcement::SORT_ORDER_MAX, (int) Announcement::query()->max('sort_order') + 1);

        return view('admin.announcements.form', [
            'announcement' => new Announcement(['sort_order' => $nextOrder]),
        ]);
    }

    public function store(AnnouncementRequest $request): RedirectResponse
    {
        $announcement = Announcement::create($request->validated());

        return redirect()->route('admin.announcements.index')
            ->with('success', "Pengumuman \"{$announcement->title}\" ditambahkan. Alat menampilkannya dalam ±1 menit.");
    }

    public function edit(Announcement $announcement): View
    {
        return view('admin.announcements.form', ['announcement' => $announcement]);
    }

    public function update(AnnouncementRequest $request, Announcement $announcement): RedirectResponse
    {
        $announcement->update($request->validated());

        return redirect()->route('admin.announcements.index')
            ->with('success', "Pengumuman \"{$announcement->title}\" disimpan. Alat menerapkannya dalam ±1 menit.");
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        $announcement->delete();

        return redirect()->route('admin.announcements.index')
            ->with('success', "Pengumuman \"{$announcement->title}\" dihapus.");
    }

    /**
     * Tampilkan, sembunyikan, atau hapus beberapa pengumuman sekaligus. Disimpan per model supaya
     * updated_at ikut berubah dan config.announcements_rev terbarui.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('bulk', [
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:announcements,id'],
            'action' => ['required', Rule::in(array_keys(self::BULK_ACTIONS))],
        ], [
            'ids.required' => 'Pilih minimal satu pengumuman.',
        ], [
            'ids' => 'pengumuman',
            'ids.*' => 'pengumuman',
            'action' => 'aksi',
        ]);

        $announcements = Announcement::query()->whereKey($data['ids'])->get();

        foreach ($announcements as $announcement) {
            if ($data['action'] === 'delete') {
                $announcement->delete();
            } else {
                $announcement->update(['is_active' => $data['action'] === 'show']);
            }
        }

        return redirect()->route('admin.announcements.index')
            ->with('success', "{$announcements->count()} pengumuman ".self::BULK_ACTIONS[$data['action']].'. Alat menerapkannya dalam ±1 menit.');
    }

    /** Lama tiap pengumuman & jeda sebelum screensaver muncul. Dikirim di GET /announcements. */
    public function updateScreensaver(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('screensaver', [
            'interval' => ['required', 'integer', 'between:'.Setting::SCREENSAVER_INTERVAL_MIN.','.Setting::SCREENSAVER_INTERVAL_MAX],
            'idle' => ['required', 'integer', 'between:'.Setting::SCREENSAVER_IDLE_MIN.','.Setting::SCREENSAVER_IDLE_MAX],
        ], [], [
            'interval' => 'lama tiap pengumuman',
            'idle' => 'jeda screensaver',
        ]);

        Setting::setValue(Setting::SCREENSAVER_INTERVAL, (string) (int) $data['interval']);
        Setting::setValue(Setting::SCREENSAVER_IDLE, (string) (int) $data['idle']);

        return redirect()->route('admin.announcements.index')
            ->with('success', 'Pengaturan screensaver disimpan. Alat menerapkannya dalam ±1 menit.');
    }
}
