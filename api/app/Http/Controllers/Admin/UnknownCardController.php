<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\UnknownCardList;
use Illuminate\View\View;

class UnknownCardController extends Controller
{
    public function index(UnknownCardList $list): View
    {
        return view('admin.unknown-cards', ['cards' => $list->recent()]);
    }
}
