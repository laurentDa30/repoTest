@extends('layouts.public')
@section('title', 'Réservation - Cris Création')

@section('content')
<section class="py-16 md:py-24">
    <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-12">
            <h1 class="font-display text-4xl md:text-5xl font-bold text-gray-900 mb-4">Prendre Rendez-vous</h1>
            <div class="w-16 h-0.5 bg-gold mx-auto mb-6"></div>
            <p class="text-gray-500 text-lg">Réservez votre créneau en quelques clics</p>
        </div>

        <div class="bg-white p-8 md:p-10 shadow-sm fade-in">
            <form method="POST" action="{{ route('reservation.store') }}" class="space-y-6" id="reservation-form">
                @csrf

                <!-- Service -->
                <div>
                    <label for="service_id" class="block text-sm font-medium text-gray-700 mb-1">Prestation</label>
                    <select name="service_id" id="service_id" required class="w-full border border-gray-300 px-4 py-3 text-sm focus:ring-1 focus:ring-gold focus:border-gold outline-none transition-colors bg-white">
                        <option value="">Choisir une prestation</option>
                        @foreach($services->groupBy('category') as $category => $categoryServices)
                        <optgroup label="{{ $category }}">
                            @foreach($categoryServices as $service)
                            <option value="{{ $service->id }}" {{ old('service_id') == $service->id ? 'selected' : '' }}>
                                {{ $service->name }} - {{ number_format($service->price, 0) }} € ({{ $service->duration }} min)
                            </option>
                            @endforeach
                        </optgroup>
                        @endforeach
                    </select>
                    @error('service_id') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                <!-- Stylist -->
                <div>
                    <label for="stylist" class="block text-sm font-medium text-gray-700 mb-1">Coiffeur (optionnel)</label>
                    <select name="stylist" id="stylist" class="w-full border border-gray-300 px-4 py-3 text-sm focus:ring-1 focus:ring-gold focus:border-gold outline-none transition-colors bg-white">
                        <option value="">Sans préférence</option>
                        <option value="Sophie" {{ old('stylist') == 'Sophie' ? 'selected' : '' }}>Sophie</option>
                        <option value="Marie" {{ old('stylist') == 'Marie' ? 'selected' : '' }}>Marie</option>
                        <option value="Lucas" {{ old('stylist') == 'Lucas' ? 'selected' : '' }}>Lucas</option>
                    </select>
                </div>

                <!-- Date -->
                <div>
                    <label for="appointment_date" class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                    <input type="date" name="appointment_date" id="appointment_date" required value="{{ old('appointment_date') }}" min="{{ date('Y-m-d') }}" class="w-full border border-gray-300 px-4 py-3 text-sm focus:ring-1 focus:ring-gold focus:border-gold outline-none transition-colors">
                    @error('appointment_date') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                <!-- Time slots -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Heure</label>
                    <div id="time-slots" class="grid grid-cols-4 sm:grid-cols-5 gap-2">
                        <p class="text-gray-400 text-sm col-span-full">Sélectionnez d'abord une date</p>
                    </div>
                    <input type="hidden" name="appointment_time" id="appointment_time" value="{{ old('appointment_time') }}">
                    @error('appointment_time') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                <hr class="border-gray-100">

                <!-- Client info -->
                <div>
                    <label for="client_name" class="block text-sm font-medium text-gray-700 mb-1">Nom complet</label>
                    <input type="text" name="client_name" id="client_name" required value="{{ old('client_name') }}" class="w-full border border-gray-300 px-4 py-3 text-sm focus:ring-1 focus:ring-gold focus:border-gold outline-none transition-colors" placeholder="Votre nom">
                    @error('client_name') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="client_email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                        <input type="email" name="client_email" id="client_email" required value="{{ old('client_email') }}" class="w-full border border-gray-300 px-4 py-3 text-sm focus:ring-1 focus:ring-gold focus:border-gold outline-none transition-colors" placeholder="votre@email.com">
                        @error('client_email') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="client_phone" class="block text-sm font-medium text-gray-700 mb-1">Téléphone</label>
                        <input type="tel" name="client_phone" id="client_phone" required value="{{ old('client_phone') }}" class="w-full border border-gray-300 px-4 py-3 text-sm focus:ring-1 focus:ring-gold focus:border-gold outline-none transition-colors" placeholder="06 12 34 56 78">
                        @error('client_phone') <p class="text-red-500 text-xs mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label for="notes" class="block text-sm font-medium text-gray-700 mb-1">Notes (optionnel)</label>
                    <textarea name="notes" id="notes" rows="3" class="w-full border border-gray-300 px-4 py-3 text-sm focus:ring-1 focus:ring-gold focus:border-gold outline-none transition-colors resize-none" placeholder="Informations complémentaires...">{{ old('notes') }}</textarea>
                </div>

                <button type="submit" class="w-full bg-gold text-white py-4 text-sm font-medium uppercase tracking-wider hover:bg-gold-dark transition-colors">
                    Confirmer le rendez-vous
                </button>
            </form>
        </div>
    </div>
</section>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const dateInput = document.getElementById('appointment_date');
    const stylistSelect = document.getElementById('stylist');
    const slotsContainer = document.getElementById('time-slots');
    const timeInput = document.getElementById('appointment_time');

    function loadSlots() {
        const date = dateInput.value;
        if (!date) return;

        const stylist = stylistSelect.value;
        const url = '{{ route("api.slots") }}?date=' + date + (stylist ? '&stylist=' + stylist : '');

        slotsContainer.innerHTML = '<p class="text-gray-400 text-sm col-span-full">Chargement...</p>';

        fetch(url)
            .then(r => r.json())
            .then(slots => {
                if (slots.length === 0) {
                    slotsContainer.innerHTML = '<p class="text-gray-400 text-sm col-span-full">Aucun créneau disponible pour cette date.</p>';
                    return;
                }

                slotsContainer.innerHTML = '';
                slots.forEach(slot => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.textContent = slot;
                    btn.className = 'py-2.5 px-3 text-sm border border-gray-300 hover:border-gold hover:text-gold transition-colors text-center';
                    if (timeInput.value === slot) {
                        btn.className += ' bg-gold text-white border-gold';
                    }
                    btn.addEventListener('click', function() {
                        timeInput.value = slot;
                        slotsContainer.querySelectorAll('button').forEach(b => {
                            b.className = 'py-2.5 px-3 text-sm border border-gray-300 hover:border-gold hover:text-gold transition-colors text-center';
                        });
                        btn.className = 'py-2.5 px-3 text-sm border border-gold bg-gold text-white transition-colors text-center';
                    });
                    slotsContainer.appendChild(btn);
                });
            });
    }

    dateInput.addEventListener('change', loadSlots);
    stylistSelect.addEventListener('change', loadSlots);

    if (dateInput.value) loadSlots();
});
</script>
@endpush
