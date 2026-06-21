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

    <p style="margin: 18px 0 6px;">
        <strong>{{ $locale === 'es' ? 'Número de solicitud:' : 'Request number:' }}</strong>
        {{ $request->request_number }}
    </p>

    <p style="margin: 14px 0 6px;"><strong>{{ $locale === 'es' ? 'Artículos solicitados' : 'Requested items' }}</strong></p>

    @foreach($request->items as $item)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 10px 0; border: 1px solid #eee; border-radius: 8px;">
            <tr>
                @if($item->image_full_url)
                    <td width="76" valign="top" style="padding: 10px;">
                        <img src="{{ $item->image_full_url }}" width="60" height="60" alt="" style="width: 60px; height: 60px; object-fit: contain; border: 1px solid #eee; border-radius: 6px; background: #fafafa;">
                    </td>
                @endif
                <td valign="top" style="padding: 10px 12px 10px {{ $item->image_full_url ? '0' : '12px' }};">
                    <div style="font-weight: 600; color: #111;">{{ $item->product_name }}</div>
                    <div style="color: #666; font-size: 14px; margin-top: 2px;">
                        {{ $locale === 'es' ? 'Cantidad:' : 'Qty:' }} {{ $item->quantity }}
                        @if($item->price)
                            &nbsp;·&nbsp; {{ $locale === 'es' ? 'Precio est. en tienda:' : 'Est. store price:' }} ${{ $item->price }} USD
                        @endif
                    </div>
                    @if($item->notes)
                        <div style="color: #666; font-size: 14px;">{{ $item->notes }}</div>
                    @endif
                    @if($item->options && count($item->options) > 0)
                        <div style="color: #666; font-size: 14px;">
                            @foreach($item->options as $key => $value){{ $key }}: {{ $value }}@if(!$loop->last) &nbsp;·&nbsp; @endif @endforeach
                        </div>
                    @endif
                    @if($item->product_url)
                        <div style="font-size: 13px; margin-top: 3px;"><a href="{{ $item->product_url }}" style="color: #4f46e5;">{{ $locale === 'es' ? 'Ver producto' : 'View product' }}</a></div>
                    @endif
                </td>
            </tr>
        </table>
    @endforeach

    <p style="color: #666; font-size: 13px;">
        {{ $locale === 'es'
            ? 'Estos son los precios de tienda. Aún no has pagado nada — te enviaremos la cotización con la comisión de Boxly y el envío a México para que la apruebes.'
            : "These are store prices. You haven't paid anything yet — we'll send the quote with Boxly's fee and shipping to Mexico for you to approve." }}
    </p>

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