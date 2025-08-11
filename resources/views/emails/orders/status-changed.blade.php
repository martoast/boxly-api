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
                {{ $locale === 'es' ? '⏳ Esperando tus paquetes' : '⏳ Awaiting your packages' }}
                @break
            @case(\App\Models\Order::STATUS_PACKAGES_COMPLETE)
                {{ $locale === 'es' ? '✅ Hemos recibido todos tus paquetes' : '✅ We have received all your packages' }}
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
                <p>Tu orden <strong>{{ $order->tracking_number }}</strong> ha sido creada exitosamente.</p>
                <p>Estamos esperando que lleguen tus paquete(s) a nuestro almacén en USA.</p>
                
                
            @else
                <p>Your order <strong>{{ $order->tracking_number }}</strong> has been created successfully.</p>
                <p>We're waiting for your package(s) to arrive at our USA warehouse.</p>
                
              
            @endif
            @break
            
        @case(\App\Models\Order::STATUS_PACKAGES_COMPLETE)
            @if($locale === 'es')
                <p><strong>¡Excelentes noticias!</strong> Hemos recibido todos los paquetes de tu orden <strong>{{ $order->tracking_number }}</strong> en nuestro almacén.</p>
                
                {{-- Items List --}}
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 20px 0;">
                    <h3 style="margin: 0 0 10px 0; font-size: 16px;">Productos recibidos:</h3>
                    <ul style="margin: 0; padding-left: 20px;">
                        @foreach($order->items as $item)
                            <li style="margin: 5px 0;">
                                {{ $item->product_name }}
                                @if($item->quantity > 1)
                                    (Cantidad: {{ $item->quantity }})
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <p style="margin: 10px 0 0 0; padding-top: 10px; border-top: 1px solid #dee2e6;">
                        <strong>Total de artículos:</strong> {{ $order->items->sum('quantity') }}<br>
                        @if($order->total_weight)
                            <strong>Peso total:</strong> {{ number_format($order->total_weight, 2) }} kg
                        @endif
                    </p>
                </div>
                
                <p>Ahora nuestro equipo comenzará a procesar tu orden. Te enviaremos una cotización una vez que esté lista.</p>
            @else
                <p><strong>Great news!</strong> We have received all packages for your order <strong>{{ $order->tracking_number }}</strong> at our warehouse.</p>
                
                {{-- Items List --}}
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 20px 0;">
                    <h3 style="margin: 0 0 10px 0; font-size: 16px;">Items received:</h3>
                    <ul style="margin: 0; padding-left: 20px;">
                        @foreach($order->items as $item)
                            <li style="margin: 5px 0;">
                                {{ $item->product_name }}
                                @if($item->quantity > 1)
                                    (Quantity: {{ $item->quantity }})
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <p style="margin: 10px 0 0 0; padding-top: 10px; border-top: 1px solid #dee2e6;">
                        <strong>Total items:</strong> {{ $order->items->sum('quantity') }}<br>
                        @if($order->total_weight)
                            <strong>Total weight:</strong> {{ number_format($order->total_weight, 2) }} kg
                        @endif
                    </p>
                </div>
                
                <p>Our team will now begin processing your order. We'll send you a quote once it's ready.</p>
            @endif
            @break
            
        @case(\App\Models\Order::STATUS_PROCESSING)
            @if($locale === 'es')
                <p>Tu orden <strong>{{ $order->tracking_number }}</strong> está siendo procesada por nuestro equipo.</p>
                
                {{-- Items Being Processed --}}
                <div style="background: #e7f5ff; padding: 15px; border-radius: 8px; margin: 20px 0;">
                    <h3 style="margin: 0 0 10px 0; font-size: 16px; color: #0c5460;">📦 Productos en tu envío:</h3>
                    <ul style="margin: 0; padding-left: 20px;">
                        @foreach($order->items as $item)
                            <li style="margin: 5px 0;">
                                {{ $item->product_name }}
                                @if($item->quantity > 1)
                                    (Cantidad: {{ $item->quantity }})
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <p style="margin: 10px 0 0 0; padding-top: 10px; border-top: 1px solid #bee5eb;">
                        <strong>Total de artículos:</strong> {{ $order->items->sum('quantity') }}<br>
                        @if($order->total_weight)
                            <strong>Peso total:</strong> {{ number_format($order->total_weight, 2) }} kg
                        @endif
                    </p>
                </div>
                
                <div style="background: #fff3cd; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ffc107;">
                    <p style="margin: 0; font-weight: bold; color: #856404;">
                        💰 ¿Qué sigue?
                    </p>
                    <p style="margin: 10px 0 0 0; color: #856404;">
                        Estamos calculando el mejor tamaño de caja y preparando tu cotización final. 
                        <strong>Pronto recibirás un correo con tu cotización y las instrucciones de pago.</strong>
                    </p>
                </div>
                
                <p>El proceso de cotización normalmente toma entre 24-48 horas hábiles.</p>
            @else
                <p>Your order <strong>{{ $order->tracking_number }}</strong> is being processed by our team.</p>
                
                {{-- Items Being Processed --}}
                <div style="background: #e7f5ff; padding: 15px; border-radius: 8px; margin: 20px 0;">
                    <h3 style="margin: 0 0 10px 0; font-size: 16px; color: #0c5460;">📦 Items in your shipment:</h3>
                    <ul style="margin: 0; padding-left: 20px;">
                        @foreach($order->items as $item)
                            <li style="margin: 5px 0;">
                                {{ $item->product_name }}
                                @if($item->quantity > 1)
                                    (Quantity: {{ $item->quantity }})
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <p style="margin: 10px 0 0 0; padding-top: 10px; border-top: 1px solid #bee5eb;">
                        <strong>Total items:</strong> {{ $order->items->sum('quantity') }}<br>
                        @if($order->total_weight)
                            <strong>Total weight:</strong> {{ number_format($order->total_weight, 2) }} kg
                        @endif
                    </p>
                </div>
                
                <div style="background: #fff3cd; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ffc107;">
                    <p style="margin: 0; font-weight: bold; color: #856404;">
                        💰 What's next?
                    </p>
                    <p style="margin: 10px 0 0 0; color: #856404;">
                        We are calculating the best box size and preparing your final quote. 
                        <strong>You'll soon receive an email with your quote and payment instructions.</strong>
                    </p>
                </div>
                
                <p>The quote process normally takes 24-48 business hours.</p>
            @endif
            @break
            
        @case(\App\Models\Order::STATUS_QUOTE_SENT)
            @if($locale === 'es')
                <p>Hemos preparado la cotización para tu orden <strong>{{ $order->tracking_number }}</strong>.</p>
                
                {{-- Items List --}}
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 20px 0;">
                    <h3 style="margin: 0 0 10px 0; font-size: 16px;">📦 Productos en tu envío:</h3>
                    <ul style="margin: 0; padding-left: 20px;">
                        @foreach($order->items as $item)
                            <li style="margin: 5px 0;">
                                {{ $item->product_name }}
                                @if($item->quantity > 1)
                                    (Cantidad: {{ $item->quantity }})
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <p style="margin: 10px 0 0 0; padding-top: 10px; border-top: 1px solid #dee2e6;">
                        <strong>Total de artículos:</strong> {{ $order->items->sum('quantity') }}<br>
                        @if($order->total_weight)
                            <strong>Peso total:</strong> {{ number_format($order->total_weight, 2) }} kg
                        @endif
                    </p>
                </div>
                
                {{-- Total Amount --}}
                <div style="background: #e7f5ff; padding: 20px; border-radius: 8px; margin: 20px 0; border: 2px solid #17a2b8;">
                    <p style="margin: 0; font-size: 18px; text-align: center;">
                        <strong>Total a pagar:</strong><br>
                        <span style="font-size: 28px; color: #17a2b8;">${{ number_format($order->quoted_amount, 2) }} MXN</span>
                    </p>
                </div>
                
                @if($order->quote_expires_at)
                    <p style="text-align: center; color: #dc3545;">
                        ⏰ Esta cotización expira el {{ $order->quote_expires_at->format('d/m/Y') }}
                    </p>
                @endif
            @else
                <p>We have prepared the quote for your order <strong>{{ $order->tracking_number }}</strong>.</p>
                
                {{-- Items List --}}
                <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; margin: 20px 0;">
                    <h3 style="margin: 0 0 10px 0; font-size: 16px;">📦 Items in your shipment:</h3>
                    <ul style="margin: 0; padding-left: 20px;">
                        @foreach($order->items as $item)
                            <li style="margin: 5px 0;">
                                {{ $item->product_name }}
                                @if($item->quantity > 1)
                                    (Quantity: {{ $item->quantity }})
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <p style="margin: 10px 0 0 0; padding-top: 10px; border-top: 1px solid #dee2e6;">
                        <strong>Total items:</strong> {{ $order->items->sum('quantity') }}<br>
                        @if($order->total_weight)
                            <strong>Total weight:</strong> {{ number_format($order->total_weight, 2) }} kg
                        @endif
                    </p>
                </div>
                
                {{-- Total Amount --}}
                <div style="background: #e7f5ff; padding: 20px; border-radius: 8px; margin: 20px 0; border: 2px solid #17a2b8;">
                    <p style="margin: 0; font-size: 18px; text-align: center;">
                        <strong>Total to pay:</strong><br>
                        <span style="font-size: 28px; color: #17a2b8;">${{ number_format($order->quoted_amount, 2) }} MXN</span>
                    </p>
                </div>
                
                @if($order->quote_expires_at)
                    <p style="text-align: center; color: #dc3545;">
                        ⏰ This quote expires on {{ $order->quote_expires_at->format('m/d/Y') }}
                    </p>
                @endif
            @endif
            
            {{-- Payment CTA Button --}}
            @if($order->payment_link)
                <div style="text-align: center; margin: 30px 0;">
                    <a href="{{ $order->payment_link }}" style="background: #28a745; color: white; padding: 15px 40px; text-decoration: none; border-radius: 5px; display: inline-block; font-size: 18px; font-weight: bold;">
                        {{ $locale === 'es' ? '💳 Pagar Ahora' : '💳 Pay Now' }}
                    </a>
                </div>
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
                        @if($order->delivery_address)
                            <li><strong>Dirección de entrega:</strong><br>
                                {{ $order->delivery_address['street'] }} {{ $order->delivery_address['exterior_number'] }}<br>
                                {{ $order->delivery_address['colonia'] }}, {{ $order->delivery_address['municipio'] }}<br>
                                {{ $order->delivery_address['estado'] }}, C.P. {{ $order->delivery_address['postal_code'] }}
                            </li>
                        @endif
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
                        @if($order->delivery_address)
                            <li><strong>Delivery address:</strong><br>
                                {{ $order->delivery_address['street'] }} {{ $order->delivery_address['exterior_number'] }}<br>
                                {{ $order->delivery_address['colonia'] }}, {{ $order->delivery_address['municipio'] }}<br>
                                {{ $order->delivery_address['estado'] }}, C.P. {{ $order->delivery_address['postal_code'] }}
                            </li>
                        @endif
                    </ul>
                </div>
                
                <p>You can track your package using the provided tracking number.</p>
            @endif
            @break
            
        @case(\App\Models\Order::STATUS_DELIVERED)
            @if($locale === 'es')
                <p><strong>¡Tu paquete ha sido entregado exitosamente!</strong> 🎉</p>
                <p>Tu orden <strong>{{ $order->tracking_number }}</strong> ha sido entregada en la dirección registrada.</p>
                
                @if($order->delivered_at)
                    <p><strong>Fecha de entrega:</strong> {{ $order->delivered_at->format('d/m/Y') }}</p>
                @endif
                
                <p>Gracias por confiar en nosotros para tus envíos a México. ¡Esperamos verte pronto!</p>
                
            @else
                <p><strong>Your package has been successfully delivered!</strong> 🎉</p>
                <p>Your order <strong>{{ $order->tracking_number }}</strong> has been delivered to the registered address.</p>
                
                @if($order->delivered_at)
                    <p><strong>Delivery date:</strong> {{ $order->delivered_at->format('m/d/Y') }}</p>
                @endif
                
                <p>Thank you for trusting us with your shipments to Mexico. We hope to see you again soon!</p>
                
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
    
    {{-- Call to action button (except for quote_sent which has its own payment button) --}}
    @if($order->status !== \App\Models\Order::STATUS_QUOTE_SENT)
        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ config('app.frontend_url') }}/app/orders/{{ $order->id }}" style="background: #007bff; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block;">
                {{ $locale === 'es' ? 'Ver Detalles de la Orden' : 'View Order Details' }}
            </a>
        </div>
    @endif
    
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