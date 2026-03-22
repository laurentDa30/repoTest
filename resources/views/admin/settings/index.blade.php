@extends('layouts.admin')
@section('title', 'Paramètres')

@section('content')
<div class="mb-8">
    <h1 class="text-2xl font-bold text-gray-900">Paramètres</h1>
    <p class="text-sm text-gray-500 mt-1">Configuration du salon et des réseaux sociaux</p>
</div>

<div class="max-w-2xl">
    <form method="POST" action="{{ route('admin.settings.update') }}" class="space-y-6">
        @csrf @method('PUT')

        <!-- Salon info -->
        <div class="bg-white rounded-lg shadow-sm p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Informations du salon</h2>
            <div class="space-y-4">
                <div>
                    <label for="salon_name" class="block text-sm font-medium text-gray-700 mb-1">Nom du salon</label>
                    <input type="text" name="salon_name" id="salon_name" value="{{ $settings['salon_name'] }}" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none">
                </div>
                <div>
                    <label for="salon_address" class="block text-sm font-medium text-gray-700 mb-1">Adresse</label>
                    <input type="text" name="salon_address" id="salon_address" value="{{ $settings['salon_address'] }}" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="salon_phone" class="block text-sm font-medium text-gray-700 mb-1">Téléphone</label>
                        <input type="text" name="salon_phone" id="salon_phone" value="{{ $settings['salon_phone'] }}" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none">
                    </div>
                    <div>
                        <label for="salon_email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                        <input type="email" name="salon_email" id="salon_email" value="{{ $settings['salon_email'] }}" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none">
                    </div>
                </div>
                <div>
                    <label for="opening_hours" class="block text-sm font-medium text-gray-700 mb-1">Horaires d'ouverture</label>
                    <textarea name="opening_hours" id="opening_hours" rows="4" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none resize-none">{{ $settings['opening_hours'] }}</textarea>
                    <p class="text-xs text-gray-400 mt-1">Une ligne par jour</p>
                </div>
            </div>
        </div>

        <!-- Social media -->
        <div class="bg-white rounded-lg shadow-sm p-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Réseaux sociaux</h2>
            <div class="space-y-4">
                <div>
                    <label for="social_hashtag" class="block text-sm font-medium text-gray-700 mb-1">Hashtag à surveiller</label>
                    <input type="text" name="social_hashtag" id="social_hashtag" value="{{ $settings['social_hashtag'] }}" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none" placeholder="#monsalon">
                </div>
                <div>
                    <label for="posts_count" class="block text-sm font-medium text-gray-700 mb-1">Nombre de posts à afficher</label>
                    <input type="number" name="posts_count" id="posts_count" min="1" max="20" value="{{ $settings['posts_count'] }}" class="w-full border border-gray-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none">
                </div>
                <div class="flex items-center space-x-6">
                    <label class="flex items-center space-x-2 cursor-pointer">
                        <input type="checkbox" name="instagram_enabled" value="1" {{ $settings['instagram_enabled'] === '1' ? 'checked' : '' }} class="w-4 h-4 text-gray-900 border-gray-300 rounded focus:ring-gray-900">
                        <span class="text-sm text-gray-700">Instagram</span>
                    </label>
                    <label class="flex items-center space-x-2 cursor-pointer">
                        <input type="checkbox" name="facebook_enabled" value="1" {{ $settings['facebook_enabled'] === '1' ? 'checked' : '' }} class="w-4 h-4 text-gray-900 border-gray-300 rounded focus:ring-gray-900">
                        <span class="text-sm text-gray-700">Facebook</span>
                    </label>
                </div>
                <p class="text-xs text-gray-400">Les tokens d'accès Instagram et Facebook doivent être configurés dans le fichier .env</p>
            </div>
        </div>

        <button type="submit" class="bg-gray-900 text-white px-6 py-2.5 text-sm font-medium rounded-lg hover:bg-gray-800 transition-colors">
            Sauvegarder
        </button>
    </form>
</div>
@endsection
