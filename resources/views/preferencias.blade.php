<!-- resources/views/preferencias.blade.php -->
@extends('layouts.app')

@section('content')
    <h1>Tus Preferencias</h1>
    @if($userPreferences)
        <div>
            <h3>Restricciones Dietéticas:</h3>
            <ul>
                @foreach($userPreferences['dietary_restrictions'] as $restriction)
                    <li>{{ $restriction }}</li>
                @endforeach
            </ul>

            <h3>Etiquetas Favoritas:</h3>
            <ul>
                @foreach($userPreferences['favorite_tags'] as $tag)
                    <li>{{ $tag }}</li>
                @endforeach
            </ul>

            <h3>Historial de Pedidos:</h3>
            <ul>
                @foreach($userPreferences['order_history'] as $order)
                    <li>{{ $order }}</li>
                @endforeach
            </ul>
        </div>
    @else
        <p>No se encontraron preferencias del usuario.</p>
    @endif
@endsection