<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function index()
    {
        $settings = [
            'social_hashtag' => Setting::get('social_hashtag', '#monsalon'),
            'instagram_enabled' => Setting::get('instagram_enabled', '1'),
            'facebook_enabled' => Setting::get('facebook_enabled', '1'),
            'posts_count' => Setting::get('posts_count', '5'),
            'salon_name' => Setting::get('salon_name', 'Mon Salon'),
            'salon_address' => Setting::get('salon_address', ''),
            'salon_phone' => Setting::get('salon_phone', ''),
            'salon_email' => Setting::get('salon_email', ''),
            'opening_hours' => Setting::get('opening_hours', ''),
        ];
        return view('admin.settings.index', compact('settings'));
    }

    public function update(Request $request)
    {
        $keys = [
            'social_hashtag', 'instagram_enabled', 'facebook_enabled',
            'posts_count', 'salon_name', 'salon_address', 'salon_phone',
            'salon_email', 'opening_hours',
        ];

        foreach ($keys as $key) {
            Setting::set($key, $request->input($key));
        }

        // Handle checkboxes
        Setting::set('instagram_enabled', $request->has('instagram_enabled') ? '1' : '0');
        Setting::set('facebook_enabled', $request->has('facebook_enabled') ? '1' : '0');

        return back()->with('success', 'Paramètres sauvegardés.');
    }
}
