{{-- orders/shipped.blade.php --}}
@extends('emails.layout')

@section('subject', 'Order Shipped')

@section('content')
    @php
        $locale = $order->user->preferred_language ?? 'es';
        app()->setLocale($locale);
    @endphp

    <h2>
        {{ $locale === 'es' ? '¡Tu orden ha sido enviada!' : 'Your order has been shipped!' }}
    </h2>

    <p>
        {{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},
    </p>

    <p>
        @if($locale === 'es')
            ¡Buenas noticias! Tu paquete está en camino. {{ $boxes->count() > 1 ? 'Aquí tienes los números de guía para rastrear cada caja:' : 'Aquí tienes tu número de guía para rastrearlo:' }}
        @else
            Great news! Your package is on its way. {{ $boxes->count() > 1 ? 'Here are your tracking numbers for each box:' : 'Here is your tracking number:' }}
        @endif
    </p>

    {{-- Tracking Numbers --}}
    @if(isset($boxes) && $boxes->count() > 0)
    <div style="background-color: #e8f4f8; border-radius: 8px; padding: 20px; margin: 20px 0; border-left: 4px solid #2E6BB7;">
        @foreach($boxes as $index => $box)
        <div style="margin-bottom: {{ !$loop->last ? '15px' : '0' }}; {{ !$loop->last ? 'border-bottom: 1px solid #cde4ed; padding-bottom: 15px;' : '' }}">
            <p style="margin: 0 0 8px 0; font-weight: bold;">
                📦 {{ $boxes->count() > 1 ? ($locale === 'es' ? 'Caja' : 'Box') . ' ' . ($index + 1) . ': ' : '' }}{{ $box->box_name }}
            </p>
            @if($box->guia_number)
            <p style="margin: 0; font-size: 18px;">
                <strong>{{ $locale === 'es' ? 'Guía:' : 'Tracking #:' }}</strong>
                <span style="font-family: monospace; background: #fff; padding: 4px 10px; border-radius: 4px; font-size: 16px;">{{ $box->guia_number }}</span>
            </p>
            @endif
        </div>
        @endforeach
    </div>
    @else
        {{-- Fallback for legacy orders --}}
        @if($order->guia_number)
        <div style="background-color: #e8f4f8; border-radius: 8px; padding: 20px; margin: 20px 0; border-left: 4px solid #2E6BB7;">
            <p style="margin: 0; font-size: 18px;">
                <strong>{{ $locale === 'es' ? 'Guía:' : 'Tracking #:' }}</strong>
                <span style="font-family: monospace; background: #fff; padding: 4px 10px; border-radius: 4px;">{{ $order->guia_number }}</span>
            </p>
        </div>
        @endif
    @endif

    {{-- Track on Estafeta --}}
    <div style="text-align: center; margin: 25px 0;">
        <a href="{{ $trackingUrl }}" target="_blank" class="button">
            {{ $locale === 'es' ? 'Rastrear en Estafeta' : 'Track on Estafeta' }}
        </a>
    </div>

    <p style="text-align: center; font-size: 14px; color: #666;">
        @if($locale === 'es')
            Ingresa tu número de guía en el sitio de Estafeta para ver el estado de tu envío.
        @else
            Enter your tracking number on the Estafeta website to see your shipment status.
        @endif
    </p>

    {{-- Estimated Delivery --}}
    @if($estimatedDelivery)
    <div style="background-color: #f9f9f9; border-radius: 8px; padding: 15px; margin: 20px 0;">
        <p style="margin: 0; text-align: center;">
            <strong>{{ $locale === 'es' ? 'Fecha Estimada de Entrega:' : 'Estimated Delivery Date:' }}</strong>
            <br>
            <span style="font-size: 18px; color: #2E6BB7;">{{ $estimatedDelivery }}</span>
        </p>
    </div>
    @endif

    {{-- GIA Files Note --}}
    @if(($boxes->count() > 0 && $boxes->first()->gia_path) || $order->gia_path)
    <p style="font-size: 14px; color: #666; text-align: center; margin-top: 20px;">
        @if($locale === 'es')
            📎 Los documentos de importación (GIA) están adjuntos a este correo.
        @else
            📎 The import documents (GIA) are attached to this email.
        @endif
    </p>
    @endif

    <hr style="border: 0; border-top: 1px solid #eee; margin: 30px 0;">

    {{-- Order Details --}}
    <div style="background-color: #f9f9f9; border-radius: 8px; padding: 15px; margin: 20px 0;">
        <p style="margin: 0 0 10px 0; font-weight: bold;">
            {{ $locale === 'es' ? 'Detalles del Pedido' : 'Order Details' }}
        </p>
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="padding: 5px 0; color: #666;">
                    {{ $locale === 'es' ? 'Número de Orden:' : 'Order Number:' }}
                </td>
                <td style="padding: 5px 0; text-align: right; font-weight: bold;">
                    {{ $order->order_number }}
                </td>
            </tr>
            <tr>
                <td style="padding: 5px 0; color: #666;">
                    {{ $locale === 'es' ? 'Cajas:' : 'Boxes:' }}
                </td>
                <td style="padding: 5px 0; text-align: right;">
                    {{ $boxes->count() }}
                </td>
            </tr>
        </table>
    </div>

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
