@props(['name'])
<svg {{ $attributes->merge(['class' => 'icon']) }} viewBox="0 0 24 24" aria-hidden="true" focusable="false">
@switch($name)
    @case('home')<path d="M3.5 10.5 12 3.5l8.5 7V20a1 1 0 0 1-1 1H15v-6H9v6H4.5a1 1 0 0 1-1-1z"/>@break
    @case('users')<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8"/><path d="M18.5 14.2A6.5 6.5 0 0 1 21.5 20"/>@break
    @case('card')<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19"/><path d="M6.5 15h4"/>@break
    @case('tap')<rect x="3" y="6" width="12" height="15" rx="2"/><path d="M6.5 16.5h5"/><path d="M17.5 8.5a4 4 0 0 1 0 5"/><path d="M20 6a7.5 7.5 0 0 1 0 10"/>@break
    @case('report')<path d="M14 3H6.5a1.5 1.5 0 0 0-1.5 1.5v15A1.5 1.5 0 0 0 6.5 21h11a1.5 1.5 0 0 0 1.5-1.5V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h6"/>@break
    @case('device')<rect x="6" y="6" width="12" height="12" rx="2"/><path d="M9.5 2.5V6M14.5 2.5V6M9.5 18v3.5M14.5 18v3.5M2.5 9.5H6M2.5 14.5H6M18 9.5h3.5M18 14.5h3.5"/>@break
    @case('settings')<path d="M4 6.5h8.5M16.5 6.5H20M4 12h2.5M10.5 12H20M4 17.5h10.5M18.5 17.5H20"/><circle cx="14.5" cy="6.5" r="2"/><circle cx="8.5" cy="12" r="2"/><circle cx="16.5" cy="17.5" r="2"/>@break
    @case('megaphone')<path d="M4 10v4a1 1 0 0 0 1 1h2.5l8.5 5V4L7.5 9H5a1 1 0 0 0-1 1z"/><path d="m7.5 15 1.5 5h2.5l-1.2-4"/><path d="M19.5 9.5a3.5 3.5 0 0 1 0 5"/>@break
    @case('logout')<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="m10 17-5-5 5-5"/><path d="M5 12h11"/>@break
    @case('menu')<path d="M4 7h16M4 12h16M4 17h16"/>@break
    @case('plus')<path d="M12 5v14M5 12h14"/>@break
    @case('search')<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>@break
    @case('download')<path d="M12 4v11"/><path d="m7 10.5 5 4.5 5-4.5"/><path d="M5 20h14"/>@break
    @case('copy')<rect x="8.5" y="8.5" width="11.5" height="11.5" rx="2"/><path d="M15.5 8.5V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v7.5a2 2 0 0 0 2 2h2.5"/>@break
    @case('key')<circle cx="8" cy="15.5" r="4"/><path d="m10.9 12.6 8.6-8.6M16.5 7l2.5 2.5"/>@break
    @case('edit')<path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>@break
    @case('trash')<path d="M4 7h16M10 11v6M14 11v6"/><path d="M6 7l1 12.5A1.5 1.5 0 0 0 8.5 21h7a1.5 1.5 0 0 0 1.5-1.5L18 7"/><path d="M9 7V4.5A1.5 1.5 0 0 1 10.5 3h3A1.5 1.5 0 0 1 15 4.5V7"/>@break
    @case('power')<path d="M12 3v8"/><path d="M6.5 6.5a8 8 0 1 0 11 0"/>@break
    @case('lock')<rect x="4.5" y="10.5" width="15" height="10" rx="2"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/>@break
@endswitch
</svg>
