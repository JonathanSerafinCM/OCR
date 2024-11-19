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
                    @if($userPreferences)
                        <div>
                            <h3 class="font-bold text-lg">Restricciones Dietéticas:</h3>
                            <ul class="list-disc ml-5 mt-2">
                                @foreach($userPreferences['dietary_restrictions'] as $restriction)
                                    <li>{{ $restriction }}</li>
                                @endforeach
                            </ul>

                            <h3 class="font-bold text-lg mt-4">Etiquetas Favoritas:</h3>
                            <ul class="list-disc ml-5 mt-2">
                                @foreach($userPreferences['favorite_tags'] as $tag)
                                    <li>{{ $tag }}</li>
                                @endforeach
                            </ul>

                            <h3 class="font-bold text-lg mt-4">Historial de Pedidos:</h3>
                            <ul class="list-disc ml-5 mt-2">
                                @foreach($userPreferences['order_history'] as $order)
                                    <li>{{ $order }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @else
                        <p>No se encontraron preferencias del usuario.</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>