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
            Hemos recibido tu pago para la solicitud de compra asistida <strong>{{ $request->request_number }}</strong>.
        @else
            We have received your payment for assisted purchase request <strong>{{ $request->request_number }}</strong>.
        @endif
    </p>

    <!-- Item Summary -->
    <div style="margin-bottom: 20px;">
        <p style="font-weight: bold; margin-bottom: 10px;">
            {{ $locale === 'es' ? 'Artículos a Comprar:' : 'Items to Purchase:' }}
        </p>
        <ul style="list-style-type: none; padding: 0; margin: 0;">
            @foreach($request->items as $item)
                <li style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px; margin-bottom: 8px; font-size: 14px;">
                    <div style="font-weight: bold; color: #333;">{{ $item->product_name }}</div>
                    <div style="color: #666; font-size: 12px; margin-top: 4px; display: flex; justify-content: space-between;">
                        <span>{{ $locale === 'es' ? 'Cant:' : 'Qty:' }} {{ $item->quantity }}</span>
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
        <p style="font-size: 16px; font-weight: bold; margin: 0;">
            {{ $locale === 'es' ? 'Estado: Pagado' : 'Status: Paid' }}
        </p>
        <p style="margin: 5px 0;">
            {{ $locale === 'es' ? 'Total Pagado:' : 'Total Paid:' }} ${{ $request->total_amount }} USD
        </p>
    </div>

    <p>
        @if($locale === 'es')
            Nuestro equipo procederá ahora a comprar tus artículos. Te notificaremos una vez que la compra se haya completado.
        @else
            Our team will now proceed to purchase your items. We will notify you once the purchase is complete.
        @endif
    </p>

    <p style="font-size: 14px; color: #666;">
        @if($locale === 'es')
            Si tienes alguna pregunta, por favor contáctanos.
        @else
            If you have any questions, please contact us.
        @endif
    </p>
@endsection