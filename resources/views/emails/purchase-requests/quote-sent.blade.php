<!-- resources/views/emails/orders/quote-sent.blade.php -->
@extends('emails.layout')

@section('subject', $locale === 'es' ? '💰 Tu cotización está lista' : '💰 Your quote is ready')

@section('content')
    @php
        app()->setLocale($locale);
    @endphp

    <h2 style="color: #333; font-size: 24px; margin-bottom: 20px;">
        {{ $locale === 'es' ? '¡Tu cotización final está lista!' : 'Your final quote is ready!' }}
    </h2>

    <p>
        {{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},
    </p>

    <p>
        @if($locale === 'es')
            Tu paquete ha sido entregado y procesado. Hemos generado tu factura final.
        @else
            Your package has been delivered and processed. We have generated your final invoice.
        @endif
    </p>

    <div class="info-box" style="background-color: #f8f9fa; padding: 20px; border-left: 4px solid #2E6BB7; margin: 25px 0;">
        <p style="margin: 0 0 10px 0; font-size: 14px; color: #666;">
            {{ $locale === 'es' ? 'Total a Pagar:' : 'Total to Pay:' }}
        </p>
        <p style="margin: 0; font-size: 28px; font-weight: bold; color: #2E6BB7;">
            ${{ number_format($quotedAmount, 2) }} MXN
        </p>
        @if($quoteExpiresAt)
            <p style="margin: 10px 0 0 0; font-size: 12px; color: #888;">
                {{ $locale === 'es' ? 'Vence el:' : 'Due by:' }} {{ $quoteExpiresAt->format('d/m/Y') }}
            </p>
        @endif
    </div>

    <!-- Breakdown -->
    @if($quoteBreakdown)
        <div style="margin-bottom: 25px; border: 1px solid #eee; border-radius: 8px; padding: 15px;">
            <p style="font-weight: bold; margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 5px;">
                {{ $locale === 'es' ? 'Desglose:' : 'Breakdown:' }}
            </p>
            <table style="width: 100%; font-size: 14px;">
                @foreach($quoteBreakdown as $item)
                    <tr>
                        <td style="padding: 5px 0;">{{ $item['item'] ?? 'Item' }}</td>
                        <td style="text-align: right; font-weight: 500;">${{ number_format($item['amount'] ?? 0, 2) }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif

    <div style="text-align: center; margin: 35px 0;">
        <a href="{{ $paymentLink }}" class="button" style="background-color: #28a745; color: white; padding: 14px 35px; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 16px; display: inline-block;">
            {{ $locale === 'es' ? 'Pagar Ahora' : 'Pay Now' }}
        </a>
    </div>

    <p style="font-size: 14px; color: #666; text-align: center;">
        @if($locale === 'es')
            Si tienes alguna pregunta sobre tu factura, por favor contáctanos.
        @else
            If you have any questions about your invoice, please contact us.
        @endif
    </p>
@endsection