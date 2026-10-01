<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminDashboard;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(AdminDashboard $dashboard): View
    {
        return view('admin.dashboard', $dashboard->data());
    }

    /** Potongan HTML yang sama, diambil ulang tiap 10 detik oleh public/js/admin.js. */
    public function live(AdminDashboard $dashboard): View
    {
        return view('admin.partials.dashboard-live', $dashboard->data());
    }
}
