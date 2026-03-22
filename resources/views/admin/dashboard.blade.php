@extends('layouts.admin')
@section('title', 'Tableau de bord')

@section('content')
<div class="mb-8">
    <h1 class="text-2xl font-bold text-gray-900">Tableau de bord</h1>
    <p class="text-sm text-gray-500 mt-1">Vue d'ensemble de votre salon</p>
</div>

<!-- Stats -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    <div class="bg-white rounded-lg shadow-sm p-6">
        <div class="text-sm font-medium text-gray-500 uppercase tracking-wider">RDV Aujourd'hui</div>
        <div class="text-3xl font-bold text-gray-900 mt-2">{{ $todayAppointments->count() }}</div>
    </div>
    <div class="bg-white rounded-lg shadow-sm p-6">
        <div class="text-sm font-medium text-gray-500 uppercase tracking-wider">En attente</div>
        <div class="text-3xl font-bold text-yellow-600 mt-2">{{ $pendingAppointments }}</div>
    </div>
    <div class="bg-white rounded-lg shadow-sm p-6">
        <div class="text-sm font-medium text-gray-500 uppercase tracking-wider">Services actifs</div>
        <div class="text-3xl font-bold text-gray-900 mt-2">{{ $totalServices }}</div>
    </div>
</div>

<!-- Today's appointments -->
<div class="bg-white rounded-lg shadow-sm">
    <div class="px-6 py-4 border-b border-gray-200">
        <h2 class="text-lg font-semibold text-gray-900">Rendez-vous du jour</h2>
    </div>
    @if($todayAppointments->isEmpty())
    <div class="p-6 text-center text-gray-500 text-sm">
        Aucun rendez-vous aujourd'hui.
    </div>
    @else
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    <th class="px-6 py-3">Heure</th>
                    <th class="px-6 py-3">Client</th>
                    <th class="px-6 py-3">Service</th>
                    <th class="px-6 py-3">Statut</th>
                    <th class="px-6 py-3">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($todayAppointments as $apt)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 text-sm font-medium">{{ \Carbon\Carbon::parse($apt->appointment_time)->format('H:i') }}</td>
                    <td class="px-6 py-4 text-sm">{{ $apt->client_name }}</td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $apt->service->name ?? '-' }}</td>
                    <td class="px-6 py-4">
                        <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full
                            {{ $apt->status === 'confirmed' ? 'bg-green-100 text-green-800' : '' }}
                            {{ $apt->status === 'pending' ? 'bg-yellow-100 text-yellow-800' : '' }}
                            {{ $apt->status === 'cancelled' ? 'bg-red-100 text-red-800' : '' }}">
                            {{ ucfirst($apt->status) }}
                        </span>
                    </td>
                    <td class="px-6 py-4">
                        <a href="{{ route('admin.appointments.show', $apt) }}" class="text-sm text-blue-600 hover:underline">Voir</a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
@endsection
