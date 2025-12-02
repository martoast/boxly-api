{{-- purchase-requests/quote-sent.blade.php --}}
@extends('emails.layout')

@section('subject', $locale === 'es' ? '💰 Tu cotización está lista' : '💰 Your quote is ready')

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

    <p style="font-size: 18px; margin: 25px 0;">
        {{ $locale === 'es' ? 'Total a Pagar:' : 'Total to Pay:' }} ${{ number_format($request->total_amount, 2) }} USD
    </p>

    @if($request->payment_method === \App\Models\PurchaseRequest::PAYMENT_METHOD_STRIPE)
        {{-- STRIPE PAYMENT SECTION --}}
        <div style="text-align: center; margin: 35px 0;">
            <a href="{{ $request->payment_link }}" class="button">
                {{ $locale === 'es' ? 'Pagar Ahora' : 'Pay Now' }}
            </a>
        </div>

        <p style="color: #666; text-align: center;">
            @if($locale === 'es')
                Haz clic en el botón anterior para pagar con tarjeta de crédito o débito.
            @else
                Click the button above to pay with credit or debit card.
            @endif
        </p>

    @elseif($request->payment_method === \App\Models\PurchaseRequest::PAYMENT_METHOD_MANUAL_DEPOSIT)
        {{-- MANUAL DEPOSIT SECTION --}}
        <div style="margin: 30px 0; border: 2px solid #f39c12; padding: 20px; border-radius: 8px; background-color: #fffbf0;">
            <h3 style="margin-top: 0; color: #f39c12;">
                {{ $locale === 'es' ? 'Instrucciones de Transferencia Bancaria' : 'Bank Transfer Instructions' }}
            </h3>

            <p>
                {{ $locale === 'es' ? 'Por favor, realiza una transferencia bancaria a la siguiente cuenta:' : 'Please send a bank transfer to the following account:' }}
            </p>

            <div style="background-color: white; padding: 15px; border-left: 4px solid #f39c12; margin: 15px 0;">
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
                {{ $locale === 'es' ? 'Total a Transferir:' : 'Total to Transfer:' }} ${{ number_format($request->total_amount, 2) }} USD
            </p>

            <p style="color: #666; font-size: 14px; margin-top: 20px;">
                @if($locale === 'es')
                    Una vez que hayas completado la transferencia, por favor responde a este correo con una captura de pantalla o comprobante de pago. Esto nos ayuda a confirmar la recepción y procesar tu pedido rápidamente.
                @else
                    Once you have completed the transfer, please reply to this email with a screenshot or proof of payment. This helps us confirm receipt and process your order quickly.
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
