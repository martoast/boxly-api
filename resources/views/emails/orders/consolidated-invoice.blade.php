{{-- orders/consolidated-invoice.blade.php --}}
@extends('emails.layout')

@section('subject', 'Items Consolidated - Invoice Ready')

@section('content')
    @php
        $locale = $order->user->preferred_language ?? 'es';
        app()->setLocale($locale);
    @endphp

    <h2>
        {{ $locale === 'es' ? '🎉 ¡Todos tus productos han llegado!' : '🎉 All Your Items Have Arrived!' }}
    </h2>

    <p>
        {{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},
    </p>

    <p>
        @if($locale === 'es')
            ¡Excelentes noticias! Todos los productos de tu orden han llegado a nuestro almacén y están listos para ser consolidados y enviados.
        @else
            Great news! All items from your order have arrived at our warehouse and are ready to be consolidated and shipped.
        @endif
    </p>

    {{-- Items Summary --}}
    <div style="background-color: #e8f4f8; border-radius: 8px; padding: 15px; margin: 20px 0; border-left: 4px solid #2E6BB7;">
        <p style="margin: 0 0 10px 0; font-weight: bold;">
            {{ $locale === 'es' ? '📦 Productos en tu orden:' : '📦 Items in your order:' }}
        </p>
        @foreach($items as $item)
        <div style="padding: 8px 0; {{ !$loop->last ? 'border-bottom: 1px solid #cde4ed;' : '' }}">
            <p style="margin: 0; font-weight: 500;">
                {{ $item->quantity > 1 ? $item->quantity . 'x ' : '' }}{{ $item->product_name }}
            </p>
            @if($item->retailer)
            <p style="margin: 3px 0 0 0; font-size: 13px; color: #666;">
                {{ $locale === 'es' ? 'de' : 'from' }} {{ ucfirst($item->retailer) }}
            </p>
            @endif
        </div>
        @endforeach
    </div>

    <hr style="border: 0; border-top: 1px solid #eee; margin: 30px 0;">

    <h2>
        {{ $locale === 'es' ? 'Tu Factura' : 'Your Invoice' }}
    </h2>

    <p>
        @if($isCrossingOnly)
            @if($locale === 'es')
                Hemos preparado tu paquete para cruce. A continuación se muestra el costo de tu servicio:
            @else
                We've prepared your package for crossing. Below is the cost of your service:
            @endif
        @else
            @if($locale === 'es')
                Hemos seleccionado las cajas ideales para tu envío. A continuación se muestra el desglose de costos:
            @else
                We've selected the ideal boxes for your shipment. Below is the cost breakdown:
            @endif
        @endif
    </p>

    {{-- Box Details --}}
    @if(isset($boxes) && $boxes->count() > 0)
    <div style="background-color: #f9f9f9; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
        <table style="width: 100%; border-collapse: collapse;">
            @foreach($boxes as $box)
            <tr>
                <td style="padding: 8px 0;">
                    {{ $box->quantity > 1 ? $box->quantity . 'x ' : '' }}{{ $box->box_name }}
                </td>
                <td style="padding: 8px 0; text-align: right;">
                    ${{ number_format($box->box_price * $box->quantity, 2) }} {{ strtoupper($box->currency) }}
                </td>
            </tr>
            @endforeach
            <tr style="border-top: 2px solid #2E6BB7;">
                <td style="padding: 15px 0 5px 0; font-weight: bold; font-size: 18px; color: #2E6BB7;">
                    {{ $locale === 'es' ? 'Total a Pagar:' : 'Total to Pay:' }}
                </td>
                <td style="padding: 15px 0 5px 0; text-align: right; font-weight: bold; font-size: 18px; color: #2E6BB7;">
                    ${{ number_format($totalBoxPrice, 2) }} {{ strtoupper($order->currency ?? 'mxn') }}
                </td>
            </tr>
        </table>
    </div>
    @endif

    <p style="text-align: center; margin: 10px 0 30px 0;">
        @if($locale === 'es')
            Una vez que realices el pago, procederemos a preparar y enviar tu paquete.
        @else
            Once you complete the payment, we'll proceed to prepare and ship your package.
        @endif
    </p>

    @if($paymentMethod === 'manual_transfer')
        {{-- Manual Bank Transfer Details --}}
        <div style="background-color: #fff3cd; border-radius: 8px; padding: 20px; margin: 20px 0; border-left: 4px solid #ffc107;">
            <p style="margin: 0 0 15px 0; font-weight: bold; font-size: 16px;">
                {{ $locale === 'es' ? '🏦 Datos para Transferencia Bancaria' : '🏦 Bank Transfer Details' }}
            </p>
            <table style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td style="padding: 8px 0; color: #666;">
                        {{ $locale === 'es' ? 'Beneficiario:' : 'Beneficiary:' }}
                    </td>
                    <td style="padding: 8px 0; text-align: right; font-weight: bold;">
                        {{ $bankDetails['beneficiary_name'] }}
                    </td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #666;">
                        {{ $locale === 'es' ? 'Banco:' : 'Bank:' }}
                    </td>
                    <td style="padding: 8px 0; text-align: right; font-weight: bold;">
                        {{ $bankDetails['bank_name'] }}
                    </td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; color: #666;">
                        {{ $locale === 'es' ? 'Número de Cuenta:' : 'Account Number:' }}
                    </td>
                    <td style="padding: 8px 0; text-align: right; font-weight: bold; font-family: monospace;">
                        {{ $bankDetails['account_number'] }}
                    </td>
                </tr>
                <tr style="border-top: 2px solid #ffc107;">
                    <td style="padding: 15px 0 5px 0; color: #2E6BB7; font-weight: bold;">
                        {{ $locale === 'es' ? 'Monto a Depositar:' : 'Amount to Deposit:' }}
                    </td>
                    <td style="padding: 15px 0 5px 0; text-align: right; font-weight: bold; font-size: 18px; color: #2E6BB7;">
                        ${{ number_format($totalBoxPrice, 2) }} {{ strtoupper($order->currency ?? 'mxn') }}
                    </td>
                </tr>
            </table>
        </div>

        <p style="text-align: center; font-size: 14px; color: #666; margin: 20px 0;">
            @if($locale === 'es')
                📧 Una vez realizado el depósito, envía tu comprobante a <a href="mailto:contact@boxly.mx" style="color: #2E6BB7;">contact@boxly.mx</a> o responde a este correo.
            @else
                📧 Once you've made the deposit, send your proof of payment to <a href="mailto:contact@boxly.mx" style="color: #2E6BB7;">contact@boxly.mx</a> or reply to this email.
            @endif
        </p>
    @else
        {{-- Stripe Payment Button --}}
        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $paymentLink }}" class="button" style="background-color: #2E6BB7; color: white; padding: 15px 40px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px; display: inline-block;">
                {{ $locale === 'es' ? 'Pagar Ahora' : 'Pay Now' }}
            </a>
        </div>
    @endif

    <hr style="border: 0; border-top: 1px solid #eee; margin: 30px 0;">

    <h3>{{ $locale === 'es' ? 'Próximos Pasos' : 'Next Steps' }}</h3>
    <ol>
        @if($isCrossingOnly)
            @if($paymentMethod === 'manual_transfer')
                @if($locale === 'es')
                    <li>Realiza la transferencia bancaria con los datos proporcionados</li>
                    <li>Envía tu comprobante de pago por correo o WhatsApp</li>
                    <li>Una vez confirmado el pago, te notificaremos cuando tu paquete esté listo para recoger</li>
                    <li>Ven a nuestra ubicación en Tijuana con tu identificación</li>
                @else
                    <li>Make the bank transfer using the details provided</li>
                    <li>Send your proof of payment via email or WhatsApp</li>
                    <li>Once payment is confirmed, we'll notify you when your package is ready for pickup</li>
                    <li>Come to our Tijuana location with your ID</li>
                @endif
            @else
                @if($locale === 'es')
                    <li>Realiza el pago haciendo clic en el botón de arriba</li>
                    <li>Una vez confirmado el pago, te notificaremos cuando tu paquete esté listo para recoger</li>
                    <li>Ven a nuestra ubicación en Tijuana con tu identificación</li>
                @else
                    <li>Complete the payment by clicking the button above</li>
                    <li>Once payment is confirmed, we'll notify you when your package is ready for pickup</li>
                    <li>Come to our Tijuana location with your ID</li>
                @endif
            @endif
        @else
            @if($paymentMethod === 'manual_transfer')
                @if($locale === 'es')
                    <li>Realiza la transferencia bancaria con los datos proporcionados</li>
                    <li>Envía tu comprobante de pago por correo o WhatsApp</li>
                    <li>Prepararemos tu paquete para envío</li>
                    <li>Te enviaremos la guía de rastreo una vez que sea enviado</li>
                    <li>¡Recibe tu paquete en tu domicilio!</li>
                @else
                    <li>Make the bank transfer using the details provided</li>
                    <li>Send your proof of payment via email or WhatsApp</li>
                    <li>We'll prepare your package for shipping</li>
                    <li>We'll send you the tracking number once it ships</li>
                    <li>Receive your package at your door!</li>
                @endif
            @else
                @if($locale === 'es')
                    <li>Realiza el pago haciendo clic en el botón de arriba</li>
                    <li>Prepararemos tu paquete para envío</li>
                    <li>Te enviaremos la guía de rastreo una vez que sea enviado</li>
                    <li>¡Recibe tu paquete en tu domicilio!</li>
                @else
                    <li>Complete the payment by clicking the button above</li>
                    <li>We'll prepare your package for shipping</li>
                    <li>We'll send you the tracking number once it ships</li>
                    <li>Receive your package at your door!</li>
                @endif
            @endif
        @endif
    </ol>

    <p style="font-size: 12px; color: #999; text-align: center; margin-top: 30px;">
        {{ $locale === 'es' ? '¿Tienes preguntas? Responde a este correo y te ayudaremos.' : 'Have questions? Reply to this email and we\'ll help you out.' }}
    </p>
@endsection
