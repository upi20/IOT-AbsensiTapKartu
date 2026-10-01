<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MemberRequest;
use App\Models\Member;
use App\Services\MemberPhoto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MemberController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $members = Member::query()
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';

                $query->where(fn ($w) => $w
                    ->where('name', 'ilike', $like)
                    ->orWhere('identifier', 'ilike', $like)
                    ->orWhere('card_uid', 'ilike', $like));
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.members.index', [
            'members' => $members,
            'q' => $q,
            'total' => Member::count(),
        ]);
    }

    /** Form tambah. ?kartu=0218893066 mengisi nomor kartu (dari halaman "Kartu belum terdaftar"). */
    public function create(Request $request): View
    {
        $member = new Member(['is_active' => true]);
        $card = Member::normalizeUid((string) $request->query('kartu', ''));

        if (Member::isValidUid($card)) {
            $member->card_uid = $card;
        }

        return view('admin.members.form', ['member' => $member]);
    }

    public function store(MemberRequest $request, MemberPhoto $photos): RedirectResponse
    {
        $member = Member::create($request->memberData());
        $this->savePhoto($request, $member, $photos);

        return redirect()->route('admin.members.index')
            ->with('success', "Anggota {$member->name} ditambahkan.");
    }

    public function edit(Member $member): View
    {
        return view('admin.members.form', ['member' => $member]);
    }

    public function update(MemberRequest $request, Member $member, MemberPhoto $photos): RedirectResponse
    {
        $member->update($request->memberData());
        $this->savePhoto($request, $member, $photos);

        return redirect()->route('admin.members.index')
            ->with('success', "Data {$member->name} disimpan.");
    }

    public function destroy(Member $member, MemberPhoto $photos): RedirectResponse
    {
        $name = $member->name;
        $photos->delete($member);
        $member->delete();

        return redirect()->route('admin.members.index')->with('success', "Anggota {$name} dihapus.");
    }

    private function savePhoto(MemberRequest $request, Member $member, MemberPhoto $photos): void
    {
        if ($request->boolean('remove_photo')) {
            $photos->delete($member);
        }

        if (! $request->hasFile('photo') || ! MemberPhoto::isSupported()) {
            return;
        }

        try {
            $photos->replace($member, $request->file('photo'));
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['photo' => 'Foto tidak bisa dibaca. Coba file JPG/PNG lain.'])
                ->redirectTo(route('admin.members.edit', $member));
        }
    }
}
