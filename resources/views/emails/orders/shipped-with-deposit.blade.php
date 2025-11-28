{{-- orders/shipped-with-deposit.blade.php --}}
@extends('emails.layout')

@section('subject', 'Order Shipped')

@section('content')
    @php
        $locale = $order->user->preferred_language ?? 'es';
        app()->setLocale($locale);
    @endphp

    <h2>
        {{ $locale === 'es' ? '¡Tu orden ha sido enviada!' : 'Your order has been shipped!' }}
    </h2>

    <p>
        {{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},
    </p>

    <p>
        @if($locale === 'es')
            Tu paquete está en camino. Aquí tienes tu número de guía para rastrearlo:
        @else
            Your package is on its way. Here is your waybill number to track it:
        @endif
    </p>

    <p style="text-align: center; font-size: 18px; margin: 20px 0;">
        {{ $order->guia_number }}
    </p>

    @if($giaUrl)
    <div style="text-align: center; margin-bottom: 20px;">
        <a href="{{ $giaUrl }}" target="_blank" style="color: #2E6BB7; text-decoration: none;">
            {{ $locale === 'es' ? '📄 Ver/Descargar Guía (PDF)' : '📄 View/Download Waybill (PDF)' }}
        </a>
    </div>
    @endif

    <div style="text-align: center; margin-bottom: 30px;">
        <a href="{{ $trackingLink }}" style="color: #2E6BB7; text-decoration: none;">
            {{ $locale === 'es' ? 'Rastrear mi Paquete →' : 'Track my Package →' }}
        </a>
    </div>

    <hr style="border: 0; border-top: 1px solid #eee; margin: 30px 0;">

    <h2>
        {{ $locale === 'es' ? 'Depósito Requerido' : 'Deposit Required' }}
    </h2>

    {{-- Show box details if available --}}
    @if(isset($boxes) && $boxes->count() > 0)
    <div style="background-color: #f9f9f9; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
        <p style="margin: 0 0 10px 0; font-weight: bold;">
            {{ $locale === 'es' ? 'Cajas en tu envío:' : 'Boxes in your shipment:' }}
        </p>
        <table style="width: 100%; border-collapse: collapse;">
            @foreach($boxes as $box)
            <tr>
                <td style="padding: 5px 0;">
                    {{ $box->quantity > 1 ? $box->quantity . 'x ' : '' }}{{ $box->box_name }}
                </td>
                <td style="padding: 5px 0; text-align: right;">
                    ${{ number_format($box->box_price * $box->quantity, 2) }} {{ strtoupper($box->currency) }}
                </td>
            </tr>
            @endforeach
            <tr style="border-top: 1px solid #ddd;">
                <td style="padding: 10px 0 5px 0; font-weight: bold;">
                    {{ $locale === 'es' ? 'Total:' : 'Total:' }}
                </td>
                <td style="padding: 10px 0 5px 0; text-align: right; font-weight: bold;">
                    ${{ number_format($totalBoxPrice, 2) }} {{ strtoupper($order->currency ?? 'mxn') }}
                </td>
            </tr>
            <tr>
                <td style="padding: 5px 0; color: #2E6BB7; font-weight: bold;">
                    {{ $locale === 'es' ? 'Depósito (50%):' : 'Deposit (50%):' }}
                </td>
                <td style="padding: 5px 0; text-align: right; color: #2E6BB7; font-weight: bold;">
                    ${{ number_format($order->deposit_amount, 2) }} {{ strtoupper($order->currency ?? 'mxn') }}
                </td>
            </tr>
        </table>
    </div>
    @endif

    <p>
        @if($locale === 'es')
            Como parte del proceso de envío, se requiere el pago del depósito del 50%. El saldo restante se cobrará al momento de la entrega.
        @else
            As part of the shipping process, a 50% deposit payment is required. The remaining balance will be charged upon delivery.
        @endif
    </p>

    <div style="text-align: center; margin: 30px 0;">
        <a href="{{ $depositLink }}" class="button">
            {{ $locale === 'es' ? 'Pagar Depósito' : 'Pay Deposit' }}
        </a>
    </div>

    <p style="font-size: 12px; color: #999; text-align: center;">
        {{ $locale === 'es' ? 'Si ya realizaste el pago, por favor ignora este mensaje.' : 'If you have already made the payment, please ignore this message.' }}
    </p>
@endsection