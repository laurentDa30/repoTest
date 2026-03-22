<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Service;

class DashboardController extends Controller
{
    public function index()
    {
        $todayAppointments = Appointment::whereDate('appointment_date', today())
            ->where('status', '!=', 'cancelled')
            ->with('service')
            ->orderBy('appointment_time')
            ->get();
        $totalServices = Service::count();
        $pendingAppointments = Appointment::where('status', 'pending')->count();

        return view('admin.dashboard', compact('todayAppointments', 'totalServices', 'pendingAppointments'));
    }
}
