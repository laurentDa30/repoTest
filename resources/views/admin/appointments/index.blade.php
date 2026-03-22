@extends('layouts.admin')
@section('title', 'Gestion des rendez-vous')

@section('content')
<div class="mb-8">
    <h1 class="text-2xl font-bold text-gray-900">Rendez-vous</h1>
    <p class="text-sm text-gray-500 mt-1">Gérez les rendez-vous de vos clients</p>
</div>

<!-- Filters -->
<div class="bg-white rounded-lg shadow-sm p-4 mb-6">
    <form method="GET" action="{{ route('admin.appointments.index') }}" class="flex flex-wrap items-end gap-4">
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Date</label>
            <input type="date" name="date" value="{{ request('date') }}" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Statut</label>
            <select name="status" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none">
                <option value="">Tous</option>
                <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>En attente</option>
                <option value="confirmed" {{ request('status') === 'confirmed' ? 'selected' : '' }}>Confirmé</option>
                <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>Annulé</option>
            </select>
        </div>
        <button type="submit" class="bg-gray-900 text-white px-4 py-2 text-sm font-medium rounded-lg hover:bg-gray-800">Filtrer</button>
        <a href="{{ route('admin.appointments.index') }}" class="text-sm text-gray-500 hover:underline py-2">Réinitialiser</a>
    </form>
</div>

<div class="bg-white rounded-lg shadow-sm">
    @if($appointments->isEmpty())
    <div class="p-8 text-center text-gray-500 text-sm">Aucun rendez-vous trouvé.</div>
    @else
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    <th class="px-6 py-3">Date</th>
                    <th class="px-6 py-3">Heure</th>
                    <th class="px-6 py-3">Client</th>
                    <th class="px-6 py-3">Service</th>
                    <th class="px-6 py-3">Statut</th>
                    <th class="px-6 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($appointments as $apt)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 text-sm">{{ $apt->appointment_date->format('d/m/Y') }}</td>
                    <td class="px-6 py-4 text-sm font-medium">{{ \Carbon\Carbon::parse($apt->appointment_time)->format('H:i') }}</td>
                    <td class="px-6 py-4">
                        <div class="text-sm font-medium text-gray-900">{{ $apt->client_name }}</div>
                        <div class="text-xs text-gray-500">{{ $apt->client_phone }}</div>
                    </td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $apt->service->name ?? '-' }}</td>
                    <td class="px-6 py-4">
                        <form method="POST" action="{{ route('admin.appointments.status', $apt) }}" class="inline">
                            @csrf @method('PATCH')
                            <select name="status" onchange="this.form.submit()" class="text-xs border border-gray-300 rounded px-2 py-1 focus:ring-1 focus:ring-gray-900
                                {{ $apt->status === 'confirmed' ? 'bg-green-50 text-green-800' : '' }}
                                {{ $apt->status === 'pending' ? 'bg-yellow-50 text-yellow-800' : '' }}
                                {{ $apt->status === 'cancelled' ? 'bg-red-50 text-red-800' : '' }}">
                                <option value="pending" {{ $apt->status === 'pending' ? 'selected' : '' }}>En attente</option>
                                <option value="confirmed" {{ $apt->status === 'confirmed' ? 'selected' : '' }}>Confirmé</option>
                                <option value="cancelled" {{ $apt->status === 'cancelled' ? 'selected' : '' }}>Annulé</option>
                            </select>
                        </form>
                    </td>
                    <td class="px-6 py-4 text-right space-x-2">
                        <a href="{{ route('admin.appointments.show', $apt) }}" class="text-sm text-blue-600 hover:underline">Détails</a>
                        <form method="POST" action="{{ route('admin.appointments.destroy', $apt) }}" class="inline" onsubmit="return confirm('Supprimer ce rendez-vous ?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-sm text-red-600 hover:underline">Supprimer</button>
                        </form>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="px-6 py-4 border-t border-gray-200">
        {{ $appointments->withQueryString()->links() }}
    </div>
    @endif
</div>
@endsection
