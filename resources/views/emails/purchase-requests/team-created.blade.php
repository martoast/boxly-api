@extends('emails.layout')

@section('subject', 'Nueva solicitud de compra')

@section('content')
    <h2 style="margin: 0 0 8px;">📥 Nueva solicitud — {{ $request->request_number }}</h2>
    <p style="color: #666; margin: 0 0 20px;">Un cliente acaba de crear una solicitud de compra asistida. Revísala y mándale una cotización.</p>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
        <tr>
            <td style="padding: 6px 0; color: #666; width: 140px;">Cliente</td>
            <td style="padding: 6px 0; font-weight: 600;">{{ $request->user->name }}</td>
        </tr>
        <tr>
            <td style="padding: 6px 0; color: #666;">Email</td>
            <td style="padding: 6px 0;">{{ $request->user->email }}</td>
        </tr>
        <tr>
            <td style="padding: 6px 0; color: #666;">Items</td>
            <td style="padding: 6px 0;">{{ $request->items->count() }}</td>
        </tr>
        <tr>
            <td style="padding: 6px 0; color: #666;">Moneda</td>
            <td style="padding: 6px 0;">{{ strtoupper($request->currency ?? 'usd') }}</td>
        </tr>
    </table>

    <h3 style="margin: 20px 0 8px; font-size: 16px;">Productos solicitados:</h3>

    @foreach($request->items as $item)
        <div style="margin: 0 0 12px; padding: 10px 12px; background: #f8f9fa; border-left: 3px solid #f59e0b; border-radius: 4px;">
            <div style="font-weight: 600; margin-bottom: 4px;">
                {{ $item->product_name }}
                <span style="color: #666; font-weight: normal;">× {{ $item->quantity }}</span>
            </div>
            <div style="color: #666; font-size: 13px;">
                Precio estimado: ${{ number_format($item->price, 2) }}
            </div>
            @if($item->notes)
                <div style="color: #666; font-size: 13px; font-style: italic; margin-top: 4px;">"{{ $item->notes }}"</div>
            @endif
            @if($item->product_url)
                <div style="font-size: 13px; margin-top: 6px;">
                    <a href="{{ $item->product_url }}" style="color: #4f46e5; text-decoration: none;">→ Ver en tienda fuente</a>
                </div>
            @endif
        </div>
    @endforeach

    <div style="margin-top: 24px;">
        <a href="{{ $admin_url }}" style="display: inline-block; padding: 12px 24px; background: #4f46e5; color: #fff; text-decoration: none; border-radius: 8px; font-weight: 600;">
            Revisar y cotizar →
        </a>
    </div>

    <p style="color: #999; font-size: 12px; margin-top: 30px;">
        Una vez revises la solicitud, mándale una cotización al cliente desde el detalle. Cuando paguen, recibirás otro correo para que vayas a comprar los productos.
    </p>
@endsection
