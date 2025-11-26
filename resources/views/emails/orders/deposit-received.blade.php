{{-- orders/deposit-received.blade.php --}}
@extends('emails.layout')

@section('subject', 'Deposit Received')

@section('content')
    @php
        $locale = $order->user->preferred_language ?? 'es';
        app()->setLocale($locale);
    @endphp

    <h2>
        {{ $locale === 'es' ? '¡Gracias por tu depósito!' : 'Thank you for your deposit!' }}
    </h2>

    <p>
        {{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},
    </p>

    <p>
        @if($locale === 'es')
            Hemos recibido tu pago de depósito por ${{ number_format($depositAmount, 2) }} {{ strtoupper($currency) }} exitosamente.
        @else
            We have successfully received your deposit payment of ${{ number_format($depositAmount, 2) }} {{ strtoupper($currency) }}.
        @endif
    </p>

    <p>
        @if($locale === 'es')
            Tu paquete está seguro y en camino. Puedes rastrear su progreso en cualquier momento usando el siguiente enlace.
        @else
            Your package is safe and on its way. You can track its progress at any time using the link below.
        @endif
    </p>

    <p>
        {{ $locale === 'es' ? 'Número de Guía:' : 'Tracking Number:' }} {{ $order->guia_number }}
    </p>

    <div style="text-align: center; margin: 30px 0;">
        <a href="{{ $trackingLink }}" class="button">
            {{ $locale === 'es' ? 'Rastrear Mi Paquete' : 'Track My Package' }}
        </a>
    </div>
    
    <p>
        @if($locale === 'es')
            El saldo restante se cobrará una vez que tu paquete haya sido entregado. Te notificaremos cuando llegue.
        @else
            The remaining balance will be charged once your package has been delivered. We will notify you upon arrival.
        @endif
    </p>
@endsection