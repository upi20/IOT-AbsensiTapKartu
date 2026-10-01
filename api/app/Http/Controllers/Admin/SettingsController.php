<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(): View
    {
        return view('admin.settings', [
            'apiKey' => Setting::apiKey(),
            'baseUrl' => url('/api/absensi'),
            'title' => Setting::title(),
            'dimAfter' => Setting::dimAfter(),
            'dimLevel' => Setting::dimLevel(),
        ]);
    }

    /** Judul layar alat. Dikirim ke semua alat lewat "config" di /ping & /heartbeat. PIN diatur per alat di halaman Alat. */
    public function updateTitle(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('title', [
            'title' => ['required', 'string', 'max:30'],
        ], [], ['title' => 'judul']);

        Setting::setValue(Setting::TITLE, trim($data['title']));

        return redirect()->route('admin.settings')
            ->with('success', 'Judul disimpan. Alat menerapkannya pada heartbeat berikutnya (maks. 1 menit).');
    }

    /** Layar redup alat. Dikirim ke semua alat lewat "config" (dim_after, dim_level) di /ping & /heartbeat. */
    public function updateScreen(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('screen', [
            'dim_after' => ['required', 'integer', 'between:0,'.Setting::DIM_AFTER_MAX,
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ((int) $value > 0 && (int) $value < Setting::DIM_AFTER_MIN) {
                        $fail(':Attribute harus 0 (tidak pernah redup) atau '.Setting::DIM_AFTER_MIN.' sampai '.Setting::DIM_AFTER_MAX.'.');
                    }
                }],
            'dim_level' => ['required', 'integer', 'between:0,'.Setting::DIM_LEVEL_MAX],
        ], [], [
            'dim_after' => 'redup setelah',
            'dim_level' => 'kecerahan saat redup',
        ]);

        Setting::setValue(Setting::DIM_AFTER, (string) (int) $data['dim_after']);
        Setting::setValue(Setting::DIM_LEVEL, (string) (int) $data['dim_level']);

        return redirect()->route('admin.settings')
            ->with('success', 'Pengaturan layar disimpan. Alat menerapkannya pada heartbeat berikutnya (maks. 1 menit).');
    }

    public function regenerateApiKey(): RedirectResponse
    {
        Setting::regenerateApiKey();

        return redirect()->route('admin.settings')
            ->with('success', 'API key baru dibuat. Masukkan key ini di menu Pengaturan setiap alat.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('password', [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed', 'different:current_password'],
        ], [], [
            'current_password' => 'password saat ini',
            'password' => 'password baru',
        ]);

        $request->user()->forceFill(['password' => $data['password']])->save();
        $request->session()->regenerate();

        return redirect()->route('admin.settings')->with('success', 'Password berhasil diganti.');
    }
}
