<!DOCTYPE html>
<html lang="fr" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Salon de coiffure - Coupe, coloration, soins capillaires. Prenez rendez-vous en ligne.">
    <title>@yield('title', 'Cris Création - Salon de Coiffure')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Inter:wght@300;400;500;600&display=swap');
        :root {
            --color-cream: #F5F0EB;
            --color-warm: #E8DDD3;
            --color-gold: #B8956A;
            --color-dark: #1A1A1A;
            --color-text: #333333;
        }
        body { font-family: 'Inter', sans-serif; color: var(--color-text); }
        .font-display { font-family: 'Playfair Display', serif; }
        .bg-cream { background-color: var(--color-cream); }
        .bg-warm { background-color: var(--color-warm); }
        .text-gold { color: var(--color-gold); }
        .bg-gold { background-color: var(--color-gold); }
        .border-gold { border-color: var(--color-gold); }
        .hover\:bg-gold-dark:hover { background-color: #A07D58; }
        .carousel-slide { transition: opacity 0.8s ease-in-out; }
        .fade-in { animation: fadeIn 0.6s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body class="bg-cream min-h-screen">
    <!-- Navigation -->
    <nav class="bg-white/90 backdrop-blur-md shadow-sm fixed w-full z-50 transition-all duration-300" id="navbar">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16 lg:h-20">
                <a href="{{ route('home') }}" class="font-display text-2xl lg:text-3xl font-bold text-gray-900 tracking-wide">
                    Cris Création
                </a>
                <!-- Desktop menu -->
                <div class="hidden md:flex items-center space-x-8">
                    <a href="{{ route('home') }}" class="text-sm font-medium text-gray-700 hover:text-gold transition-colors uppercase tracking-wider">Accueil</a>
                    <a href="{{ route('tarifs') }}" class="text-sm font-medium text-gray-700 hover:text-gold transition-colors uppercase tracking-wider">Tarifs</a>
                    <a href="{{ route('reservation') }}" class="text-sm font-medium text-gray-700 hover:text-gold transition-colors uppercase tracking-wider">Réservation</a>
                    <a href="{{ route('contact') }}" class="text-sm font-medium text-gray-700 hover:text-gold transition-colors uppercase tracking-wider">Contact</a>
                    <a href="{{ route('reservation') }}" class="bg-gold text-white px-6 py-2.5 text-sm font-medium uppercase tracking-wider hover:bg-gold-dark transition-colors">
                        Prendre RDV
                    </a>
                </div>
                <!-- Mobile menu button -->
                <button class="md:hidden p-2" id="mobile-menu-btn" aria-label="Menu">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
            </div>
        </div>
        <!-- Mobile menu -->
        <div class="md:hidden hidden bg-white border-t" id="mobile-menu">
            <div class="px-4 py-4 space-y-3">
                <a href="{{ route('home') }}" class="block text-sm font-medium text-gray-700 hover:text-gold uppercase tracking-wider py-2">Accueil</a>
                <a href="{{ route('tarifs') }}" class="block text-sm font-medium text-gray-700 hover:text-gold uppercase tracking-wider py-2">Tarifs</a>
                <a href="{{ route('reservation') }}" class="block text-sm font-medium text-gray-700 hover:text-gold uppercase tracking-wider py-2">Réservation</a>
                <a href="{{ route('contact') }}" class="block text-sm font-medium text-gray-700 hover:text-gold uppercase tracking-wider py-2">Contact</a>
                <a href="{{ route('reservation') }}" class="block bg-gold text-white px-6 py-3 text-sm font-medium uppercase tracking-wider text-center mt-3">
                    Prendre RDV
                </a>
            </div>
        </div>
    </nav>

    <!-- Content -->
    <main class="pt-16 lg:pt-20">
        @if(session('success'))
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
                <div class="bg-green-50 border border-green-200 text-green-800 px-6 py-4 rounded-lg fade-in">
                    {{ session('success') }}
                </div>
            </div>
        @endif
        @yield('content')
    </main>

    <!-- Footer -->
    <footer class="bg-gray-900 text-gray-300 mt-20">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                <div>
                    <h3 class="font-display text-2xl text-white mb-4">Cris Création</h3>
                    <p class="text-sm leading-relaxed text-gray-400">Votre salon de coiffure d'exception. Expertise, élégance et bien-être au service de votre beauté.</p>
                </div>
                <div>
                    <h4 class="text-sm font-semibold uppercase tracking-wider text-white mb-4">Navigation</h4>
                    <ul class="space-y-2 text-sm">
                        <li><a href="{{ route('home') }}" class="hover:text-gold transition-colors">Accueil</a></li>
                        <li><a href="{{ route('tarifs') }}" class="hover:text-gold transition-colors">Tarifs</a></li>
                        <li><a href="{{ route('reservation') }}" class="hover:text-gold transition-colors">Réservation</a></li>
                        <li><a href="{{ route('contact') }}" class="hover:text-gold transition-colors">Contact</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-sm font-semibold uppercase tracking-wider text-white mb-4">Contact</h4>
                    <ul class="space-y-2 text-sm text-gray-400">
                        <li>123 Rue de la Beauté, 75001 Paris</li>
                        <li>01 23 45 67 89</li>
                        <li>contact@criscreation.fr</li>
                    </ul>
                </div>
            </div>
            <div class="border-t border-gray-800 mt-8 pt-8 text-center text-sm text-gray-500">
                &copy; {{ date('Y') }} Cris Création. Tous droits réservés.
            </div>
        </div>
    </footer>

    <script>
        // Mobile menu toggle
        document.getElementById('mobile-menu-btn').addEventListener('click', function() {
            document.getElementById('mobile-menu').classList.toggle('hidden');
        });

        // Navbar scroll effect
        window.addEventListener('scroll', function() {
            const navbar = document.getElementById('navbar');
            if (window.scrollY > 50) {
                navbar.classList.add('shadow-md');
            } else {
                navbar.classList.remove('shadow-md');
            }
        });
    </script>
    @stack('scripts')
</body>
</html>