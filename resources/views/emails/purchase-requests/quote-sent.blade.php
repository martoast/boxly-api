{{-- purchase-requests/quote-sent.blade.php --}}
@extends('emails.layout')

{{--
    The request number belongs in the SUBJECT. Without it every quote shares one
    subject line, Gmail threads them as a conversation, and then collapses the
    "repeated" tail behind a ••• — which on a phone hid the Pay button entirely.
    A unique subject keeps each quote its own message, and is easier to search.
--}}
@section('subject', ($locale === 'es' ? '💰 Tu cotización está lista — ' : '💰 Your quote is ready — ') . $request->request_number)

@section('content')
    @php
        app()->setLocale($locale);
    @endphp

    <h2>
        {{ $locale === 'es' ? '¡Tu cotización final está lista!' : 'Your final quote is ready!' }}
    </h2>

    <p>
        {{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},
    </p>

    <p>
        @if($locale === 'es')
            Hemos preparado la cotización para tu solicitud de compra asistida {{ $request->request_number }}.
        @else
            We have prepared the quote for your assisted purchase request {{ $request->request_number }}.
        @endif
    </p>

    {{--
        TOTAL + CTA FIRST. The itemized breakdown below is reassurance; paying is
        the job. Putting the button under a long product table meant a phone user
        had to scroll past everything to find it — and when Gmail collapsed the
        tail, the button disappeared completely. The action never goes below the
        fold again.
    --}}
    @if($request->payment_method === \App\Models\PurchaseRequest::PAYMENT_METHOD_STRIPE)
        <div style="margin: 30px 0; padding: 25px; background-color: #f7f9fc; border: 1px solid #e3e8ef; border-radius: 6px; text-align: center;">
            <p style="margin: 0 0 4px; color: #666; font-size: 14px;">
                {{ $locale === 'es' ? 'Total a pagar' : 'Total to pay' }}
            </p>
            <p style="margin: 0 0 20px; font-size: 32px; font-weight: bold; line-height: 1.1;">
                ${{ number_format((float) $request->total_amount, 2) }} <span style="font-size: 16px; font-weight: normal; color: #666;">USD</span>
            </p>
            <a href="{{ $request->payment_link }}" class="button" style="display: inline-block;">
                {{ $locale === 'es' ? 'Pagar Ahora' : 'Pay Now' }}
            </a>
            <p style="margin: 15px 0 0; color: #666; font-size: 13px;">
                {{ $locale === 'es' ? 'Paga con tarjeta de crédito o débito. El detalle está abajo.' : 'Pay by credit or debit card. The breakdown is below.' }}
            </p>
        </div>
    @endif

    {{--
        The customer used to get a bare total with no idea what it covered — and
        it was labelled MXN while the invoice is issued in USD. Both fixed: the
        exact products we're buying, then the arithmetic that turns them into the
        amount due. `unavailable` lines are excluded from billing, so they are
        listed separately rather than silently dropped.
    --}}
    @php
        $billable = $request->items->filter(fn ($i) => $i->stock_status !== 'unavailable' && $i->stock_status !== 'wishlist');
        $dropped  = $request->items->filter(fn ($i) => $i->stock_status === 'unavailable');
    @endphp

    <h3 style="margin: 30px 0 10px;">
        {{ $locale === 'es' ? 'Lo que vamos a comprar' : "What we'll be buying" }}
    </h3>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
        @foreach($billable as $item)
            <tr>
                <td style="padding: 8px 0; border-bottom: 1px solid #eee;">
                    {{ $item->product_name }}
                    @if($item->options)
                        @foreach((array) $item->options as $k => $v)
                            <br><span style="color: #888; font-size: 13px;">{{ $k }}: {{ $v }}</span>
                        @endforeach
                    @endif
                </td>
                <td style="padding: 8px 0; border-bottom: 1px solid #eee; text-align: right; white-space: nowrap;">
                    {{ $item->quantity }} × ${{ number_format((float) $item->price, 2) }}<br>
                    <strong>${{ number_format((float) $item->price * (int) $item->quantity, 2) }}</strong>
                </td>
            </tr>
        @endforeach
    </table>

    @if($dropped->count())
        <div style="margin: 0 0 20px; padding: 12px 15px; background-color: #fff8e1; border: 1px solid #ffe0a3;">
            <strong>{{ $locale === 'es' ? 'No disponible — no te lo cobramos' : "Unavailable — not charged" }}</strong>
            @foreach($dropped as $item)
                <br><span style="color: #7a6000;">{{ $item->product_name }}</span>
            @endforeach
        </div>
    @endif

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 25px;">
        <tr>
            <td style="padding: 5px 0;">
                {{ $locale === 'es' ? 'Compra en tiendas de EE. UU.' : 'US store purchase' }}
                <br><span style="color: #888; font-size: 12px;">{{ $locale === 'es' ? 'incluye envío e impuestos de las tiendas' : 'includes store shipping and sales tax' }}</span>
            </td>
            <td style="padding: 5px 0; text-align: right;">${{ number_format((float) $request->items_total, 2) }}</td>
        </tr>
        <tr>
            <td style="padding: 5px 0; border-top: 1px solid #ddd;">{{ $locale === 'es' ? 'Comisión Boxly' : 'Boxly commission' }}</td>
            <td style="padding: 5px 0; border-top: 1px solid #ddd; text-align: right;">${{ number_format((float) $request->processing_fee, 2) }}</td>
        </tr>
        <tr>
            <td style="padding: 10px 0; border-top: 2px solid #333; font-size: 18px;"><strong>{{ $locale === 'es' ? 'Total a Pagar' : 'Total to Pay' }}</strong></td>
            <td style="padding: 10px 0; border-top: 2px solid #333; text-align: right; font-size: 18px;"><strong>${{ number_format((float) $request->total_amount, 2) }} USD</strong></td>
        </tr>
    </table>

    <p style="color: #666; font-size: 13px; margin-top: -10px;">
        @if($locale === 'es')
            El envío de tu caja a México se cotiza por separado según el tamaño de la caja.
        @else
            Shipping your box to Mexico is quoted separately based on box size.
        @endif
    </p>

    @if($request->payment_method === \App\Models\PurchaseRequest::PAYMENT_METHOD_STRIPE)
        {{-- Secondary CTA. The primary one is above the breakdown; this one
             catches anyone who read all the way down before deciding. --}}
        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $request->payment_link }}" class="button">
                {{ $locale === 'es' ? 'Pagar Ahora' : 'Pay Now' }}
            </a>
        </div>

    @elseif($request->payment_method === \App\Models\PurchaseRequest::PAYMENT_METHOD_MANUAL_DEPOSIT)
        {{-- MANUAL DEPOSIT SECTION --}}
        <div style="margin: 30px 0; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">
            <h3 style="margin-top: 0; margin-bottom: 15px;">
                {{ $locale === 'es' ? 'Instrucciones de Transferencia Bancaria' : 'Bank Transfer Instructions' }}
            </h3>

            <p>
                {{ $locale === 'es' ? 'Por favor, realiza una transferencia bancaria a la siguiente cuenta:' : 'Please send a bank transfer to the following account:' }}
            </p>

            <div style="padding: 15px; margin: 15px 0; background-color: #f9f9f9;">
                <p style="margin: 8px 0;">
                    <strong>{{ $locale === 'es' ? 'Nombre del Beneficiario:' : 'Beneficiary Name:' }}</strong><br>
                    {{ config('payment.nu_beneficiary_name') }}
                </p>
                <p style="margin: 8px 0;">
                    <strong>{{ $locale === 'es' ? 'Nombre del Banco:' : 'Bank Name:' }}</strong><br>
                    {{ config('payment.nu_bank_name') }}
                </p>
                <p style="margin: 8px 0;">
                    <strong>{{ $locale === 'es' ? 'Número de Cuenta / CLABE:' : 'Account Number / CLABE:' }}</strong><br>
                    {{ config('payment.nu_account_number') }}
                </p>
            </div>

            <p style="margin: 15px 0; font-weight: bold;">
                {{ $locale === 'es' ? 'Total a Transferir:' : 'Total to Transfer:' }} ${{ number_format($request->total_amount, 2) }} MXN
            </p>

            <p style="color: #666; font-size: 14px; margin-top: 20px;">
                @if($locale === 'es')
                    Una vez que hayas completado la transferencia, por favor comparte una captura de pantalla o comprobante de pago a través de WhatsApp o responde a este correo. Esto nos ayuda a confirmar la recepción y procesar tu pedido rápidamente.
                @else
                    Once you have completed the transfer, please share a screenshot or proof of payment via WhatsApp or reply to this email. This helps us confirm receipt and process your order quickly.
                @endif
            </p>

            <p style="color: #666; font-size: 14px; margin-top: 30px;">
                @if($locale === 'es')
                    Si tienes alguna pregunta contáctanos en WhatsApp: +1 619 559-1920
                @else
                    If you have any questions, contact us on WhatsApp: +1 619 559-1920
                @endif
            </p>
        </div>

    @endif

    <p style="color: #666; text-align: center; margin-top: 30px;">
        @if($locale === 'es')
            Si tienes alguna pregunta sobre tu cotización, por favor contáctanos.
        @else
            If you have any questions about your quote, please contact us.
        @endif
    </p>
@endsection
