{{-- orders/ship-date-changed.blade.php --}}
@extends('emails.layout')

@section('subject', 'Ship date updated')

@section('content')
    @php
        $locale = $order->user->preferred_language ?? 'es';
        app()->setLocale($locale);
    @endphp

    <h2>
        {{ $locale === 'es' ? 'Tu fecha de envío cambió' : 'Your ship date changed' }}
    </h2>

    <p>
        {{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},
    </p>

    <p>
        @if($locale === 'es')
            Te avisamos que la fecha estimada de envío de tu orden <strong>{{ $order->order_number }}</strong> se actualizó.
        @else
            We're letting you know the estimated ship date for your order <strong>{{ $order->order_number }}</strong> has been updated.
        @endif
    </p>

    {{-- New ship date --}}
    <div style="background-color: #e8f4f8; border-radius: 8px; padding: 22px; margin: 24px 0; border-left: 4px solid #2E6BB7; text-align: center;">
        <p style="margin: 0 0 6px 0; color: #5a6474; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">
            {{ $locale === 'es' ? 'Nueva fecha estimada de envío' : 'New estimated ship date' }}
        </p>
        <p style="margin: 0; font-size: 22px; font-weight: bold; color: #0d2c4f;">
            {{ $shipLabel }}
        </p>
    </div>

    <p style="color: #555;">
        @if($locale === 'es')
            Te seguiremos avisando conforme tu paquete avance. Si tienes alguna pregunta, aquí estamos.
        @else
            We'll keep you posted as your package moves along. If you have any questions, we're here.
        @endif
    </p>

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
