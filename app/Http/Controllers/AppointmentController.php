<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Service;
use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    public function create()
    {
        $services = Service::where('active', true)->orderBy('category')->orderBy('sort_order')->get();
        return view('public.reservation', compact('services'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_name' => 'required|string|max:255',
            'client_email' => 'required|email|max:255',
            'client_phone' => 'required|string|max:20',
            'service_id' => 'required|exists:services,id',
            'appointment_date' => 'required|date|after_or_equal:today',
            'appointment_time' => 'required|string',
            'stylist' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:500',
        ]);

        if (!Appointment::isSlotAvailable($validated['appointment_date'], $validated['appointment_time'], $validated['stylist'] ?? null)) {
            return back()->withInput()->withErrors(['appointment_time' => 'Ce créneau n\'est plus disponible. Veuillez en choisir un autre.']);
        }

        Appointment::create($validated);

        return redirect()->route('reservation')->with('success', 'Votre rendez-vous a été enregistré avec succès ! Vous recevrez une confirmation par email.');
    }

    public function availableSlots(Request $request)
    {
        $date = $request->query('date');
        $stylist = $request->query('stylist');

        if (!$date) {
            return response()->json([]);
        }

        $slots = [];
        $start = 9;
        $end = 19;

        for ($hour = $start; $hour < $end; $hour++) {
            foreach (['00', '30'] as $min) {
                $time = sprintf('%02d:%s', $hour, $min);
                if (Appointment::isSlotAvailable($date, $time, $stylist)) {
                    $slots[] = $time;
                }
            }
        }

        return response()->json($slots);
    }
}
