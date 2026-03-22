@extends('layouts.public')
@section('title', 'Nos Tarifs - Cris Création')

@section('content')
<section class="py-16 md:py-24">
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-16">
            <h1 class="font-display text-4xl md:text-5xl font-bold text-gray-900 mb-4">Nos Tarifs</h1>
            <div class="w-16 h-0.5 bg-gold mx-auto mb-6"></div>
            <p class="text-gray-500 text-lg">Des prestations de qualité à des prix accessibles</p>
        </div>

        @forelse($categories as $category => $services)
        <div class="mb-12 fade-in">
            <h2 class="font-display text-2xl md:text-3xl font-semibold text-gray-900 mb-6 pb-3 border-b border-gray-200">{{ $category }}</h2>
            <div class="space-y-4">
                @foreach($services as $service)
                <div class="flex items-start justify-between py-4 border-b border-gray-100 last:border-0 hover:bg-white/50 px-4 -mx-4 transition-colors">
                    <div class="flex-1 pr-4">
                        <h3 class="font-semibold text-gray-900">{{ $service->name }}</h3>
                        @if($service->description)
                        <p class="text-sm text-gray-500 mt-1">{{ $service->description }}</p>
                        @endif
                        <p class="text-xs text-gray-400 mt-1">{{ $service->duration }} min</p>
                    </div>
                    <div class="text-right flex-shrink-0">
                        <span class="font-display text-xl font-semibold text-gold">{{ number_format($service->price, 0) }} €</span>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
        @empty
        <div class="text-center py-12">
            <p class="text-gray-500 text-lg">Les tarifs seront bientôt disponibles.</p>
        </div>
        @endforelse

        <div class="mt-12 text-center">
            <a href="{{ route('reservation') }}" class="inline-block bg-gold text-white px-10 py-4 text-sm font-medium uppercase tracking-wider hover:bg-gold-dark transition-colors">
                Prendre rendez-vous
            </a>
        </div>
    </div>
</section>
@endsection
