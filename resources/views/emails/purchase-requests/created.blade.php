{{-- purchase-requests/created.blade.php --}}
@extends('emails.layout')

@section('subject', $locale === 'es' ? 'Solicitud Recibida' : 'Request Received')

@section('content')
    @php
        app()->setLocale($locale);
    @endphp

    <h2>{{ $locale === 'es' ? '¡Hemos recibido tu solicitud!' : 'We received your request!' }}</h2>

    <p>
        {{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},
    </p>

    <p>
        @if($locale === 'es')
            Gracias por enviarnos tu solicitud de compra asistida. Nuestro equipo revisará los enlaces y la disponibilidad de los productos.
        @else
            Thank you for sending your assisted purchase request. Our team will review the links and product availability.
        @endif
    </p>

    <p>{{ $locale === 'es' ? 'Artículos Solicitados:' : 'Requested Items:' }}</p>
    
    @foreach($request->items as $item)
        <p style="margin: 8px 0; padding-left: 10px; border-left: 2px solid #eee;">
            {{ $item->product_name }}<br>
            <span style="color: #666; font-size: 14px;">
                {{ $locale === 'es' ? 'Cantidad:' : 'Qty:' }} {{ $item->quantity }}
                @if($item->price)
                    | {{ $locale === 'es' ? 'Precio Est.:' : 'Est. Price:' }} ${{ $item->price }}
                @endif
                @if($item->options && count($item->options) > 0)
                    @foreach($item->options as $key => $value)
                        | {{ $key }}: {{ $value }}
                    @endforeach
                @endif
            </span>
        </p>
    @endforeach

    <p>
        @if($locale === 'es')
            ¿Qué sigue?
        @else
            What's next?
        @endif
    </p>
    
    <p>
        @if($locale === 'es')
            Te enviaremos una cotización final que incluirá los costos de envío al almacén, impuestos y nuestra tarifa de servicio. Una vez que apruebes y pagues la cotización, procederemos con la compra.
        @else
            We will send you a final quote including warehouse shipping costs, taxes, and our service fee. Once you approve and pay the quote, we will proceed with the purchase.
        @endif
    </p>

    <div style="text-align: center; margin: 30px 0;">
        <a href="{{ $url }}" class="button">
            {{ $locale === 'es' ? 'Ver Mi Solicitud' : 'View My Request' }}
        </a>
    </div>
@endsection