{{-- orders/shipped-with-deposit.blade.php --}}
@extends('emails.layout')

@section('subject', 'Order Shipped')

@section('content')
    @php
        $locale = $order->user->preferred_language ?? 'es';
        app()->setLocale($locale);
    @endphp

    @if($isCrossingOnly)
        {{-- CROSSING ORDER: Package is now in Mexico at pickup location --}}
        <h2>
            {{ $locale === 'es' ? '¡Tu paquete está en México!' : 'Your package is in Mexico!' }}
        </h2>

        <p>
            {{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},
        </p>

        <p>
            @if($locale === 'es')
                ¡Buenas noticias! Tu paquete ya cruzó la frontera y está listo para que lo recojas en nuestra ubicación en Tijuana.
            @else
                Great news! Your package has crossed the border and is ready for you to pick up at our Tijuana location.
            @endif
        </p>

        {{-- Pickup Location --}}
        <div style="background-color: #d4edda; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #28a745;">
            <p style="margin-top: 0;"><strong>{{ $locale === 'es' ? '📍 Tu paquete te espera en:' : '📍 Your package is waiting at:' }}</strong></p>
            <p style="font-size: 18px; font-weight: bold; margin: 10px 0;">
                {{ $pickupLocation['name'] }}
            </p>
            <p style="margin: 10px 0;">
                <strong>{{ $locale === 'es' ? 'Teléfono:' : 'Phone:' }}</strong>
                <a href="tel:+16195591920" style="color: #2E6BB7; text-decoration: none;">{{ $pickupLocation['phone'] }}</a>
            </p>
            <p style="margin: 10px 0;">
                <strong>{{ $locale === 'es' ? 'Horario:' : 'Hours:' }}</strong> {{ $pickupLocation['hours'] }}
            </p>
        </div>

        <div style="text-align: center; margin: 20px 0;">
            <a href="{{ $pickupLocation['mapsLink'] }}" style="background-color: #4285F4; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; font-weight: bold; display: inline-block;">
                {{ $locale === 'es' ? '📍 Ver en Google Maps' : '📍 View on Google Maps' }}
            </a>
        </div>

        <hr style="border: 0; border-top: 1px solid #eee; margin: 30px 0;">

        <h2>
            {{ $locale === 'es' ? 'Tu Cuenta' : 'Your Bill' }}
        </h2>

        {{-- Show box details if available --}}
        @if(isset($boxes) && $boxes->count() > 0)
        <div style="background-color: #f9f9f9; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
            <p style="margin: 0 0 10px 0; font-weight: bold;">
                {{ $locale === 'es' ? 'Servicio de cruce:' : 'Crossing service:' }}
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
                @if($protectedBoxCount > 0)
                <tr>
                    <td style="padding: 5px 0;">
                        🛡️ Boxly Protection
                        <span style="color: #999; font-size: 13px;">
                            ({{ $protectedBoxCount }} {{ $locale === 'es'
                                ? ($protectedBoxCount === 1 ? 'caja' : 'cajas')
                                : ($protectedBoxCount === 1 ? 'box' : 'boxes') }})
                        </span>
                    </td>
                    <td style="padding: 5px 0; text-align: right;">
                        ${{ number_format($protectionTotal, 2) }} {{ strtoupper($order->currency ?? 'mxn') }}
                    </td>
                </tr>
                @endif
                <tr style="border-top: 1px solid #ddd;">
                    <td style="padding: 10px 0 5px 0; font-weight: bold; color: #2E6BB7;">
                        {{ $locale === 'es' ? 'Total a Pagar:' : 'Total to Pay:' }}
                    </td>
                    <td style="padding: 10px 0 5px 0; text-align: right; font-weight: bold; color: #2E6BB7;">
                        ${{ number_format($order->deposit_amount, 2) }} {{ strtoupper($order->currency ?? 'usd') }}
                    </td>
                </tr>
            </table>
        </div>
        @endif

        <p>
            @if($locale === 'es')
                Puedes venir a inspeccionar tu paquete antes de pagar. Una vez que verifiques que todo está en orden, realiza el pago para llevarte tu caja.
            @else
                You can come inspect your package before paying. Once you verify everything is in order, complete the payment to take your box.
            @endif
        </p>

        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $depositLink }}" class="button">
                {{ $locale === 'es' ? 'Pagar Ahora' : 'Pay Now' }}
            </a>
        </div>

        <p style="text-align: center; font-size: 14px; color: #666;">
            @if($locale === 'es')
                También puedes pagar en persona al momento de recoger
            @else
                You can also pay in person when you pick up
            @endif
        </p>

        <h3>{{ $locale === 'es' ? 'Próximos Pasos' : 'Next Steps' }}</h3>
        <ol>
            @if($locale === 'es')
                <li>Ven a nuestra ubicación para inspeccionar tu paquete</li>
                <li>Verifica que todo esté en orden</li>
                <li>Realiza el pago (en línea o en persona)</li>
                <li>Presenta tu identificación y llévate tu caja</li>
            @else
                <li>Come to our location to inspect your package</li>
                <li>Verify everything is in order</li>
                <li>Complete the payment (online or in person)</li>
                <li>Present your ID and take your box</li>
            @endif
        </ol>

    @else
        {{-- SHIPPING ORDER: Standard flow with guia (per-box) --}}
        <h2>
            {{ $locale === 'es' ? '¡Tu orden ha sido enviada!' : 'Your order has been shipped!' }}
        </h2>

        <p>
            {{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},
        </p>

        <p>
            @if($locale === 'es')
                Tu paquete está en camino. {{ $boxes->count() > 1 ? 'Aquí tienes los números de guía para rastrear cada caja:' : 'Aquí tienes tu número de guía para rastrearlo:' }}
            @else
                Your package is on its way. {{ $boxes->count() > 1 ? 'Here are the waybill numbers to track each box:' : 'Here is your waybill number to track it:' }}
            @endif
        </p>

        {{-- Show per-box guia numbers --}}
        @if(isset($boxes) && $boxes->count() > 0)
        <div style="background-color: #e8f4f8; border-radius: 8px; padding: 15px; margin: 20px 0; border-left: 4px solid #2E6BB7;">
            @foreach($boxes as $index => $box)
            <div style="margin-bottom: {{ !$loop->last ? '15px' : '0' }}; {{ !$loop->last ? 'border-bottom: 1px solid #cde4ed; padding-bottom: 15px;' : '' }}">
                <p style="margin: 0 0 5px 0; font-weight: bold;">
                    📦 {{ $boxes->count() > 1 ? ($locale === 'es' ? 'Caja' : 'Box') . ' ' . ($index + 1) . ': ' : '' }}{{ $box->box_name }}
                </p>
                @if($box->guia_number)
                <p style="margin: 5px 0; font-size: 16px;">
                    <strong>{{ $locale === 'es' ? 'Guía:' : 'Waybill:' }}</strong>
                    <span style="font-family: monospace; background: #fff; padding: 2px 8px; border-radius: 4px;">{{ $box->guia_number }}</span>
                </p>
                @endif
                @if($box->gia_full_url)
                <p style="margin: 5px 0;">
                    <a href="{{ $box->gia_full_url }}" target="_blank" style="color: #2E6BB7; text-decoration: none; font-size: 14px;">
                        📄 {{ $locale === 'es' ? 'Ver/Descargar Guía (PDF)' : 'View/Download Waybill (PDF)' }}
                    </a>
                </p>
                @endif
            </div>
            @endforeach
        </div>
        @else
            {{-- Fallback to order-level guia for legacy orders --}}
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

        {{-- Show box details with pricing --}}
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
                @if($protectedBoxCount > 0)
                <tr>
                    <td style="padding: 5px 0;">
                        🛡️ Boxly Protection
                        <span style="color: #999; font-size: 13px;">
                            ({{ $protectedBoxCount }} {{ $locale === 'es'
                                ? ($protectedBoxCount === 1 ? 'caja' : 'cajas')
                                : ($protectedBoxCount === 1 ? 'box' : 'boxes') }})
                        </span>
                    </td>
                    <td style="padding: 5px 0; text-align: right;">
                        ${{ number_format($protectionTotal, 2) }} {{ strtoupper($order->currency ?? 'mxn') }}
                    </td>
                </tr>
                @endif
                <tr style="border-top: 1px solid #ddd;">
                    <td style="padding: 10px 0 5px 0; font-weight: bold;">
                        {{ $locale === 'es' ? 'Total:' : 'Total:' }}
                    </td>
                    <td style="padding: 10px 0 5px 0; text-align: right; font-weight: bold;">
                        ${{ number_format($orderTotal, 2) }} {{ strtoupper($order->currency ?? 'mxn') }}
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
    @endif

    <p style="font-size: 12px; color: #999; text-align: center;">
        {{ $locale === 'es' ? 'Si ya realizaste el pago, por favor ignora este mensaje.' : 'If you have already made the payment, please ignore this message.' }}
    </p>
@endsection
