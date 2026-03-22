@extends('layouts.admin')
@section('title', 'Détail rendez-vous')

@section('content')
<div class="mb-8">
    <h1 class="text-2xl font-bold text-gray-900">Détail du rendez-vous</h1>
    <p class="text-sm text-gray-500 mt-1">
        <a href="{{ route('admin.appointments.index') }}" class="text-blue-600 hover:underline">&larr; Retour</a>
    </p>
</div>

<div class="max-w-2xl">
    <div class="bg-white rounded-lg shadow-sm p-6 space-y-4">
        <div class="grid grid-cols-2 gap-4">
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase">Client</p>
                <p class="text-sm font-semibold mt-1">{{ $appointment->client_name }}</p>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase">Statut</p>
                <span class="inline-flex mt-1 px-2 py-1 text-xs font-medium rounded-full
                    {{ $appointment->status === 'confirmed' ? 'bg-green-100 text-green-800' : '' }}
                    {{ $appointment->status === 'pending' ? 'bg-yellow-100 text-yellow-800' : '' }}
                    {{ $appointment->status === 'cancelled' ? 'bg-red-100 text-red-800' : '' }}">
                    {{ ucfirst($appointment->status) }}
                </span>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase">Email</p>
                <p class="text-sm mt-1">{{ $appointment->client_email }}</p>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase">Téléphone</p>
                <p class="text-sm mt-1">{{ $appointment->client_phone }}</p>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase">Date</p>
                <p class="text-sm font-semibold mt-1">{{ $appointment->appointment_date->format('d/m/Y') }}</p>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase">Heure</p>
                <p class="text-sm font-semibold mt-1">{{ \Carbon\Carbon::parse($appointment->appointment_time)->format('H:i') }}</p>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase">Service</p>
                <p class="text-sm mt-1">{{ $appointment->service->name ?? '-' }}</p>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase">Coiffeur</p>
                <p class="text-sm mt-1">{{ $appointment->stylist ?? 'Sans préférence' }}</p>
            </div>
        </div>

        @if($appointment->notes)
        <div class="pt-4 border-t border-gray-200">
            <p class="text-xs font-medium text-gray-500 uppercase">Notes</p>
            <p class="text-sm mt-1 text-gray-600">{{ $appointment->notes }}</p>
        </div>
        @endif

        <div class="flex items-center space-x-3 pt-4 border-t border-gray-200">
            <form method="POST" action="{{ route('admin.appointments.status', $appointment) }}">
                @csrf @method('PATCH')
                <input type="hidden" name="status" value="confirmed">
                <button type="submit" class="bg-green-600 text-white px-4 py-2 text-sm rounded-lg hover:bg-green-700">Confirmer</button>
            </form>
            <form method="POST" action="{{ route('admin.appointments.status', $appointment) }}">
                @csrf @method('PATCH')
                <input type="hidden" name="status" value="cancelled">
                <button type="submit" class="bg-red-600 text-white px-4 py-2 text-sm rounded-lg hover:bg-red-700">Annuler</button>
            </form>
            <form method="POST" action="{{ route('admin.appointments.destroy', $appointment) }}" onsubmit="return confirm('Supprimer ?')">
                @csrf @method('DELETE')
                <button type="submit" class="text-sm text-red-600 hover:underline">Supprimer</button>
            </form>
        </div>
    </div>
</div>
@endsection
