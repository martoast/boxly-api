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
    {{ $locale === 'es' ? '🛫 Tu paquete está en camino' : '🛫 Your package is on the way' }}
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
        <p>
            @if($locale === 'es')
                Tu orden {{ $order->tracking_number }} ha sido reabierta y está lista para agregar más productos.
            @else
                Your order {{ $order->tracking_number }} has been reopened and is ready to add more products.
            @endif
        </p>
        <p>
            @if($locale === 'es')
                Puedes continuar agregando los artículos que compraste antes de enviar la orden nuevamente.
            @else
                You can continue adding items you've purchased before submitting the order again.
            @endif
        </p>
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
                Ahora nuestro equipo comenzará a procesar tu orden para enviarla a México.
            @else
                Our team will now begin processing your order to ship it to Mexico.
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
        <p>
            @if($locale === 'es')
                Estamos consolidando tus artículos y preparando todo para el envío. Te notificaremos tan pronto como tu paquete esté en camino.
            @else
                We are consolidating your items and preparing everything for shipment. We will notify you as soon as your package is on its way.
            @endif
        </p>
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
        <p>
            @if($locale === 'es')
                ¡Gracias por tu pago!
            @else
                Thank you for your payment!
            @endif
        </p>
        <p>
            @if($locale === 'es')
                Tu orden {{ $order->tracking_number }} está completamente pagada y finalizada.
            @else
                Your order {{ $order->tracking_number }} is fully paid and complete.
            @endif
        </p>
        <p>
            @if($locale === 'es')
                Agradecemos tu confianza en Boxly. ¡Esperamos verte pronto!
            @else
                We appreciate you trusting Boxly. We hope to see you again soon!
            @endif
        </p>
    @break

    @case('shipped')
        <p>
            @if($locale === 'es')
                Tu orden {{ $order->tracking_number }} ha sido enviada.
            @else
                Your order {{ $order->tracking_number }} has been shipped.
            @endif
        </p>
        @if($order->guia_number)
        <p>
            @if($locale === 'es')
                Número de guía: {{ $order->guia_number }}
                @if($order->estimated_delivery_date)
                    <br>Fecha estimada de entrega: {{ $order->estimated_delivery_date->format('d/m/Y') }}
                @endif
            @else
                Waybill number: {{ $order->guia_number }}
                @if($order->estimated_delivery_date)
                    <br>Estimated delivery date: {{ $order->estimated_delivery_date->format('m/d/Y') }}
                @endif
            @endif
        </p>
        @endif
        <p>
            @if($locale === 'es')
                Una vez que tu paquete sea entregado, te enviaremos la factura final.
            @else
                Once your package is delivered, we will send you the final invoice.
            @endif
        </p>
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
                En breve, recibirás un correo electrónico con la factura final y el enlace para realizar tu pago.
            @else
                Shortly, you will receive an email with the final invoice and a link to make your payment.
            @endif
        </p>
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