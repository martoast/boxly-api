<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewOrderNotification extends Notification
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
            ->subject('Nueva Orden Creada - ' . $this->order->order_number)
            ->line('Se ha creado una nueva orden.')
            ->line('Número de Orden: ' . $this->order->order_number)
            ->line('Cliente: ' . $this->order->user->name)
            ->line('Tamaño de Caja: ' . ucfirst($this->order->box_size))
            ->line('Monto Pagado: $' . number_format($this->order->amount_paid, 2) . ' ' . strtoupper($this->order->currency))
            ->action('Ver Orden', url('/admin/orders/' . $this->order->id))
            ->line('El cliente ahora puede comenzar a agregar artículos a su orden.');
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
        ];
    }
}