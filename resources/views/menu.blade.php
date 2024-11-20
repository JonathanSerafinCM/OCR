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
                        <div class="menu-item mb-4 p-4 rounded-lg shadow border 
                            {{-- Aplicar estilo verde suave si es favorito --}}
                            {{ $item['is_favorite'] ? 'bg-green-50 border-green-200' : 'bg-white border-gray-200' }}
                            {{-- Aplicar estilo amarillo suave si tiene alérgenos --}}
                            {{ $item['has_allergens'] ? 'border-yellow-200' : '' }}">
                            
                            <h3 class="text-lg font-semibold mb-2 {{ $item['is_favorite'] ? 'text-green-700' : 'text-gray-900' }}">
                                {{ $item['dish_name'] }}
                            </h3>
                            
                            <div class="space-y-1">
                                <p><span class="font-medium">Precio:</span> {{ $item['price'] }}</p>
                                <p><span class="font-medium">Descripción:</span> {{ $item['description'] ?? 'Sin descripción' }}</p>
                                <p><span class="font-medium">Categoría:</span> {{ $item['category'] ?? 'Sin categoría' }}</p>
                                
                                @if(!empty($item['allergens']))
                                    <p class="text-yellow-700">
                                        <span class="font-medium">Alérgenos:</span> 
                                        {{ implode(', ', $item['allergens']) }}
                                    </p>
                                @endif
                            </div>

                            <div class="mt-2 space-y-1">
                                @if($item['is_favorite'])
                                    <p class="text-green-600 text-sm">
                                        ✨ Recomendado basado en tus preferencias
                                    </p>
                                @endif
                                @if($item['has_allergens'])
                                    <p class="text-yellow-600 text-sm">
                                        ⚠️ Contiene alérgenos a tener en cuenta
                                    </p>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</x-app-layout>