@extends('emails.layout')

@section('subject', $locale === 'es' ? 'Compra Recibida' : 'Purchase Received')

@section('content')
    @php app()->setLocale($locale); @endphp

    <h2>{{ $locale === 'es' ? '¡Gracias por tu compra!' : 'Thank you for your purchase!' }}</h2>

    <p>{{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},</p>

    <p>
        @if($locale === 'es')
            Recibimos tu compra <strong>{{ $order->order_number }}</strong>. Los productos se sumaron a tu envío en San Diego.
        @else
            We received your purchase <strong>{{ $order->order_number }}</strong>. The products have been added to your shipment in San Diego.
        @endif
    </p>

    <p>{{ $locale === 'es' ? 'Productos:' : 'Items:' }}</p>
    @foreach($order->items as $item)
        <p style="margin: 8px 0; padding-left: 10px; border-left: 2px solid #eee;">
            {{ $item->name_snapshot }}<br>
            <span style="color: #666; font-size: 14px;">
                {{ $locale === 'es' ? 'Cant:' : 'Qty:' }} {{ $item->quantity }}
                · ${{ number_format($item->price_cents_snapshot / 100, 2) }} MXN c/u
            </span>
        </p>
    @endforeach

    <p>
        @if($locale === 'es')
            <strong>Sigue agregando productos a tu envío</strong> para optimizar el costo de envío. Cuando estés listo, pídenos enviar tu caja.
        @else
            <strong>Keep adding products to your shipment</strong> to optimize shipping cost. When ready, request shipment of your box.
        @endif
    </p>

    <p style="margin-top: 30px;">
        <a href="{{ $orderUrl }}" style="background: #2E6BB7; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block;">
            {{ $locale === 'es' ? 'Ver Mi Envío' : 'View My Shipment' }}
        </a>
    </p>
@endsection
