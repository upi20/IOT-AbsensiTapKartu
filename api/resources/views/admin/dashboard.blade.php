@extends('admin.layouts.app')

@section('title', 'Dasbor')

@section('content')
    <div class="page-head">
        <div>
            <h1>Dasbor</h1>
            <p>{{ now()->translatedFormat('l, d F Y') }}</p>
        </div>
        <span class="live" title="Diperbarui otomatis setiap 10 detik"><span class="dot on"></span> Diperbarui otomatis</span>
    </div>

    <div data-live="{{ route('admin.dashboard.live') }}">
        @include('admin.partials.dashboard-live')
    </div>
@endsection
