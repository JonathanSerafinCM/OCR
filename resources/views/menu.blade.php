<!-- resources/views/menu.blade.php -->
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Menú') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <!-- Sección de Preferencias Activas -->
            <div class="mb-6 bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <h3 class="font-bold text-lg mb-4">Tus Preferencias Activas:</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <h4 class="font-semibold">Restricciones Dietéticas:</h4>
                            <ul class="list-disc ml-5 mt-2">
                                @forelse($userPreferences['dietary_restrictions'] ?? [] as $restriction)
                                    <li>{{ $restriction }}</li>
                                @empty
                                    <li class="text-gray-500">Sin restricciones dietéticas</li>
                                @endforelse
                            </ul>
                        </div>
                        <div>
                            <h4 class="font-semibold">Categorías Favoritas:</h4>
                            <ul class="list-disc ml-5 mt-2">
                                @forelse($userPreferences['favorite_tags'] ?? [] as $tag)
                                    <li>{{ $tag }}</li>
                                @empty
                                    <li class="text-gray-500">Sin categorías favoritas</li>
                                @endforelse
                            </ul>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="{{ route('preferencias') }}" class="text-indigo-600 hover:text-indigo-900">
                            Modificar preferencias →
                        </a>
                    </div>
                </div>
            </div>

            <!-- Lista de Platillos -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    @foreach($menuItems as $item)
                        <div class="mb-6 p-4 border rounded-lg @if(isset($item['not_recommended']) && $item['not_recommended']) bg-red-50 @elseif(isset($item['recommendation_score']) && $item['recommendation_score'] > 0) bg-green-50 @endif">
                            <h2 class="text-xl font-bold mb-2">{{ $item['dish_name'] }}</h2>
                            <p class="text-gray-600">Precio: {{ $item['price'] }}</p>
                            <p class="text-gray-600">Descripción: {{ $item['description'] ?? 'Sin descripción' }}</p>
                            <p class="text-gray-600">Categoría: {{ $item['category'] ?? 'Sin categoría' }}</p>
                            
                            @if(!empty($item['allergens']))
                                <p class="text-red-600 mt-2">
                                    <span class="font-bold">Alérgenos:</span> 
                                    {{ implode(', ', $item['allergens']) }}
                                </p>
                            @endif

                            @if(isset($item['not_recommended']) && $item['not_recommended'])
                                <p class="text-red-600 mt-2">⚠️ Este plato contiene ingredientes que no coinciden con tus restricciones dietéticas.</p>
                            @endif

                            @if(isset($item['recommendation_score']) && $item['recommendation_score'] > 0)
                                <p class="text-green-600 mt-2">✨ Recomendado basado en tus preferencias</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</x-app-layout>