@extends('emails.layout')

@section('subject', $locale === 'es' ? 'Pago de Envío Listo' : 'Shipping Invoice Ready')

@section('content')
    @php app()->setLocale($locale); @endphp

    <h2>{{ $locale === 'es' ? 'Tu envío está listo para pagar' : 'Your shipment is ready for shipping payment' }}</h2>

    <p>{{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},</p>

    <p>
        @if($locale === 'es')
            Consolidamos tu envío <strong>{{ $order->order_number }}</strong> en una caja
            <strong>{{ $order->box_size }}</strong> y la cotización del envío está lista.
        @else
            We've consolidated your shipment <strong>{{ $order->order_number }}</strong> into a
            <strong>{{ $order->box_size }}</strong> box. The shipping invoice is ready.
        @endif
    </p>

    <p style="background: #F0F7FF; padding: 16px; border-radius: 8px; border: 1px solid #D6E6F8;">
        <strong>{{ $locale === 'es' ? 'Caja' : 'Box' }} {{ $order->box_size }}</strong><br>
        <span style="font-size: 22px; font-weight: bold; color: #2E6BB7;">
            ${{ number_format($order->box_price_cents / 100, 2) }} MXN
        </span>
    </p>

    <p style="margin-top: 30px;">
        <a href="{{ $orderUrl }}" style="background: #2E6BB7; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block;">
            {{ $locale === 'es' ? 'Pagar Envío' : 'Pay Shipping' }}
        </a>
    </p>

    <p style="color: #666; font-size: 14px;">
        @if($locale === 'es')
            Tu envío saldrá a México una vez que se pague la cotización.
        @else
            Your shipment will be sent to Mexico once shipping is paid.
        @endif
    </p>
@endsection
