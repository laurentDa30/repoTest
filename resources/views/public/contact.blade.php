@extends('layouts.public')
@section('title', 'Contact - Cris Création')

@section('content')
<section class="py-16 md:py-24">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-16">
            <h1 class="font-display text-4xl md:text-5xl font-bold text-gray-900 mb-4">Contact</h1>
            <div class="w-16 h-0.5 bg-gold mx-auto mb-6"></div>
            <p class="text-gray-500 text-lg">N'hésitez pas à nous contacter</p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-12">
            <!-- Info -->
            <div class="space-y-8">
                <div class="bg-white p-8 shadow-sm">
                    <h2 class="font-display text-2xl font-semibold mb-6">Informations</h2>
                    <div class="space-y-4">
                        <div class="flex items-start space-x-4">
                            <svg class="w-5 h-5 text-gold mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <div>
                                <p class="font-medium text-gray-900">Adresse</p>
                                <p class="text-gray-500 text-sm">123 Rue de la Beauté, 75001 Paris</p>
                            </div>
                        </div>
                        <div class="flex items-start space-x-4">
                            <svg class="w-5 h-5 text-gold mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                            <div>
                                <p class="font-medium text-gray-900">Téléphone</p>
                                <p class="text-gray-500 text-sm">01 23 45 67 89</p>
                            </div>
                        </div>
                        <div class="flex items-start space-x-4">
                            <svg class="w-5 h-5 text-gold mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            <div>
                                <p class="font-medium text-gray-900">Email</p>
                                <p class="text-gray-500 text-sm">contact@criscreation.fr</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-8 shadow-sm">
                    <h2 class="font-display text-2xl font-semibold mb-6">Horaires d'ouverture</h2>
                    <div class="space-y-2 text-sm">
                        <div class="flex justify-between py-2 border-b border-gray-100">
                            <span class="text-gray-600">Lundi</span>
                            <span class="font-medium text-gray-400">Fermé</span>
                        </div>
                        <div class="flex justify-between py-2 border-b border-gray-100">
                            <span class="text-gray-600">Mardi - Vendredi</span>
                            <span class="font-medium text-gray-900">9h00 - 19h00</span>
                        </div>
                        <div class="flex justify-between py-2">
                            <span class="text-gray-600">Samedi</span>
                            <span class="font-medium text-gray-900">9h00 - 18h00</span>
                        </div>
                        <div class="flex justify-between py-2">
                            <span class="text-gray-600">Dimanche</span>
                            <span class="font-medium text-gray-400">Fermé</span>
                        </div>
                    </div>
                </div>

                <!-- Google Maps placeholder -->
                <div class="bg-gray-200 h-64 flex items-center justify-center shadow-sm">
                    <div class="text-center text-gray-500">
                        <svg class="w-12 h-12 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"/></svg>
                        <p class="text-sm">Carte Google Maps</p>
                        <p class="text-xs mt-1">Configurez votre clé API Google Maps</p>
                    </div>
                </div>
            </div>

            <!-- Contact form -->
            <div class="bg-white p-8 shadow-sm h-fit">
                <h2 class="font-display text-2xl font-semibold mb-6">Envoyez-nous un message</h2>
                <form method="POST" action="{{ route('contact.send') }}" class="space-y-5">
                    @csrf
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Nom</label>
                        <input type="text" name="name" id="name" required value="{{ old('name') }}" class="w-full border border-gray-300 px-4 py-3 text-sm focus:ring-1 focus:ring-gold focus:border-gold outline-none transition-colors" placeholder="Votre nom">
                        @error('name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                        <input type="email" name="email" id="email" required value="{{ old('email') }}" class="w-full border border-gray-300 px-4 py-3 text-sm focus:ring-1 focus:ring-gold focus:border-gold outline-none transition-colors" placeholder="votre@email.com">
                        @error('email') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="message" class="block text-sm font-medium text-gray-700 mb-1">Message</label>
                        <textarea name="message" id="message" rows="5" required class="w-full border border-gray-300 px-4 py-3 text-sm focus:ring-1 focus:ring-gold focus:border-gold outline-none transition-colors resize-none" placeholder="Votre message...">{{ old('message') }}</textarea>
                        @error('message') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="w-full bg-gold text-white py-3 text-sm font-medium uppercase tracking-wider hover:bg-gold-dark transition-colors">
                        Envoyer
                    </button>
                </form>
            </div>
        </div>
    </div>
</section>
@endsection
