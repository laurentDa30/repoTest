@extends('layouts.admin')
@section('title', isset($service) ? 'Modifier le service' : 'Ajouter un service')

@section('content')
<div class="mb-8">
    <h1 class="text-2xl font-bold text-gray-900">{{ isset($service) ? 'Modifier le service' : 'Nouveau service' }}</h1>
    <p class="text-sm text-gray-500 mt-1">
        <a href="{{ route('admin.services.index') }}" class="text-blue-600 hover:underline">&larr; Retour aux services</a>
    </p>
</div>

<div class="max-w-2xl">
    <div class="bg-white rounded-lg shadow-sm p-6">
        <form method="POST" action="{{ isset($service) ? route('admin.services.update', $service) : route('admin.services.store') }}" class="space-y-5">
            @csrf
            @if(isset($service)) @method('PUT') @endif

            <div>
                <label for="category" class="block text-sm font-medium text-gray-700 mb-1">Catégorie</label>
                <select name="category" id="category" required class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none">
                    @foreach($categories as $cat)
                    <option value="{{ $cat }}" {{ old('category', $service->category ?? '') === $cat ? 'selected' : '' }}>{{ $cat }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Nom du service</label>
                <input type="text" name="name" id="name" required value="{{ old('name', $service->name ?? '') }}" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none">
            </div>

            <div>
                <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                <textarea name="description" id="description" rows="3" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none resize-none">{{ old('description', $service->description ?? '') }}</textarea>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="price" class="block text-sm font-medium text-gray-700 mb-1">Prix (€)</label>
                    <input type="number" name="price" id="price" required step="0.01" min="0" value="{{ old('price', $service->price ?? '') }}" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none">
                </div>
                <div>
                    <label for="duration" class="block text-sm font-medium text-gray-700 mb-1">Durée (min)</label>
                    <input type="number" name="duration" id="duration" required min="5" value="{{ old('duration', $service->duration ?? 30) }}" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none">
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="sort_order" class="block text-sm font-medium text-gray-700 mb-1">Ordre d'affichage</label>
                    <input type="number" name="sort_order" id="sort_order" min="0" value="{{ old('sort_order', $service->sort_order ?? 0) }}" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none">
                </div>
                <div class="flex items-end pb-1">
                    <label class="flex items-center space-x-2 cursor-pointer">
                        <input type="checkbox" name="active" value="1" {{ old('active', $service->active ?? true) ? 'checked' : '' }} class="w-4 h-4 text-gray-900 border-gray-300 rounded focus:ring-gray-900">
                        <span class="text-sm text-gray-700">Actif</span>
                    </label>
                </div>
            </div>

            <div class="flex items-center space-x-4 pt-4">
                <button type="submit" class="bg-gray-900 text-white px-6 py-2.5 text-sm font-medium rounded-lg hover:bg-gray-800 transition-colors">
                    {{ isset($service) ? 'Enregistrer' : 'Ajouter' }}
                </button>
                <a href="{{ route('admin.services.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Annuler</a>
            </div>
        </form>
    </div>
</div>
@endsection
