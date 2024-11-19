<!-- resources/views/preferencias.blade.php -->
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Preferencias') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <form method="POST" action="{{ route('preferencias.update') }}" class="space-y-6">
                        @csrf
                        @method('PUT')
                        
                        <!-- Restricciones Dietéticas -->
                        <div>
                            <h3 class="font-bold text-lg">Restricciones Dietéticas:</h3>
                            <div class="mt-2 space-y-2">
                                @foreach(['Gluten', 'Lácteos', 'Frutos Secos', 'Pescado', 'Mariscos', 'Huevos'] as $restriction)
                                    <label class="inline-flex items-center">
                                        <input type="checkbox" 
                                               name="dietary_restrictions[]" 
                                               value="{{ $restriction }}"
                                               {{ in_array($restriction, $userPreferences['dietary_restrictions'] ?? []) ? 'checked' : '' }}
                                               class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                                        <span class="ml-2">{{ $restriction }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <!-- Etiquetas Favoritas -->
                        <div>
                            <h3 class="font-bold text-lg mt-4">Etiquetas Favoritas:</h3>
                            <div class="mt-2 space-y-2">
                                @foreach(['Entradas', 'Ensaladas', 'Carnes', 'Pescados', 'Postres'] as $tag)
                                    <label class="inline-flex items-center">
                                        <input type="checkbox" 
                                               name="favorite_tags[]" 
                                               value="{{ $tag }}"
                                               {{ in_array($tag, $userPreferences['favorite_tags'] ?? []) ? 'checked' : '' }}
                                               class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                                        <span class="ml-2">{{ $tag }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div class="flex items-center gap-4">
                            <x-primary-button>{{ __('Guardar Preferencias') }}</x-primary-button>
                        </div>
                    </form>

                    @if($userPreferences)
                        <div class="mt-6">
                            <h3 class="font-bold text-lg">Preferencias Actuales:</h3>
                            <div class="mt-4">
                                <h4 class="font-semibold">Restricciones Dietéticas:</h4>
                                <ul class="list-disc ml-5 mt-2">
                                    @forelse($userPreferences['dietary_restrictions'] as $restriction)
                                        <li>{{ $restriction }}</li>
                                    @empty
                                        <li>Sin restricciones</li>
                                    @endforelse
                                </ul>

                                <h4 class="font-semibold mt-4">Etiquetas Favoritas:</h4>
                                <ul class="list-disc ml-5 mt-2">
                                    @forelse($userPreferences['favorite_tags'] as $tag)
                                        <li>{{ $tag }}</li>
                                    @empty
                                        <li>Sin etiquetas favoritas</li>
                                    @endforelse
                                </ul>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>