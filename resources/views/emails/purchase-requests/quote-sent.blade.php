@extends('emails.layout')

@section('subject', $locale === 'es' ? 'Cotización Lista' : 'Quote Ready')

@section('content')
    @php
        app()->setLocale($locale);
    @endphp

    <h2>{{ $locale === 'es' ? '¡Tu cotización está lista!' : 'Your quote is ready!' }}</h2>

    <p>
        {{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},
    </p>

    <p>
        @if($locale === 'es')
            Hemos revisado tu solicitud de compra asistida <strong>{{ $request->request_number }}</strong>.
            Ya hemos calculado los costos de envío al almacén, impuestos y nuestra tarifa de servicio.
        @else
            We have reviewed your assisted purchase request <strong>{{ $request->request_number }}</strong>.
            We have calculated the warehouse shipping costs, taxes, and our service fee.
        @endif
    </p>

    <!-- Item Summary -->
    <div style="margin-bottom: 20px;">
        <p style="font-weight: bold; margin-bottom: 10px;">
            {{ $locale === 'es' ? 'Resumen de Artículos:' : 'Item Summary:' }}
        </p>
        <ul style="list-style-type: none; padding: 0; margin: 0;">
            @foreach($request->items as $item)
                <li style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px; margin-bottom: 8px; font-size: 14px;">
                    <div style="font-weight: bold; color: #333;">{{ $item->product_name }}</div>
                    <div style="color: #666; font-size: 12px; margin-top: 4px; display: flex; justify-content: space-between;">
                        <span>{{ $locale === 'es' ? 'Cant:' : 'Qty:' }} {{ $item->quantity }}</span>
                        <span>${{ $item->price }}</span>
                    </div>
                    @if($item->options)
                        <div style="margin-top: 4px; font-size: 11px; color: #888;">
                            @foreach($item->options as $key => $value)
                                <span style="background: #fff; padding: 1px 4px; border: 1px solid #eee; border-radius: 3px; margin-right: 4px;">
                                    {{ $key }}: {{ $value }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>

    <div class="info-box">
        <p><strong>{{ $locale === 'es' ? 'Total a Pagar (MXN):' : 'Total to Pay (MXN):' }}</strong></p>
        <h1 style="margin-top: 0; color: #2E6BB7;">
            ${{ number_format($request->total_amount * 18.00, 2) }} MXN
        </h1>
        <p style="font-size: 12px; color: #666;">
            ({{ $locale === 'es' ? 'Aprox.' : 'Approx.' }} ${{ $request->total_amount }} USD)
        </p>
    </div>

    <p>
        @if($locale === 'es')
            Una vez que realices el pago, procederemos a comprar tus artículos inmediatamente.
        @else
            Once you make the payment, we will proceed to purchase your items immediately.
        @endif
    </p>

    <div style="text-align: center;">
        <a href="{{ $url }}" class="button">
            {{ $locale === 'es' ? 'Pagar Ahora' : 'Pay Now' }}
        </a>
    </div>

    <p style="font-size: 12px; color: #888; margin-top: 20px;">
        @if($locale === 'es')
            Nota: La factura está en Pesos Mexicanos (MXN) utilizando un tipo de cambio fijo.
        @else
            Note: The invoice is in Mexican Pesos (MXN) using a fixed exchange rate.
        @endif
    </p>
@endsection