{{-- orders/status-changed.blade.php --}}
@extends('emails.layout')
@section('subject', $subject)
@section('content')
@php
$locale = $order->user->preferred_language ?? 'es';
app()->setLocale($locale);
@endphp

<h2>
    @switch($order->status)
    @case('collecting')
    {{ $locale === 'es' ? '📦 Tu orden está lista para recibir productos' : '📦 Your order is ready for products' }}
    @break
    @case('awaiting_packages')
    {{ $locale === 'es' ? '⏳ Esperando tus paquetes' : '⏳ Awaiting your packages' }}
    @break
    @case('packages_complete')
    {{ $locale === 'es' ? '✅ Hemos recibido todos tus paquetes' : '✅ We have received all your packages' }}
    @break
    @case('processing')
    {{ $locale === 'es' ? '⚙️ Procesando tu orden' : '⚙️ Processing your order' }}
    @break
    @case('awaiting_payment')
    {{ $locale === 'es' ? '🧾 Tu factura está lista' : '🧾 Your invoice is ready' }}
    @break
    @case('paid')
    {{ $locale === 'es' ? '✅ Pago recibido' : '✅ Payment received' }}
    @break
    @case('shipped')
    @if($isCrossingOnly)
    {{ $locale === 'es' ? '📦 Tu paquete está listo para recoger' : '📦 Your package is ready for pickup' }}
    @else
    {{ $locale === 'es' ? '🛫 Tu paquete está en camino' : '🛫 Your package is on the way' }}
    @endif
    @break
    @case('delivered')
    {{ $locale === 'es' ? '🎉 Tu paquete ha sido entregado' : '🎉 Your package has been delivered' }}
    @break
    @case('cancelled')
    {{ $locale === 'es' ? '❌ Orden cancelada' : '❌ Order cancelled' }}
    @break
    @endswitch
</h2>

<p>{{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $order->user->name }},</p>

@switch($order->status)
    @case('collecting')
        @if($isCrossingOnly)
            {{-- CROSSING-ONLY ORDER --}}
            <p>
                @if($locale === 'es')
                    Tu orden de importación (cruzada) {{ $order->tracking_number }} ha sido creada exitosamente. Solo necesitamos importar tus productos a través de la frontera, sin envío a domicilio.
                @else
                    Your import order {{ $order->tracking_number }} has been created successfully. We will only import your products across the border, with no home delivery.
                @endif
            </p>

            <div style="background-color: #f5f5f5; padding: 20px; border-radius: 4px; margin: 20px 0; border-left: 4px solid #2E6BB7;">
                <p style="margin-top: 0;"><strong>{{ $locale === 'es' ? '📍 Lugar de Recogida' : '📍 Pickup Location' }}</strong></p>

                <p>
                    <strong>{{ $pickupLocation['name'] }}</strong><br>
                    {{ $pickupLocation['street'] }}<br>
                    {{ $pickupLocation['suite'] }}<br>
                    {{ $pickupLocation['city'] }}, {{ $pickupLocation['state'] }} {{ $pickupLocation['zip'] }}<br>
                    {{ $pickupLocation['country'] }}<br>
                    <strong>{{ $locale === 'es' ? 'Teléfono:' : 'Phone:' }}</strong> <a href="tel:+16195591920" style="color: #2E6BB7; text-decoration: none;">{{ $pickupLocation['phone'] }}</a>
                </p>

                <p style="margin: 10px 0;">
                    <strong>{{ $locale === 'es' ? 'Horario:' : 'Hours:' }}</strong> {{ $pickupLocation['hours'] }}<br>
                    <em>{{ $pickupLocation['instructions'] }}</em>
                </p>

                <p style="margin-bottom: 0;">
                    <a href="{{ $mapsLink }}" style="color: #2E6BB7; text-decoration: none; font-weight: 500;">
                        {{ $locale === 'es' ? '📌 Ver ubicación en Google Maps' : '📌 View location on Google Maps' }}
                    </a>
                </p>
            </div>

            <h3>{{ $locale === 'es' ? 'Próximos Pasos' : 'Next Steps' }}</h3>
            <ol>
                @if($locale === 'es')
                    <li>Agrega los productos que vas a importar a tu orden</li>
                    <li>Usa la dirección del almacén proporcionada como tu dirección de envío</li>
                    <li>IMPORTANTE: Incluye tu ID de usuario ({{ $order->user_id }}) en el nombre del destinatario</li>
                    <li>Actualiza cada producto con su número de rastreo cuando lo tengas</li>
                    <li>Una vez que todos tus paquetes lleguen, te enviaremos una cotización</li>
                    <li>Realiza el pago y recoge tu paquete consolidado en nuestro almacén</li>
                @else
                    <li>Add the products you plan to import to your order</li>
                    <li>Use the provided warehouse address as your shipping address</li>
                    <li>IMPORTANT: Include your user ID ({{ $order->user_id }}) in the recipient name</li>
                    <li>Update each product with its tracking number when available</li>
                    <li>Once all your packages arrive, we'll send you a quote</li>
                    <li>Make the payment and pick up your consolidated package at our warehouse</li>
                @endif
            </ol>
        @else
            {{-- STANDARD SHIPPING ORDER --}}
            <p>
                @if($locale === 'es')
                    Tu orden {{ $order->tracking_number }} ha sido creada exitosamente. Ahora puedes agregar productos y enviarlos a nuestro almacén en Estados Unidos.
                @else
                    Your order {{ $order->tracking_number }} has been created successfully. You can now add products and ship them to our warehouse in the United States.
                @endif
            </p>

            {{-- Warehouse Address for Receiving Items --}}
            <div style="background-color: #f5f5f5; padding: 20px; border-radius: 4px; margin: 20px 0; border-left: 4px solid #2E6BB7;">
                <p style="margin-top: 0;"><strong>{{ $locale === 'es' ? '📦 Dirección del Almacén (para enviar paquetes)' : '📦 Warehouse Address (for shipping packages)' }}</strong></p>

                <p>
                    <strong>{{ $warehouseAddress['name'] }}</strong><br>
                    {{ $warehouseAddress['street'] }}<br>
                    {{ $warehouseAddress['suite'] }}<br>
                    {{ $warehouseAddress['city'] }}, {{ $warehouseAddress['state'] }} {{ $warehouseAddress['zip'] }}<br>
                    {{ $warehouseAddress['country'] }}<br>
                    <strong>{{ $locale === 'es' ? 'ID de referencia:' : 'Reference ID:' }}</strong> {{ $warehouseAddress['reference'] }}
                </p>
            </div>

            {{-- Delivery Address --}}
            @if(!empty($deliveryAddress['full_address']))
                <div style="background-color: #f5f5f5; padding: 20px; border-radius: 4px; margin: 20px 0; border-left: 4px solid #28a745;">
                    <p style="margin-top: 0;"><strong>{{ $locale === 'es' ? '🚚 Dirección de Entrega (en México)' : '🚚 Delivery Address (in Mexico)' }}</strong></p>

                    <p style="margin-bottom: 0;">
                        {{ $deliveryAddress['full_address'] }}
                        @if($deliveryAddress['referencias'])
                            <br><em>{{ $locale === 'es' ? 'Referencias:' : 'References:' }} {{ $deliveryAddress['referencias'] }}</em>
                        @endif
                    </p>
                </div>
            @endif

            <h3>{{ $locale === 'es' ? 'Próximos Pasos' : 'Next Steps' }}</h3>
            <ol>
                @if($locale === 'es')
                    <li>Agrega los productos que vas a comprar a tu orden</li>
                    <li>Compra en tus tiendas en línea favoritas de USA</li>
                    <li>Usa la dirección del almacén proporcionada como tu dirección de envío</li>
                    <li>IMPORTANTE: Incluye tu ID de referencia en el nombre del destinatario</li>
                    <li>Actualiza cada producto con su número de rastreo cuando lo tengas</li>
                    <li>Una vez que todos tus paquetes lleguen, te enviaremos una cotización</li>
                    <li>Realiza el pago y enviaremos tu paquete consolidado a tu domicilio en México</li>
                @else
                    <li>Add the products you plan to buy to your order</li>
                    <li>Shop from your favorite US online stores</li>
                    <li>Use the provided warehouse address as your shipping address</li>
                    <li>IMPORTANT: Include your reference ID in the recipient name</li>
                    <li>Update each product with its tracking number when available</li>
                    <li>Once all your packages arrive, we'll send you a quote</li>
                    <li>Make the payment and we'll ship your consolidated package to Mexico</li>
                @endif
            </ol>
        @endif
    @break

    @case('awaiting_packages')
        <p>
            @if($locale === 'es')
                Tu orden {{ $order->tracking_number }} ha sido creada exitosamente.
            @else
                Your order {{ $order->tracking_number }} has been created successfully.
            @endif
        </p>
        <p>
            @if($locale === 'es')
                Estamos esperando que lleguen tus paquete(s) a nuestro almacén en USA.
            @else
                We're waiting for your package(s) to arrive at our USA warehouse.
            @endif
        </p>
    @break

    @case('packages_complete')
        <p>
            @if($locale === 'es')
                ¡Excelentes noticias! Hemos recibido todos los paquetes de tu orden {{ $order->tracking_number }} en nuestro almacén.
            @else
                Great news! We have received all packages for your order {{ $order->tracking_number }} at our warehouse.
            @endif
        </p>
        <p>
            @if($locale === 'es')
                Ahora nuestro equipo comenzará a procesar tu orden para cruzarla a México.
            @else
                Our team will now begin processing your order to cross to Mexico.
            @endif
        </p>
    @break

    @case('processing')
        <p>
            @if($locale === 'es')
                Tu orden {{ $order->tracking_number }} está siendo procesada por nuestro equipo.
            @else
                Your order {{ $order->tracking_number }} is being processed by our team.
            @endif
        </p>
        @if($isCrossingOnly)
            <p>
                @if($locale === 'es')
                    Estamos consolidando tus artículos y realizando el cruce a México. Te notificaremos cuando tu envío haya llegado para recoger.
                @else
                    We are consolidating your items and processing the cross-border import. We will notify you when your shipment has arrived for pickup.
                @endif
            </p>
        @else
            <p>
                @if($locale === 'es')
                    Estamos consolidando tus artículos y realizando el cruce a México. Te notificaremos cuando tu envío haya llegado y esté listo para enviarlo a tu dirección de entrega.
                @else
                    We are consolidating your items and processing the cross-border import. We will notify you when your shipment has arrived and is ready to be shipped to your delivery address.
                @endif
            </p>
        @endif
    @break

    @case('awaiting_payment')
        <p>
            @if($locale === 'es')
                Tu paquete ha sido entregado exitosamente. Hemos preparado la factura final para tu orden {{ $order->tracking_number }}.
            @else
                Your package has been successfully delivered. We have prepared the final invoice for your order {{ $order->tracking_number }}.
            @endif
        </p>
        <p>
            @if($locale === 'es')
                Saldo restante a pagar: ${{ number_format($order->quoted_amount, 2) }} MXN
            @else
                Remaining balance to pay: ${{ number_format($order->quoted_amount, 2) }} MXN
            @endif
        </p>
        @if($order->quote_expires_at)
        <p>
            @if($locale === 'es')
                ⏰ Por favor, realiza el pago antes del {{ $order->quote_expires_at->format('d/m/Y') }}.
            @else
                ⏰ Please make the payment before {{ $order->quote_expires_at->format('m/d/Y') }}.
            @endif
        </p>
        @endif

        @if($order->payment_link)
        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $order->payment_link }}" class="button">
                {{ $locale === 'es' ? 'Pagar Factura' : 'Pay Invoice' }}
            </a>
        </div>
        @endif
    @break

    @case('paid')
        {{-- Legacy status - new orders use DELIVERED as final status --}}
        <p>
            @if($locale === 'es')
                Tu orden {{ $order->tracking_number }} está completa.
            @else
                Your order {{ $order->tracking_number }} is complete.
            @endif
        </p>
        <p>
            @if($locale === 'es')
                ¡Gracias por confiar en Boxly! Esperamos verte pronto.
            @else
                Thank you for trusting Boxly! We hope to see you again soon.
            @endif
        </p>
    @break

    @case('shipped')
        @if($isCrossingOnly)
            {{-- CROSSING-ONLY READY FOR PICKUP (requires full payment first) --}}
            <p>
                @if($locale === 'es')
                    ¡Tu paquete ha llegado a nuestro almacén! 🎉
                @else
                    Your package has arrived at our warehouse! 🎉
                @endif
            </p>

            {{-- Payment Section --}}
            @if($order->deposit_amount)
            <div style="background-color: #fff3cd; padding: 20px; border-radius: 4px; margin: 20px 0; border-left: 4px solid #ffc107;">
                <p style="margin-top: 0;"><strong>{{ $locale === 'es' ? '💰 Pago Requerido para Recoger' : '💰 Payment Required for Pickup' }}</strong></p>
                <p style="font-size: 24px; font-weight: bold; color: #333; margin: 10px 0;">
                    ${{ number_format($order->deposit_amount, 2) }} {{ strtoupper($order->currency ?? 'MXN') }}
                </p>
                <p style="margin-bottom: 0;">
                    @if($locale === 'es')
                        Para poder recoger tu paquete, primero debes completar el pago.
                    @else
                        To pick up your package, you must first complete the payment.
                    @endif
                </p>
            </div>

            @if($order->deposit_payment_link)
            <div style="text-align: center; margin: 20px 0;">
                <a href="{{ $order->deposit_payment_link }}" style="background-color: #2E6BB7; color: white; padding: 15px 30px; text-decoration: none; border-radius: 4px; font-weight: bold; display: inline-block;">
                    {{ $locale === 'es' ? 'Pagar Ahora' : 'Pay Now' }}
                </a>
            </div>
            @endif
            @endif

            {{-- Pickup Location --}}
            <div style="background-color: #f5f5f5; padding: 20px; border-radius: 4px; margin: 20px 0; border-left: 4px solid #2E6BB7;">
                <p style="margin-top: 0;"><strong>{{ $locale === 'es' ? '📍 Lugar de Recogida' : '📍 Pickup Location' }}</strong></p>

                <p>
                    <strong>{{ $pickupLocation['name'] }}</strong><br>
                    {{ $pickupLocation['street'] }}<br>
                    {{ $pickupLocation['suite'] }}<br>
                    {{ $pickupLocation['city'] }}, {{ $pickupLocation['state'] }} {{ $pickupLocation['zip'] }}<br>
                    {{ $pickupLocation['country'] }}<br>
                    <strong>{{ $locale === 'es' ? 'Teléfono:' : 'Phone:' }}</strong> <a href="tel:+16195591920" style="color: #2E6BB7; text-decoration: none;">{{ $pickupLocation['phone'] }}</a>
                </p>

                <p style="margin: 10px 0;">
                    <strong>{{ $locale === 'es' ? 'Horario:' : 'Hours:' }}</strong> {{ $pickupLocation['hours'] }}<br>
                    <em>{{ $pickupLocation['instructions'] }}</em>
                </p>
            </div>

            <h3>{{ $locale === 'es' ? 'Próximos Pasos' : 'Next Steps' }}</h3>
            <ol>
                @if($locale === 'es')
                    <li>Realiza el pago usando el enlace de arriba</li>
                    <li>Una vez confirmado el pago, contacta con nosotros para coordinar tu recogida</li>
                    <li>Presenta tu identificación al momento de recoger</li>
                @else
                    <li>Complete the payment using the link above</li>
                    <li>Once payment is confirmed, contact us to schedule your pickup</li>
                    <li>Present your ID when picking up</li>
                @endif
            </ol>
        @else
            {{-- SHIPPING ORDER SHIPPED --}}
            <p>
                @if($locale === 'es')
                    ¡Tu orden {{ $order->tracking_number }} ha sido enviada! 🚚
                @else
                    Your order {{ $order->tracking_number }} has been shipped! 🚚
                @endif
            </p>
            @if($order->guia_number)
            <div style="background-color: #e8f4f8; border-radius: 8px; padding: 15px; margin: 20px 0; border-left: 4px solid #2E6BB7;">
                <p style="margin: 0;">
                    @if($locale === 'es')
                        <strong>Número de guía:</strong> <span style="font-family: monospace;">{{ $order->guia_number }}</span>
                        @if($order->estimated_delivery_date)
                            <br><strong>Fecha estimada de entrega:</strong> {{ $order->estimated_delivery_date->format('d/m/Y') }}
                        @endif
                    @else
                        <strong>Tracking number:</strong> <span style="font-family: monospace;">{{ $order->guia_number }}</span>
                        @if($order->estimated_delivery_date)
                            <br><strong>Estimated delivery:</strong> {{ $order->estimated_delivery_date->format('m/d/Y') }}
                        @endif
                    @endif
                </p>
            </div>
            <div style="text-align: center; margin: 20px 0;">
                <a href="https://www.estafeta.com/rastrear-envio" target="_blank" style="background-color: #2E6BB7; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; font-weight: bold; display: inline-block;">
                    @if($locale === 'es')
                        Rastrear en Estafeta
                    @else
                        Track on Estafeta
                    @endif
                </a>
            </div>
            @endif
            <p>
                @if($locale === 'es')
                    Te notificaremos cuando tu paquete haya sido entregado.
                @else
                    We'll notify you when your package has been delivered.
                @endif
            </p>
        @endif
    @break

    @case('delivered')
        <p>
            @if($locale === 'es')
                ¡Tu paquete ha sido entregado exitosamente! 🎉
            @else
                Your package has been successfully delivered! 🎉
            @endif
        </p>
        <p>
            @if($locale === 'es')
                Tu orden {{ $order->tracking_number }} ha sido entregada en la dirección registrada.
            @else
                Your order {{ $order->tracking_number }} has been delivered to the registered address.
            @endif
        </p>
        <p>
            @if($locale === 'es')
                ¡Gracias por confiar en Boxly! Esperamos que hayas tenido una excelente experiencia.
            @else
                Thank you for trusting Boxly! We hope you had a great experience.
            @endif
        </p>

        {{-- Trustpilot Review Request --}}
        <div style="background-color: #f0f9ff; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #00b67a; text-align: center;">
            <p style="margin: 0 0 15px 0;">
                @if($locale === 'es')
                    ⭐ ¿Te gustó nuestro servicio? ¡Tu opinión nos ayuda a mejorar!
                @else
                    ⭐ Did you enjoy our service? Your feedback helps us improve!
                @endif
            </p>
            <a href="https://www.trustpilot.com/evaluate/boxly.mx" style="background-color: #00b67a; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; font-weight: bold; display: inline-block;">
                @if($locale === 'es')
                    Dejar una Reseña
                @else
                    Leave a Review
                @endif
            </a>
        </div>
    @break

    @case('cancelled')
        <p>
            @if($locale === 'es')
                Tu orden {{ $order->tracking_number }} ha sido cancelada.
            @else
                Your order {{ $order->tracking_number }} has been cancelled.
            @endif
        </p>
        @if($order->notes)
        <p>
            @if($locale === 'es')
                Razón: {{ $order->notes }}
            @else
                Reason: {{ $order->notes }}
            @endif
        </p>
        @endif
        <p>
            @if($locale === 'es')
                Si tienes alguna pregunta, por favor contáctanos.
            @else
                If you have any questions, please contact us.
            @endif
        </p>
    @break
@endswitch

@if($order->status !== 'awaiting_payment' && $order->status !== 'cancelled')
<div style="text-align: center; margin: 30px 0;">
    <a href="{{ config('app.frontend_url') }}/app/orders/{{ $order->id }}" class="button">
        {{ $locale === 'es' ? 'Ver Detalles de la Orden' : 'View Order Details' }}
    </a>
</div>
@endif

<p style="color: #666; font-size: 14px; margin-top: 30px;">
    @if($locale === 'es')
        Si tienes alguna pregunta contáctanos en WhatsApp: +1 619 559-1920
    @else
        If you have any questions, contact us on WhatsApp: +1 619 559-1920
    @endif
</p>
@endsection