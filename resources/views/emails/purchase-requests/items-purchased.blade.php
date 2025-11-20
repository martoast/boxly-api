@extends('emails.layout')

@section('subject', $locale === 'es' ? 'Artículos Comprados' : 'Items Purchased')

@section('content')
    @php
        app()->setLocale($locale);
    @endphp

    <h2>{{ $locale === 'es' ? '¡Hemos comprado tus artículos!' : 'We have purchased your items!' }}</h2>

    <p>
        {{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},
    </p>

    <p>
        @if($locale === 'es')
            Excelentes noticias. Hemos completado la compra de los artículos de tu solicitud <strong>{{ $request->request_number }}</strong>.
        @else
            Great news. We have completed the purchase of the items from your request <strong>{{ $request->request_number }}</strong>.
        @endif
    </p>

    <div class="info-box">
        <p style="margin-bottom: 10px;">
            @if($locale === 'es')
                Estos artículos han sido agregados a tu orden de envío <strong>#{{ $order->tracking_number }}</strong>:
            @else
                These items have been added to your shipping order <strong>#{{ $order->tracking_number }}</strong>:
            @endif
        </p>
        
        <ul style="list-style-type: none; padding: 0; margin: 0;">
            @foreach($request->items as $item)
                <li style="background: white; border-radius: 6px; padding: 10px; margin-bottom: 8px; font-size: 14px; border: 1px solid #eee;">
                    <div style="font-weight: bold; color: #333;">{{ $item->product_name }}</div>
                    <div style="color: #666; font-size: 12px; margin-top: 4px;">
                        {{ $locale === 'es' ? 'Cantidad:' : 'Qty:' }} {{ $item->quantity }} 
                        @if($item->options)
                            @foreach($item->options as $key => $value)
                                | {{ $key }}: {{ $value }}
                            @endforeach
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </div>

    <p>
        @if($locale === 'es')
            Te notificaremos nuevamente tan pronto como los paquetes lleguen a nuestro almacén en USA.
        @else
            We will notify you again as soon as the packages arrive at our US warehouse.
        @endif
    </p>

    <div style="text-align: center; margin-top: 25px;">
        <a href="{{ $url }}" class="button">
            {{ $locale === 'es' ? 'Ver Mi Orden' : 'View My Order' }}
        </a>
    </div>
@endsection