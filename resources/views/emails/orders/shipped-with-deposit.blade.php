@extends('emails.layout')

@section('subject', 'Order Shipped')

@section('content')
    @php
        $locale = $order->user->preferred_language ?? 'es';
        app()->setLocale($locale);
    @endphp

    <h2 style="color: #333; font-size: 24px; margin-bottom: 20px;">
        {{ $locale === 'es' ? '¡Tu orden ha sido enviada!' : 'Your order has been shipped!' }}
    </h2>

    <p>
        {{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},
    </p>

    <p>
        @if($locale === 'es')
            Tu paquete está en camino. Aquí tienes tu número de guía para rastrearlo:
        @else
            Your package is on its way. Here is your waybill number to track it:
        @endif
    </p>

    <div class="info-box" style="text-align: center; font-size: 18px; font-weight: bold; background-color: #f8f9fa; padding: 15px; border: 1px solid #e9ecef; border-radius: 6px; margin: 20px 0;">
        {{ $order->guia_number }}
    </div>

    {{-- NEW GIA BUTTON SECTION --}}
    @if($giaUrl)
    <div style="text-align: center; margin-bottom: 20px;">
        <a href="{{ $giaUrl }}" target="_blank" style="background-color: #6c757d; color: white; padding: 10px 20px; text-decoration: none; border-radius: 4px; font-weight: bold; font-size: 14px;">
            {{ $locale === 'es' ? '📄 Ver/Descargar Guía (PDF)' : '📄 View/Download Waybill (PDF)' }}
        </a>
    </div>
    @endif

    <div style="text-align: center; margin-bottom: 30px;">
        <a href="{{ $trackingLink }}" style="color: #2E6BB7; text-decoration: none; font-weight: 500;">
            {{ $locale === 'es' ? 'Rastrear mi Paquete' : 'Track my Package' }} &rarr;
        </a>
    </div>

    <hr style="border: 0; border-top: 1px solid #eee; margin: 30px 0;">

    <h3 style="color: #333; font-size: 18px; margin-bottom: 15px;">
        {{ $locale === 'es' ? 'Depósito Requerido' : 'Deposit Required' }}
    </h3>

    <p>
        @if($locale === 'es')
            Como parte del proceso de envío, se requiere el pago del depósito del 50%.
            El saldo restante se cobrará al momento de la entrega.
        @else
            As part of the shipping process, a 50% deposit payment is required.
            The remaining balance will be charged upon delivery.
        @endif
    </p>

    <div style="text-align: center; margin-top: 30px;">
        <a href="{{ $depositLink }}" class="button" style="background-color: #2E6BB7; color: white; padding: 12px 30px; text-decoration: none; border-radius: 4px; font-weight: bold;">
            {{ $locale === 'es' ? 'Pagar Depósito' : 'Pay Deposit' }}
        </a>
    </div>
    
    <p style="font-size: 12px; color: #999; margin-top: 40px; text-align: center;">
        {{ $locale === 'es' ? 'Si ya realizaste el pago, por favor ignora este mensaje.' : 'If you have already made the payment, please ignore this message.' }}
    </p>
@endsection