@extends('emails.layout')

@section('subject', $locale === 'es' ? 'Pago de Envío Recibido' : 'Shipping Payment Received')

@section('content')
    @php app()->setLocale($locale); @endphp

    <h2>{{ $locale === 'es' ? '¡Pago de envío recibido!' : 'Shipping payment received!' }}</h2>

    <p>{{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},</p>

    <p>
        @if($locale === 'es')
            Recibimos tu pago de envío para <strong>{{ $order->order_number }}</strong>. Tu caja
            <strong>{{ $order->box_size }}</strong> saldrá pronto a México.
        @else
            We received your shipping payment for <strong>{{ $order->order_number }}</strong>. Your
            <strong>{{ $order->box_size }}</strong> box will leave for Mexico shortly.
        @endif
    </p>

    <p style="margin-top: 30px;">
        <a href="{{ $orderUrl }}" style="background: #2E6BB7; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block;">
            {{ $locale === 'es' ? 'Ver Envío' : 'View Shipment' }}
        </a>
    </p>
@endsection
