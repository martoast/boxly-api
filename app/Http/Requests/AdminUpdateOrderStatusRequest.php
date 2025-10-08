<?php

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminUpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isAdmin();
    }

    public function rules(): array
    {
        return [
            'status' => [
                'required',
                Rule::in(array_keys(Order::getStatuses()))
            ],
            'estimated_delivery_date' => [
                'required_if:status,' . Order::STATUS_SHIPPED,
                'nullable',
                'date',
                'after:today'
            ],
            'notes' => [
                'nullable',
                'string',
                'max:500'
            ],
        ];
    }

    protected function passedValidation(): void
    {
        $order = $this->route('order');
        $newStatus = $this->status;
        $currentStatus = $order->status;

        $validTransitions = [
            Order::STATUS_COLLECTING => [Order::STATUS_AWAITING_PACKAGES, Order::STATUS_CANCELLED],
            Order::STATUS_AWAITING_PACKAGES => [Order::STATUS_PACKAGES_COMPLETE, Order::STATUS_COLLECTING, Order::STATUS_CANCELLED],
            Order::STATUS_PACKAGES_COMPLETE => [Order::STATUS_PROCESSING, Order::STATUS_CANCELLED],
            Order::STATUS_PROCESSING => [Order::STATUS_SHIPPED, Order::STATUS_CANCELLED],
            Order::STATUS_SHIPPED => [Order::STATUS_DELIVERED, Order::STATUS_CANCELLED],
            Order::STATUS_DELIVERED => [Order::STATUS_AWAITING_PAYMENT, Order::STATUS_CANCELLED],
            Order::STATUS_AWAITING_PAYMENT => [Order::STATUS_PAID, Order::STATUS_CANCELLED],
            Order::STATUS_PAID => [],
            Order::STATUS_CANCELLED => [],
        ];

        if ($newStatus !== $currentStatus && !in_array($newStatus, $validTransitions[$currentStatus] ?? [])) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition from '{$currentStatus}' to '{$newStatus}'. Invalid status progression."]
            ]);
        }

        if ($newStatus === Order::STATUS_PACKAGES_COMPLETE) {
            if (!$order->allItemsArrived()) {
                throw ValidationException::withMessages([
                    'status' => ['Cannot mark as packages complete. Not all items have arrived.']
                ]);
            }
        }
    }
}