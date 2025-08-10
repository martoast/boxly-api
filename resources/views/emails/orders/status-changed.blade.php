@extends('emails.layout')
@section('subject', $subject)
@section('content')
    @php
        $locale = $order->user->preferred_language ?? 'es';
        app()->setLocale($locale);
    @endphp
    
    {{-- Header with status-specific title --}}
    <h1 style="color: #333; font-size: 24px; margin-bottom: 20px;">
        @switch($order->status)
            @case(\App\Models\Order::STATUS_COLLECTING)
                {{ $locale === 'es' ? '📦 Tu orden está lista para recibir productos' : '📦 Your order is ready for products' }}
                @break
            @case(\App\Models\Order::STATUS_AWAITING_PACKAGES)
                {{ $locale === 'es' ? '📦 Esperando tus paquetes' : '📦Awaiting your packages' }}
                @break
            @case(\App\Models\Order::STATUS_PACKAGES_COMPLETE)
                {{ $locale === 'es' ? '✅ ¡Todos tus paquetes han llegado!' : '✅ All your packages have arrived!' }}
                @break
            @case(\App\Models\Order::STATUS_PROCESSING)
                {{ $locale === 'es' ? '⚙️ Procesando tu orden' : '⚙️ Processing your order' }}
                @break
            @case(\App\Models\Order::STATUS_QUOTE_SENT)
                {{ $locale === 'es' ? '💰 Tu cotización está lista' : '💰 Your quote is ready' }}
                @break
            @case(\App\Models\Order::STATUS_PAID)
                {{ $locale === 'es' ? '✅ Pago recibido' : '✅ Payment received' }}
                @break
            @case(\App\Models\Order::STATUS_SHIPPED)
                {{ $locale === 'es' ? '🛫 Tu paquete está en camino' : '🛫 Your package is on the way' }}
                @break
            @case(\App\Models\Order::STATUS_DELIVERED)
                {{ $locale === 'es' ? '🎉 Tu paquete ha sido entregado' : '🎉 Your package has been delivered' }}
                @break
            @case(\App\Models\Order::STATUS_CANCELLED)
                {{ $locale === 'es' ? '❌ Orden cancelada' : '❌ Order cancelled' }}
                @break
        @endswitch
    </h1>
    
    <p>{{ $locale === 'es' ? 'Hola' : 'Hello' }} {{ $order->user->name }},</p>
    
    {{-- Status-specific message --}}
    @switch($order->status)
        @case(\App\Models\Order::STATUS_COLLECTING)
            @if($locale === 'es')
                <p>Tu orden <strong>{{ $order->tracking_number }}</strong> ha sido reabierta y está lista para agregar más productos.</p>
                <p>Puedes continuar agregando los artículos que compraste antes de enviar la orden nuevamente.</p>
            @else
                <p>Your order <strong>{{ $order->tracking_number }}</strong> has been reopened and is ready to add more products.</p>
                <p>You can continue adding items you've purchased before submitting the order again.</p>
            @endif
            @break
            
        @case(\App\Models\Order::STATUS_AWAITING_PACKAGES)
            @if($locale === 'es')
                <p>Estamos esperando que lleguen tus {{ $order->items->count() }} paquete(s) a nuestro almacén en USA.</p>
                
                
            @else
                <p>We're waiting for your {{ $order->items->count() }} package(s) to arrive at our USA warehouse.</p>
            
               
            @endif
            @break
            
        @case(\App\Models\Order::STATUS_PACKAGES_COMPLETE)
            @if($locale === 'es')
                <p><strong>¡Excelentes noticias!</strong> Todos los paquetes de tu orden <strong>{{ $order->tracking_number }}</strong> han llegado a nuestro almacén.</p>
                <p>Peso total recibido: <strong>{{ $order->total_weight }} kg</strong></p>
                <p>Nuestro equipo comenzará a procesar tu orden pronto. Te enviaremos una cotización una vez que esté lista.</p>
            @else
                <p><strong>Great news!</strong> All packages for your order <strong>{{ $order->tracking_number }}</strong> have arrived at our warehouse.</p>
                <p>Total weight received: <strong>{{ $order->total_weight }} kg</strong></p>
                <p>Our team will start processing your order soon. We'll send you a quote once it's ready.</p>
            @endif
            @break
            
        @case(\App\Models\Order::STATUS_PROCESSING)
            @if($locale === 'es')
                <p>Tu orden <strong>{{ $order->tracking_number }}</strong> está siendo procesada por nuestro equipo.</p>
                <p>Estamos:</p>
                <ul>
                    <li>Verificando todos tus artículos</li>
                    <li>Calculando el mejor tamaño de caja para tu envío</li>
                    <li>Preparando tu cotización final</li>
                </ul>
                <p>Recibirás tu cotización en las próximas 24-48 horas.</p>
            @else
                <p>Your order <strong>{{ $order->tracking_number }}</strong> is being processed by our team.</p>
                <p>We are:</p>
                <ul>
                    <li>Verifying all your items</li>
                    <li>Calculating the best box size for your shipment</li>
                    <li>Preparing your final quote</li>
                </ul>
                <p>You'll receive your quote within the next 24-48 hours.</p>
            @endif
            @break
            
        @case(\App\Models\Order::STATUS_QUOTE_SENT)
            @if($locale === 'es')
                <p><strong>Tu cotización está lista</strong> para la orden <strong>{{ $order->tracking_number }}</strong>.</p>
                
                @if($order->quote_breakdown)
                    <div style="background: #fff3cd; padding: 15px; border-radius: 8px; margin: 20px 0; border: 1px solid #ffc107;">
                        <h3 style="margin: 0 0 10px 0; color: #856404;">Detalle de cotización:</h3>
                        @foreach($order->quote_breakdown as $item)
                            <div style="display: flex; justify-content: space-between; margin: 5px 0;">
                                <span>{{ $item['description'] ?? '' }}</span>
                                <strong>${{ number_format($item['amount'] ?? 0, 2) }} MXN</strong>
                            </div>
                        @endforeach
                        <div style="border-top: 2px solid #ffc107; margin-top: 10px; padding-top: 10px;">
                            <div style="display: flex; justify-content: space-between;">
                                <strong>TOTAL:</strong>
                                <strong style="color: #856404; font-size: 18px;">${{ number_format($order->quoted_amount, 2) }} MXN</strong>
                            </div>
                        </div>
                    </div>
                @endif
                
                <p><strong>⏰ Esta cotización expira el:</strong> {{ $order->quote_expires_at ? $order->quote_expires_at->format('d/m/Y') : 'En 7 días' }}</p>
                
                @if($order->payment_link)
                    <div style="text-align: center; margin: 30px 0;">
                        <a href="{{ $order->payment_link }}" style="background: #28a745; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">
                            💳 PAGAR AHORA
                        </a>
                    </div>
                @endif
            @else
                <p><strong>Your quote is ready</strong> for order <strong>{{ $order->tracking_number }}</strong>.</p>
                
                @if($order->quote_breakdown)
                    <div style="background: #fff3cd; padding: 15px; border-radius: 8px; margin: 20px 0; border: 1px solid #ffc107;">
                        <h3 style="margin: 0 0 10px 0; color: #856404;">Quote breakdown:</h3>
                        @foreach($order->quote_breakdown as $item)
                            <div style="display: flex; justify-content: space-between; margin: 5px 0;">
                                <span>{{ $item['description'] ?? '' }}</span>
                                <strong>${{ number_format($item['amount'] ?? 0, 2) }} MXN</strong>
                            </div>
                        @endforeach
                        <div style="border-top: 2px solid #ffc107; margin-top: 10px; padding-top: 10px;">
                            <div style="display: flex; justify-content: space-between;">
                                <strong>TOTAL:</strong>
                                <strong style="color: #856404; font-size: 18px;">${{ number_format($order->quoted_amount, 2) }} MXN</strong>
                            </div>
                        </div>
                    </div>
                @endif
                
                <p><strong>⏰ This quote expires on:</strong> {{ $order->quote_expires_at ? $order->quote_expires_at->format('m/d/Y') : 'In 7 days' }}</p>
                
                @if($order->payment_link)
                    <div style="text-align: center; margin: 30px 0;">
                        <a href="{{ $order->payment_link }}" style="background: #28a745; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">
                            💳 PAY NOW
                        </a>
                    </div>
                @endif
            @endif
            @break
            
        @case(\App\Models\Order::STATUS_PAID)
            @if($locale === 'es')
                <p><strong>¡Gracias por tu pago!</strong></p>
                <p>Hemos recibido tu pago de <strong>${{ number_format($order->amount_paid, 2) }} MXN</strong> para la orden <strong>{{ $order->tracking_number }}</strong>.</p>
                <p>Ahora procederemos a:</p>
                <ul>
                    <li>Consolidar todos tus artículos en una sola caja</li>
                    <li>Preparar la documentación de envío</li>
                    <li>Programar el envío a México</li>
                </ul>
                <p>Te notificaremos cuando tu paquete sea enviado.</p>
            @else
                <p><strong>Thank you for your payment!</strong></p>
                <p>We've received your payment of <strong>${{ number_format($order->amount_paid, 2) }} MXN</strong> for order <strong>{{ $order->tracking_number }}</strong>.</p>
                <p>We will now proceed to:</p>
                <ul>
                    <li>Consolidate all your items into one box</li>
                    <li>Prepare shipping documentation</li>
                    <li>Schedule shipment to Mexico</li>
                </ul>
                <p>We'll notify you when your package is shipped with the tracking number.</p>
            @endif
            @break
            
        @case(\App\Models\Order::STATUS_SHIPPED)
            @if($locale === 'es')
                <p><strong>¡Tu paquete está en camino a México!</strong> 🚛</p>
                <p>Tu orden <strong>{{ $order->tracking_number }}</strong> ha sido enviada.</p>
                
                <div style="background: #d4edda; padding: 15px; border-radius: 8px; margin: 20px 0; border: 1px solid #c3e6cb;">
                    <p style="margin: 0 0 10px 0;"><strong>Información de envío:</strong></p>
                    <ul style="margin: 0;">
                        <li><strong>Número de rastreo:</strong> {{ $order->tracking_number }}</li>
                        @if($order->estimated_delivery_date)
                            <li><strong>Fecha estimada de entrega:</strong> {{ $order->estimated_delivery_date->format('d/m/Y') }}</li>
                        @endif
                        <li><strong>Dirección de entrega:</strong><br>
                            {{ $order->delivery_address['street'] }} {{ $order->delivery_address['exterior_number'] }}<br>
                            {{ $order->delivery_address['colonia'] }}, {{ $order->delivery_address['municipio'] }}<br>
                            {{ $order->delivery_address['estado'] }}, C.P. {{ $order->delivery_address['postal_code'] }}
                        </li>
                    </ul>
                </div>
                
                <p>Puedes rastrear tu paquete usando el número de rastreo proporcionado.</p>
            @else
                <p><strong>Your package is on its way to Mexico!</strong> 🚛</p>
                <p>Your order <strong>{{ $order->tracking_number }}</strong> has been shipped.</p>
                
                <div style="background: #d4edda; padding: 15px; border-radius: 8px; margin: 20px 0; border: 1px solid #c3e6cb;">
                    <p style="margin: 0 0 10px 0;"><strong>Shipping information:</strong></p>
                    <ul style="margin: 0;">
                        <li><strong>Tracking number:</strong> {{ $order->tracking_number }}</li>
                        @if($order->estimated_delivery_date)
                            <li><strong>Estimated delivery date:</strong> {{ $order->estimated_delivery_date->format('m/d/Y') }}</li>
                        @endif
                        <li><strong>Delivery address:</strong><br>
                            {{ $order->delivery_address['street'] }} {{ $order->delivery_address['exterior_number'] }}<br>
                            {{ $order->delivery_address['colonia'] }}, {{ $order->delivery_address['municipio'] }}<br>
                            {{ $order->delivery_address['estado'] }}, C.P. {{ $order->delivery_address['postal_code'] }}
                        </li>
                    </ul>
                </div>
                
                <p>You can track your package using the provided tracking number.</p>
            @endif
            @break
            
        @case(\App\Models\Order::STATUS_DELIVERED)
            @if($locale === 'es')
                <p><strong>¡Tu paquete ha sido entregado exitosamente!</strong> 🎉</p>
                <p>Tu orden <strong>{{ $order->tracking_number }}</strong> ha sido entregada en la dirección registrada.</p>
                
                <p>Gracias por confiar en nosotros para tus envíos a México. ¡Esperamos verte pronto!</p>
                
                <div style="text-align: center; margin: 30px 0;">
                    <a href="{{ config('app.frontend_url') }}/app/orders/create" style="background: #007bff; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;">
                        Crear Nueva Orden
                    </a>
                </div>
            @else
                <p><strong>Your package has been successfully delivered!</strong> 🎉</p>
                <p>Your order <strong>{{ $order->tracking_number }}</strong> has been delivered to the registered address.</p>
                
                @if($order->delivered_at)
                    <p><strong>Delivery date:</strong> {{ $order->delivered_at->format('m/d/Y H:i') }}</p>
                @endif
                
                <p>Thank you for trusting us with your shipments to Mexico. We hope to see you again soon!</p>
                
                <div style="text-align: center; margin: 30px 0;">
                    <a href="{{ config('app.frontend_url') }}/app/orders/new" style="background: #007bff; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;">
                        Create New Order
                    </a>
                </div>
            @endif
            @break
            
        @case(\App\Models\Order::STATUS_CANCELLED)
            @if($locale === 'es')
                <p>Tu orden <strong>{{ $order->tracking_number }}</strong> ha sido cancelada.</p>
                @if($order->notes)
                    <p><strong>Razón:</strong> {{ $order->notes }}</p>
                @endif
                <p>Si tienes alguna pregunta, por favor contáctanos.</p>
            @else
                <p>Your order <strong>{{ $order->tracking_number }}</strong> has been cancelled.</p>
                @if($order->notes)
                    <p><strong>Reason:</strong> {{ $order->notes }}</p>
                @endif
                <p>If you have any questions, please contact us.</p>
            @endif
            @break
    @endswitch
    
    {{-- Call to action button --}}
    <div style="text-align: center; margin: 30px 0;">
        <a href="{{ config('app.frontend_url') }}/app/orders/{{ $order->id }}" style="background: #007bff; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;">
            {{ $locale === 'es' ? 'Ver Detalles de la Orden' : 'View Order Details' }}
        </a>
    </div>
    
    {{-- Footer message --}}
    @if($locale === 'es')
        <p style="color: #666; font-size: 14px; margin-top: 30px;">
            Si tienes alguna pregunta contáctanos en WhatsApp: +1 619 559-1920
        </p>
    @else
        <p style="color: #666; font-size: 14px; margin-top: 30px;">
            If you have any questions, contact us on WhatsApp: +1 619 559-1920
        </p>
    @endif
@endsection