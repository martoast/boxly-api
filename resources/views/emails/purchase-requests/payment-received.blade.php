{{-- purchase-requests/payment-received.blade.php --}}
@extends('emails.layout')

@section('subject', $locale === 'es' ? 'Pago Recibido' : 'Payment Received')

@section('content')
    @php
        app()->setLocale($locale);
    @endphp

    <h2>{{ $locale === 'es' ? '¡Gracias por tu pago!' : 'Thank you for your payment!' }}</h2>

    <p>
        {{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},
    </p>

    <p>
        @if($locale === 'es')
            Hemos recibido tu pago para la solicitud de compra asistida {{ $request->request_number }}.
        @else
            We have received your payment for assisted purchase request {{ $request->request_number }}.
        @endif
    </p>

    <p>{{ $locale === 'es' ? 'Artículos a Comprar:' : 'Items to Purchase:' }}</p>
    
    @foreach($request->items as $item)
        <p style="margin: 8px 0; padding-left: 10px; border-left: 2px solid #eee;">
            {{ $item->product_name }}<br>
            <span style="color: #666; font-size: 14px;">
                {{ $locale === 'es' ? 'Cant:' : 'Qty:' }} {{ $item->quantity }}
                @if($item->options)
                    @foreach($item->options as $key => $value)
                        | {{ $key }}: {{ $value }}
                    @endforeach
                @endif
            </span>
        </p>
    @endforeach

    <p>
        {{ $locale === 'es' ? 'Estado: Pagado' : 'Status: Paid' }}<br>
        {{ $locale === 'es' ? 'Total Pagado:' : 'Total Paid:' }} ${{ $request->total_amount }} USD
    </p>

    <p>
        @if($locale === 'es')
            Nuestro equipo procederá ahora a comprar tus artículos. Te notificaremos una vez que la compra se haya completado.
        @else
            Our team will now proceed to purchase your items. We will notify you once the purchase is complete.
        @endif
    </p>

    <p style="color: #666;">
        @if($locale === 'es')
            Si tienes alguna pregunta, por favor contáctanos.
        @else
            If you have any questions, please contact us.
        @endif
    </p>
@endsection