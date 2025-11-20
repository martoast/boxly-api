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

    <div class="info-box">
        <p style="margin-bottom: 10px; font-weight: bold;">
            {{ $locale === 'es' ? 'Artículos Solicitados:' : 'Requested Items:' }}
        </p>
        
        <ul style="list-style-type: none; padding: 0; margin: 0;">
            @foreach($request->items as $item)
                <li style="background: white; border-radius: 6px; padding: 10px; margin-bottom: 8px; font-size: 14px; border: 1px solid #eee;">
                    <div style="font-weight: bold; color: #333;">{{ $item->product_name }}</div>
                    <div style="color: #666; font-size: 12px; margin-top: 4px;">
                        {{ $locale === 'es' ? 'Cantidad:' : 'Qty:' }} {{ $item->quantity }}
                        @if($item->price)
                             | {{ $locale === 'es' ? 'Precio Est.:' : 'Est. Price:' }} ${{ $item->price }}
                        @endif
                    </div>
                    @if($item->options && count($item->options) > 0)
                        <div style="color: #666; font-size: 12px; margin-top: 2px;">
                            @foreach($item->options as $key => $value)
                                <span style="background: #f0f4f8; padding: 2px 5px; border-radius: 4px; margin-right: 5px;">
                                    {{ $key }}: {{ $value }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>

    <p>
        @if($locale === 'es')
            <strong>¿Qué sigue?</strong><br>
            Te enviaremos una cotización final que incluirá los costos de envío al almacén, impuestos y nuestra tarifa de servicio. Una vez que apruebes y pagues la cotización, procederemos con la compra.
        @else
            <strong>What's next?</strong><br>
            We will send you a final quote including warehouse shipping costs, taxes, and our service fee. Once you approve and pay the quote, we will proceed with the purchase.
        @endif
    </p>

    <div style="text-align: center; margin-top: 25px;">
        <a href="{{ $url }}" class="button">
            {{ $locale === 'es' ? 'Ver Mi Solicitud' : 'View My Request' }}
        </a>
    </div>
@endsection