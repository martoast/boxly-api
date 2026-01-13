{{-- orders/all-packages-arrived.blade.php --}}
@extends('emails.layout')

@section('subject', 'All Packages Arrived')

@section('content')
    @php
        $locale = $order->user->preferred_language ?? 'es';
        app()->setLocale($locale);
    @endphp

    <h2>
        {{ $locale === 'es' ? '¡Todos tus paquetes han llegado!' : 'All your packages have arrived!' }}
    </h2>

    <p>
        {{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},
    </p>

    <p>
        @if($locale === 'es')
            ¡Excelentes noticias! Todos los artículos de tu orden <strong>{{ $order->order_number }}</strong> han llegado a nuestra bodega y están listos para ser procesados.
        @else
            Great news! All items from your order <strong>{{ $order->order_number }}</strong> have arrived at our warehouse and are ready to be processed.
        @endif
    </p>

    {{-- Arrival Image --}}
    @if($arrivalImageUrl)
    <div style="margin: 25px 0; text-align: center;">
        <p style="margin: 0 0 15px 0; font-weight: bold; color: #333;">
            {{ $locale === 'es' ? 'Foto de tus paquetes:' : 'Photo of your packages:' }}
        </p>
        <div style="border: 2px solid #e8f4f8; border-radius: 12px; overflow: hidden; display: inline-block; max-width: 100%;">
            <img src="{{ $arrivalImageUrl }}" alt="{{ $locale === 'es' ? 'Tus paquetes' : 'Your packages' }}" style="max-width: 100%; height: auto; display: block;">
        </div>
    </div>
    @endif

    {{-- Items List --}}
    <div style="background-color: #f9f9f9; border-radius: 8px; padding: 20px; margin: 20px 0;">
        <p style="margin: 0 0 15px 0; font-weight: bold;">
            {{ $locale === 'es' ? 'Artículos recibidos:' : 'Items received:' }}
        </p>
        @foreach($items as $item)
        <div style="display: flex; align-items: center; padding: 10px 0; {{ !$loop->last ? 'border-bottom: 1px solid #e5e5e5;' : '' }}">
            <span style="color: #22c55e; margin-right: 10px;">&#10003;</span>
            <span>{{ $item->product_name }}</span>
            @if($item->quantity > 1)
                <span style="color: #666; margin-left: 5px;">(x{{ $item->quantity }})</span>
            @endif
        </div>
        @endforeach
    </div>

    {{-- Next Steps --}}
    <div style="background-color: #e8f4f8; border-radius: 8px; padding: 20px; margin: 20px 0; border-left: 4px solid #2E6BB7;">
        <p style="margin: 0 0 10px 0; font-weight: bold;">
            {{ $locale === 'es' ? '¿Qué sigue?' : 'What\'s next?' }}
        </p>
        <p style="margin: 0; color: #555;">
            @if($locale === 'es')
                Nuestro equipo revisará tus artículos y te enviaremos una cotización para el envío. Te notificaremos cuando esté lista.
            @else
                Our team will review your items and send you a shipping quote. We'll notify you when it's ready.
            @endif
        </p>
    </div>

    {{-- View Order Button --}}
    <div style="text-align: center; margin: 30px 0;">
        <a href="{{ config('app.frontend_url') }}/app/orders/{{ $order->id }}" class="button">
            {{ $locale === 'es' ? 'Ver Mi Orden' : 'View My Order' }}
        </a>
    </div>

    <hr style="border: 0; border-top: 1px solid #eee; margin: 30px 0;">

    <p style="text-align: center;">
        @if($locale === 'es')
            ¿Tienes preguntas? Contáctanos en <a href="mailto:contact@boxly.mx" style="color: #2E6BB7;">contact@boxly.mx</a>
        @else
            Have questions? Contact us at <a href="mailto:contact@boxly.mx" style="color: #2E6BB7;">contact@boxly.mx</a>
        @endif
    </p>

    <p style="font-size: 12px; color: #999; text-align: center;">
        {{ $locale === 'es' ? '¡Gracias por confiar en Boxly!' : 'Thank you for trusting Boxly!' }}
    </p>
@endsection
