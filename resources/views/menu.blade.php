<!-- resources/views/menu.blade.php -->
@extends('layouts.app')

@section('content')
    <h1>Menú</h1>
    @foreach($menuItems as $item)
        <div>
            <h2>{{ $item->dish_name }}</h2>
            <p>Precio: {{ $item->price }}</p>
            <p>Descripción: {{ $item->description ?? 'Sin descripción' }}</p>
            <p>Categoría: {{ $item->category ?? 'Sin categoría' }}</p>
            <p>Alérgenos: {{ implode(', ', $item->allergens ?? []) }}</p>
        </div>
        <hr>
    @endforeach
@endsection