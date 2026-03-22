@extends('layouts.public')
@section('title', 'Cris Création - Salon de Coiffure')

@section('content')
<!-- Hero Carousel -->
<section class="relative bg-gray-900 overflow-hidden" style="height: 80vh; min-height: 500px;">
    <div id="carousel" class="relative w-full h-full">
        @forelse($posts as $index => $post)
        <div class="carousel-slide absolute inset-0 {{ $index === 0 ? 'opacity-100' : 'opacity-0' }}" data-index="{{ $index }}">
            <img src="{{ $post->image_url }}" alt="{{ Str::limit($post->caption, 50) }}" class="w-full h-full object-cover" loading="{{ $index === 0 ? 'eager' : 'lazy' }}">
            <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/30 to-transparent"></div>
            <div class="absolute bottom-0 left-0 right-0 p-6 md:p-12 lg:p-16">
                <div class="max-w-3xl">
                    <span class="inline-block bg-gold text-white text-xs font-semibold px-3 py-1 uppercase tracking-wider mb-3">
                        {{ ucfirst($post->platform) }}
                    </span>
                    <p class="text-white text-lg md:text-xl leading-relaxed mb-2">{{ Str::limit($post->caption, 120) }}</p>
                    <div class="flex items-center space-x-4 text-gray-300 text-sm">
                        <span>{{ $post->posted_at->diffForHumans() }}</span>
                        <a href="{{ $post->post_url }}" target="_blank" rel="noopener" class="hover:text-gold transition-colors underline">Voir le post</a>
                    </div>
                </div>
            </div>
        </div>
        @empty
        <div class="absolute inset-0 flex items-center justify-center bg-gradient-to-br from-gray-800 to-gray-900">
            <div class="text-center text-white px-4">
                <h1 class="font-display text-4xl md:text-6xl font-bold mb-4">Cris Création</h1>
                <p class="text-xl text-gray-300">L'art de la coiffure</p>
            </div>
        </div>
        @endforelse
    </div>

    @if($posts->count() > 1)
    <!-- Carousel controls -->
    <button id="carousel-prev" class="absolute left-4 top-1/2 -translate-y-1/2 bg-white/20 hover:bg-white/40 backdrop-blur-sm text-white p-3 rounded-full transition-colors z-10" aria-label="Précédent">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    </button>
    <button id="carousel-next" class="absolute right-4 top-1/2 -translate-y-1/2 bg-white/20 hover:bg-white/40 backdrop-blur-sm text-white p-3 rounded-full transition-colors z-10" aria-label="Suivant">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
    </button>
    <!-- Dots -->
    <div class="absolute bottom-4 left-1/2 -translate-x-1/2 flex space-x-2 z-10">
        @foreach($posts as $index => $post)
        <button class="carousel-dot w-2.5 h-2.5 rounded-full transition-colors {{ $index === 0 ? 'bg-gold' : 'bg-white/50' }}" data-index="{{ $index }}" aria-label="Slide {{ $index + 1 }}"></button>
        @endforeach
    </div>
    @endif
</section>

<!-- Presentation -->
<section class="py-16 md:py-24 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="max-w-3xl mx-auto text-center fade-in">
            <h2 class="font-display text-3xl md:text-5xl font-bold text-gray-900 mb-6">Bienvenue chez Cris Création</h2>
            <div class="w-16 h-0.5 bg-gold mx-auto mb-8"></div>
            <p class="text-lg text-gray-600 leading-relaxed mb-6">
                Niché au coeur de Paris, notre salon vous accueille dans un cadre raffiné et chaleureux.
                Notre équipe de coiffeurs passionnés met son savoir-faire à votre service pour sublimer votre beauté naturelle.
            </p>
            <p class="text-gray-500 leading-relaxed">
                Que vous recherchiez une coupe tendance, une coloration sur mesure ou un soin revitalisant,
                nous vous offrons une expérience personnalisée dans une ambiance apaisante.
            </p>
        </div>
    </div>
</section>

<!-- Specialties -->
<section class="py-16 md:py-24 bg-cream">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-12">
            <h2 class="font-display text-3xl md:text-4xl font-bold text-gray-900 mb-4">Nos Spécialités</h2>
            <div class="w-16 h-0.5 bg-gold mx-auto"></div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div class="bg-white p-8 text-center shadow-sm hover:shadow-md transition-shadow fade-in">
                <div class="w-16 h-16 bg-cream rounded-full flex items-center justify-center mx-auto mb-6">
                    <svg class="w-8 h-8 text-gold" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.53 16.122a3 3 0 00-5.78 1.128 2.25 2.25 0 01-2.4 2.245 4.5 4.5 0 008.4-2.245c0-.399-.078-.78-.22-1.128zm0 0a15.998 15.998 0 003.388-1.62m-5.043-.025a15.994 15.994 0 011.622-3.395m3.42 3.42a15.995 15.995 0 004.764-4.648l3.876-5.814a1.151 1.151 0 00-1.597-1.597L14.146 6.32a15.996 15.996 0 00-4.649 4.764m3.42 3.42a6.776 6.776 0 00-3.42-3.42"/></svg>
                </div>
                <h3 class="font-display text-xl font-semibold mb-3">Coloration Expert</h3>
                <p class="text-gray-500 text-sm leading-relaxed">Balayage, mèches, couleur complète. Nos coloristes créent des nuances uniques qui subliment votre teint.</p>
            </div>
            <div class="bg-white p-8 text-center shadow-sm hover:shadow-md transition-shadow fade-in">
                <div class="w-16 h-16 bg-cream rounded-full flex items-center justify-center mx-auto mb-6">
                    <svg class="w-8 h-8 text-gold" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7.848 8.25l1.536.887M7.848 8.25a3 3 0 11-5.196-3 3 3 0 015.196 3zm1.536.887a2.165 2.165 0 011.083 1.839c.005.351.054.695.14 1.024M9.384 9.137l2.077 1.199M7.848 15.75l1.536-.887m-1.536.887a3 3 0 11-5.196 3 3 3 0 015.196-3zm1.536-.887a2.165 2.165 0 001.083-1.838c.005-.352.054-.695.14-1.025m-1.223 2.863l2.077-1.199m0-3.328a4.323 4.323 0 012.068-1.379l5.325-1.628a4.5 4.5 0 012.48 0l.518.159a2.25 2.25 0 011.633 2.165c0 .957-.607 1.812-1.514 2.126l-5.325 1.629a4.324 4.324 0 01-2.068.137m0-3.328l.458.407a2.25 2.25 0 01.69 2.044l-.112.677a2.25 2.25 0 00.797 2.148l.961.721"/></svg>
                </div>
                <h3 class="font-display text-xl font-semibold mb-3">Coupe Moderne</h3>
                <p class="text-gray-500 text-sm leading-relaxed">Des coupes tendance et personnalisées. Nos stylistes sont formés aux dernières techniques pour un résultat impeccable.</p>
            </div>
            <div class="bg-white p-8 text-center shadow-sm hover:shadow-md transition-shadow fade-in">
                <div class="w-16 h-16 bg-cream rounded-full flex items-center justify-center mx-auto mb-6">
                    <svg class="w-8 h-8 text-gold" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z"/></svg>
                </div>
                <h3 class="font-display text-xl font-semibold mb-3">Soins Premium</h3>
                <p class="text-gray-500 text-sm leading-relaxed">Des soins capillaires haut de gamme avec des produits professionnels pour des cheveux en pleine santé.</p>
            </div>
        </div>
    </div>
</section>

<!-- CTA -->
<section class="py-16 md:py-20 bg-gray-900 text-white">
    <div class="max-w-4xl mx-auto px-4 text-center">
        <h2 class="font-display text-3xl md:text-4xl font-bold mb-6">Prêt(e) pour une transformation ?</h2>
        <p class="text-gray-400 text-lg mb-8">Réservez votre rendez-vous en quelques clics et laissez nos experts prendre soin de vous.</p>
        <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
            <a href="{{ route('tarifs') }}" class="border border-white text-white px-8 py-3 text-sm font-medium uppercase tracking-wider hover:bg-white hover:text-gray-900 transition-colors w-full sm:w-auto text-center">
                Voir les tarifs
            </a>
            <a href="{{ route('reservation') }}" class="bg-gold text-white px-8 py-3 text-sm font-medium uppercase tracking-wider hover:bg-gold-dark transition-colors w-full sm:w-auto text-center">
                Prendre rendez-vous
            </a>
        </div>
    </div>
</section>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const slides = document.querySelectorAll('.carousel-slide');
    const dots = document.querySelectorAll('.carousel-dot');
    if (slides.length <= 1) return;

    let current = 0;
    let interval;

    function showSlide(index) {
        slides.forEach(s => s.classList.replace('opacity-100', 'opacity-0'));
        dots.forEach(d => { d.classList.remove('bg-gold'); d.classList.add('bg-white/50'); });
        slides[index].classList.replace('opacity-0', 'opacity-100');
        if (dots[index]) { dots[index].classList.remove('bg-white/50'); dots[index].classList.add('bg-gold'); }
        current = index;
    }

    function next() { showSlide((current + 1) % slides.length); }
    function prev() { showSlide((current - 1 + slides.length) % slides.length); }

    function startAutoplay() { interval = setInterval(next, 5000); }
    function stopAutoplay() { clearInterval(interval); }

    document.getElementById('carousel-next')?.addEventListener('click', function() { stopAutoplay(); next(); startAutoplay(); });
    document.getElementById('carousel-prev')?.addEventListener('click', function() { stopAutoplay(); prev(); startAutoplay(); });
    dots.forEach(dot => dot.addEventListener('click', function() { stopAutoplay(); showSlide(parseInt(this.dataset.index)); startAutoplay(); }));

    startAutoplay();
});
</script>
@endpush