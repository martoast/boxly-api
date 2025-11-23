@extends('emails.layout')

@section('subject', 'Deposit Received')

@section('content')
    @php
        $locale = $order->user->preferred_language ?? 'es';
        app()->setLocale($locale);
    @endphp

    <h2 style="color: #333; font-size: 24px; margin-bottom: 20px;">
        {{ $locale === 'es' ? '¡Gracias por tu depósito!' : 'Thank you for your deposit!' }}
    </h2>

    <p>
        {{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},
    </p>

    <p>
        @if($locale === 'es')
            Hemos recibido tu pago de depósito por <strong>${{ number_format($depositAmount, 2) }} {{ strtoupper($currency) }}</strong> exitosamente.
        @else
            We have successfully received your deposit payment of <strong>${{ number_format($depositAmount, 2) }} {{ strtoupper($currency) }}</strong>.
        @endif
    </p>

    <p>
        @if($locale === 'es')
            Tu paquete está seguro y en camino. Puedes rastrear su progreso en cualquier momento usando el siguiente enlace:
        @else
            Your package is safe and on its way. You can track its progress at any time using the link below:
        @endif
    </p>

    <div style="text-align: center; margin: 30px 0;">
        <a href="{{ $trackingLink }}" class="button" style="background-color: #2E6BB7; color: white; padding: 12px 30px; text-decoration: none; border-radius: 4px; font-weight: bold;">
            {{ $locale === 'es' ? 'Rastrear Mi Paquete' : 'Track My Package' }}
        </a>
    </div>

    <div class="info-box" style="background-color: #f8f9fa; padding: 15px; border: 1px solid #e9ecef; border-radius: 6px; margin-bottom: 20px;">
        <p style="margin: 0; font-size: 14px; color: #555;">
            <strong>{{ $locale === 'es' ? 'Número de Guía:' : 'Tracking Number:' }}</strong> {{ $order->guia_number }}
        </p>
    </div>
    
    <p style="font-size: 14px; color: #666;">
        @if($locale === 'es')
            El saldo restante se cobrará una vez que tu paquete haya sido entregado. Te notificaremos cuando llegue.
        @else
            The remaining balance will be charged once your package has been delivered. We will notify you upon arrival.
        @endif
    </p>
@endsection