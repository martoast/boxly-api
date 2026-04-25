@extends('emails.layout')

@section('subject', $locale === 'es' ? 'Tu envío está en camino' : 'Your shipment is on the way')

@section('content')
    @php app()->setLocale($locale); @endphp

    <h2>{{ $locale === 'es' ? '🚚 Tu envío salió hacia México' : '🚚 Your shipment is on the way to Mexico' }}</h2>

    <p>{{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},</p>

    <p>
        @if($locale === 'es')
            Tu pedido <strong>{{ $order->order_number }}</strong> ya está en tránsito.
        @else
            Your order <strong>{{ $order->order_number }}</strong> is now in transit.
        @endif
    </p>

    @if($order->guia_number)
        <p style="background: #F8F9FA; padding: 16px; border-radius: 8px;">
            <strong>{{ $locale === 'es' ? 'Guía' : 'Tracking' }}:</strong> {{ $order->guia_number }}
        </p>
    @endif

    @if($order->estimated_delivery_date)
        <p>
            <strong>{{ $locale === 'es' ? 'Entrega estimada' : 'Estimated delivery' }}:</strong>
            {{ $order->estimated_delivery_date->format('d/m/Y') }}
        </p>
    @endif

    <p style="margin-top: 30px;">
        <a href="{{ $orderUrl }}" style="background: #2E6BB7; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block;">
            {{ $locale === 'es' ? 'Rastrear Envío' : 'Track Shipment' }}
        </a>
    </p>
@endsection
