<?php

namespace App\Http\Controllers;

use App\Models\SocialPost;
use App\Models\Service;

class HomeController extends Controller
{
    public function index()
    {
        $posts = SocialPost::orderBy('posted_at', 'desc')->take(5)->get();
        $services = Service::where('active', true)->orderBy('sort_order')->get();
        return view('public.home', compact('posts', 'services'));
    }
}
