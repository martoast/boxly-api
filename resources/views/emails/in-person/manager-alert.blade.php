@extends('emails.layout')

@section('subject', 'Nueva reserva')

@section('content')
    <h2>Nueva reserva de compra personal</h2>
    <table style="width: 100%; border-collapse: collapse; margin: 16px 0;">
        <tr><td style="padding: 6px 0; color: #666; width: 160px;">Cliente</td><td style="padding: 6px 0; font-weight: 600;">{{ $user->name }}</td></tr>
        <tr><td style="padding: 6px 0; color: #666;">Teléfono</td><td style="padding: 6px 0;">{{ $user->phone ?: '—' }}@if(preg_replace('/\D+/', '', (string) $user->phone)) · <a href="https://wa.me/{{ preg_replace('/\D+/', '', (string) $user->phone) }}">WhatsApp</a>@endif</td></tr>
        <tr><td style="padding: 6px 0; color: #666;">Email</td><td style="padding: 6px 0;">{{ $user->email }}</td></tr>
        <tr><td style="padding: 6px 0; color: #666;">Fecha</td><td style="padding: 6px 0; font-weight: 600;">{{ $r->dateLabel('es') }}</td></tr>
        <tr><td style="padding: 6px 0; color: #666;">Horario</td><td style="padding: 6px 0; font-weight: 600;">{{ $r->startLabel() }} – {{ $r->endLabel() }} ({{ $r->hours_reserved }} h) · hora de California</td></tr>
        <tr><td style="padding: 6px 0; color: #666;">Reserva</td><td style="padding: 6px 0;">{{ $r->reservation_number }} · ${{ number_format($r->amount_usd, 2) }} USD pagados</td></tr>
    </table>
    @if($r->customer_notes)
        <div style="padding: 10px 12px; background: #fffbeb; border-left: 3px solid #f59e0b; border-radius: 4px; white-space: pre-wrap;">{{ $r->customer_notes }}</div>
    @endif
    <div style="text-align: center; margin: 30px 0;"><a href="{{ $url }}" class="button"> Ver en mi disponibilidad</a></div>
@endsection
