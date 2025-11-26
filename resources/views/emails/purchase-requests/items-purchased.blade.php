{{-- purchase-requests/items-purchased.blade.php --}}
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
            Excelentes noticias. Hemos completado la compra de los artículos de tu solicitud {{ $request->request_number }}.
        @else
            Great news. We have completed the purchase of the items from your request {{ $request->request_number }}.
        @endif
    </p>

    <p>
        @if($locale === 'es')
            Estos artículos han sido agregados a tu orden de envío #{{ $order->tracking_number }}:
        @else
            These items have been added to your shipping order #{{ $order->tracking_number }}:
        @endif
    </p>
    
    @foreach($request->items as $item)
        <p style="margin: 8px 0; padding-left: 10px; border-left: 2px solid #eee;">
            {{ $item->product_name }}<br>
            <span style="color: #666; font-size: 14px;">
                {{ $locale === 'es' ? 'Cantidad:' : 'Qty:' }} {{ $item->quantity }}
                @if($item->options)
                    @foreach($item->options as $key => $value)
                        | {{ $key }}: {{ $value }}
                    @endforeach
                @endif
            </span>
        </p>
    @endforeach

    <p>
        @if($locale === 'es')
            Te notificaremos nuevamente tan pronto como los paquetes lleguen a nuestro almacén en USA.
        @else
            We will notify you again as soon as the packages arrive at our US warehouse.
        @endif
    </p>

    <div style="text-align: center; margin: 30px 0;">
        <a href="{{ $url }}" class="button">
            {{ $locale === 'es' ? 'Ver Mi Orden' : 'View My Order' }}
        </a>
    </div>
@endsection