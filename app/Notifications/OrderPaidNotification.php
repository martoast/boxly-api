<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderPaidNotification extends Notification
{
    use Queueable;

    protected Order $order;

    /**
     * Create a new notification instance.
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Pago Recibido - Orden ' . $this->order->order_number)
            ->line('Se ha recibido el pago para la orden ' . $this->order->order_number)
            ->line('Cliente: ' . $this->order->user->name)
            ->line('Email: ' . $this->order->user->email)
            ->line('Monto Pagado: $' . number_format($this->order->amount_paid, 2) . ' ' . strtoupper($this->order->currency))
            ->line('Número de Rastreo: ' . $this->order->tracking_number)
            ->line('Tamaño de Caja: ' . ucfirst($this->order->box_size))
            ->line('Peso Total: ' . ($this->order->actual_weight ?? $this->order->total_weight) . ' kg')
            ->action('Ver Orden', url('/admin/orders/' . $this->order->id))
            ->line('La orden está lista para ser enviada.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'customer_name' => $this->order->user->name,
            'amount_paid' => $this->order->amount_paid,
            'status' => 'paid'
        ];
    }
}