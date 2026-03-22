<?php

namespace App\Http\Controllers;

use App\Models\Service;

class ServiceController extends Controller
{
    public function index()
    {
        $categories = Service::where('active', true)
            ->orderBy('sort_order')
            ->get()
            ->groupBy('category');
        return view('public.tarifs', compact('categories'));
    }
}
