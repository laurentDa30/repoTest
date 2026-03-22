@extends('layouts.admin')
@section('title', 'Gestion des tarifs')

@section('content')
<div class="flex items-center justify-between mb-8">
    <div>
        <h1 class="text-2xl font-bold text-gray-900">Tarifs / Services</h1>
        <p class="text-sm text-gray-500 mt-1">Gérez vos prestations et tarifs</p>
    </div>
    <a href="{{ route('admin.services.create') }}" class="bg-gray-900 text-white px-4 py-2 text-sm font-medium rounded-lg hover:bg-gray-800 transition-colors">
        + Ajouter un service
    </a>
</div>

@forelse($services as $category => $categoryServices)
<div class="bg-white rounded-lg shadow-sm mb-6">
    <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 rounded-t-lg">
        <h2 class="text-lg font-semibold text-gray-900">{{ $category }}</h2>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                    <th class="px-6 py-3">Service</th>
                    <th class="px-6 py-3">Description</th>
                    <th class="px-6 py-3">Prix</th>
                    <th class="px-6 py-3">Durée</th>
                    <th class="px-6 py-3">Statut</th>
                    <th class="px-6 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach($categoryServices as $service)
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $service->name }}</td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ Str::limit($service->description, 50) }}</td>
                    <td class="px-6 py-4 text-sm font-semibold">{{ number_format($service->price, 0) }} €</td>
                    <td class="px-6 py-4 text-sm text-gray-500">{{ $service->duration }} min</td>
                    <td class="px-6 py-4">
                        <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full {{ $service->active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }}">
                            {{ $service->active ? 'Actif' : 'Inactif' }}
                        </span>
                    </td>
                    <td class="px-6 py-4 text-right space-x-2">
                        <a href="{{ route('admin.services.edit', $service) }}" class="text-sm text-blue-600 hover:underline">Modifier</a>
                        <form method="POST" action="{{ route('admin.services.destroy', $service) }}" class="inline" onsubmit="return confirm('Supprimer ce service ?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-sm text-red-600 hover:underline">Supprimer</button>
                        </form>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@empty
<div class="bg-white rounded-lg shadow-sm p-8 text-center text-gray-500">
    Aucun service. Ajoutez votre premier service.
</div>
@endforelse
@endsection
