{{-- orders/payment-received.blade.php --}}
@extends('emails.layout')

@section('subject', 'Payment Received')

@section('content')
    @php
        $locale = $order->user->preferred_language ?? 'es';
        app()->setLocale($locale);
    @endphp

    <h2>
        {{ $locale === 'es' ? '¡Pago Recibido Exitosamente!' : 'Payment Received Successfully!' }}
    </h2>

    <p>
        {{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $user->name }},
    </p>

    <p>
        @if($locale === 'es')
            Hemos recibido tu pago de <strong>${{ number_format($amountPaid, 2) }} {{ $currency }}</strong> exitosamente.
        @else
            We have successfully received your payment of <strong>${{ number_format($amountPaid, 2) }} {{ $currency }}</strong>.
        @endif
    </p>

    {{-- Order Details --}}
    <div style="background-color: #f9f9f9; border-radius: 8px; padding: 15px; margin: 20px 0;">
        <p style="margin: 0 0 10px 0; font-weight: bold;">
            {{ $locale === 'es' ? 'Detalles del Pedido' : 'Order Details' }}
        </p>
        <table style="width: 100%; border-collapse: collapse;">
            <tr>
                <td style="padding: 5px 0; color: #666;">
                    {{ $locale === 'es' ? 'Número de Orden:' : 'Order Number:' }}
                </td>
                <td style="padding: 5px 0; text-align: right; font-weight: bold;">
                    {{ $order->order_number }}
                </td>
            </tr>
            <tr>
                <td style="padding: 5px 0; color: #666;">
                    {{ $locale === 'es' ? 'Fecha de Pago:' : 'Payment Date:' }}
                </td>
                <td style="padding: 5px 0; text-align: right;">
                    {{ $paidAt->format($locale === 'es' ? 'd/m/Y H:i' : 'm/d/Y H:i') }}
                </td>
            </tr>
            <tr>
                <td style="padding: 5px 0; color: #666;">
                    {{ $locale === 'es' ? 'Artículos:' : 'Items:' }}
                </td>
                <td style="padding: 5px 0; text-align: right;">
                    {{ $itemCount }}
                </td>
            </tr>
            @if($totalWeight)
            <tr>
                <td style="padding: 5px 0; color: #666;">
                    {{ $locale === 'es' ? 'Peso Total:' : 'Total Weight:' }}
                </td>
                <td style="padding: 5px 0; text-align: right;">
                    {{ number_format($totalWeight, 2) }} lbs
                </td>
            </tr>
            @endif
            <tr style="border-top: 1px solid #ddd;">
                <td style="padding: 10px 0 5px 0; font-weight: bold; color: #2E6BB7;">
                    {{ $locale === 'es' ? 'Total Pagado:' : 'Total Paid:' }}
                </td>
                <td style="padding: 10px 0 5px 0; text-align: right; font-weight: bold; color: #2E6BB7;">
                    ${{ number_format($amountPaid, 2) }} {{ $currency }}
                </td>
            </tr>
        </table>
    </div>

    @if($isConsolidationPayment ?? false)
        {{-- CONSOLIDATION PAYMENT: Processing begins --}}
        <div style="background-color: #d4edda; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #28a745;">
            <p style="margin: 0; font-size: 16px;">
                @if($locale === 'es')
                    🚀 <strong>¡Tu orden ahora está siendo procesada!</strong> Prepararemos tu paquete y te enviaremos la información de rastreo una vez que sea enviado.
                @else
                    🚀 <strong>Your order is now being processed!</strong> We'll prepare your package and send you tracking information once it ships.
                @endif
            </p>
        </div>

        <h3>{{ $locale === 'es' ? 'Próximos Pasos' : 'Next Steps' }}</h3>
        <ol>
            @if($locale === 'es')
                <li>Estamos preparando tu paquete para envío</li>
                <li>Recibirás un correo con tu número de guía cuando sea enviado</li>
                <li>Podrás rastrear tu paquete hasta que llegue a tu puerta</li>
            @else
                <li>We're preparing your package for shipping</li>
                <li>You'll receive an email with your tracking number when it ships</li>
                <li>You'll be able to track your package until it arrives at your door</li>
            @endif
        </ol>

    @elseif($isCrossingOnly)
        {{-- CROSSING ORDER: Pickup Instructions --}}
        <h3>{{ $locale === 'es' ? 'Información de Recogida' : 'Pickup Information' }}</h3>

        <p>
            @if($locale === 'es')
                Tu pago ha sido confirmado. Ahora puedes coordinar la recogida de tu paquete en nuestra ubicación.
            @else
                Your payment has been confirmed. You can now coordinate the pickup of your package at our location.
            @endif
        </p>

        <div style="background-color: #f5f5f5; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #2E6BB7;">
            <p style="margin-top: 0;"><strong>{{ $locale === 'es' ? '📍 Lugar de Recogida' : '📍 Pickup Location' }}</strong></p>
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

        <h3>{{ $locale === 'es' ? 'Próximos Pasos' : 'Next Steps' }}</h3>
        <ol>
            @if($locale === 'es')
                <li>Contacta con nosotros para coordinar la fecha y hora de tu recogida</li>
                <li>Presenta tu identificación al momento de recoger</li>
                <li>¡Listo! Tu paquete estará esperándote</li>
            @else
                <li>Contact us to coordinate your pickup date and time</li>
                <li>Present your ID when picking up</li>
                <li>That's it! Your package will be waiting for you</li>
            @endif
        </ol>

    @else
        {{-- SHIPPING ORDER: Delivery Information (for legacy final payment) --}}
        <h3>{{ $locale === 'es' ? 'Información de Entrega' : 'Delivery Information' }}</h3>

        @if($order->guia_number)
        <p>
            <strong>{{ $locale === 'es' ? 'Número de Guía:' : 'Tracking Number:' }}</strong> {{ $order->guia_number }}
        </p>
        @endif

        @if(!empty($deliveryAddress['full_address']))
        <div style="background-color: #f5f5f5; padding: 15px; border-radius: 8px; margin: 20px 0;">
            <p style="margin: 0;"><strong>{{ $locale === 'es' ? '📦 Dirección de Entrega:' : '📦 Delivery Address:' }}</strong></p>
            <p style="margin: 10px 0 0 0;">{{ $deliveryAddress['full_address'] }}</p>
        </div>
        @endif

        <p>
            <strong>{{ $locale === 'es' ? 'Entrega Estimada:' : 'Estimated Delivery:' }}</strong>
            {{ $estimatedDelivery['formatted_range'] }} ({{ $estimatedDelivery['range_text'] }})
        </p>

        <p>
            @if($locale === 'es')
                Tu paquete está en camino. Te notificaremos cuando esté por llegar.
            @else
                Your package is on its way. We will notify you when it's about to arrive.
            @endif
        </p>
    @endif

    <hr style="border: 0; border-top: 1px solid #eee; margin: 30px 0;">

    <p style="text-align: center;">
        @if($locale === 'es')
            ¿Tienes preguntas? Contáctanos en <a href="mailto:{{ $supportEmail }}" style="color: #2E6BB7;">{{ $supportEmail }}</a>
        @else
            Have questions? Contact us at <a href="mailto:{{ $supportEmail }}" style="color: #2E6BB7;">{{ $supportEmail }}</a>
        @endif
    </p>

    <div style="text-align: center; margin: 30px 0;">
        <a href="{{ $frontendUrl }}/orders/{{ $order->id }}" class="button">
            {{ $locale === 'es' ? 'Ver Mi Pedido' : 'View My Order' }}
        </a>
    </div>

    <p style="font-size: 12px; color: #999; text-align: center;">
        {{ $locale === 'es' ? '¡Gracias por confiar en Boxly!' : 'Thank you for trusting Boxly!' }}
    </p>
@endsection
