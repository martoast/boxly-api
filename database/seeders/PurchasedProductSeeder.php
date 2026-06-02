<?php

namespace Database\Seeders;

use App\Models\PurchasedProduct;
use Illuminate\Database\Seeder;

/**
 * Local-dev seed: a handful of "Productos Comprados" rows so Velonie's
 * tracker page has realistic data to render — a mix of pending/delivered,
 * with and without the store order number (a blank one mimics a row just
 * auto-seeded from a PR that she hasn't filled in yet).
 *
 *     php artisan db:seed --class=PurchasedProductSeeder
 */
class PurchasedProductSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            [
                'customer_name' => 'María González',
                'contact_phone' => '+52 55 1234 5678',
                'items'         => "2x Sudadera YoungLA Immortal\n1x Joggers YoungLA",
                'order_number'  => 'YLA-100482',
                'status'        => 'delivered',
                'order_date'    => '2026-05-22',
            ],
            [
                'customer_name' => 'Carlos Ramírez',
                'contact_phone' => '+52 33 9876 5432',
                'items'         => "1x Chubbies 5.5\" Shorts\n1x Chubbies Polo",
                'order_number'  => 'CHB-77219',
                'status'        => 'delivered',
                'order_date'    => '2026-05-28',
            ],
            [
                // Mimics a row just auto-seeded from a PR — no store order # yet.
                'customer_name' => 'Ana Torres',
                'contact_phone' => '+52 81 2233 4455',
                'items'         => "3x Alo Yoga Leggings\n1x Alo Yoga Sports Bra",
                'order_number'  => null,
                'status'        => 'pending',
                'order_date'    => '2026-06-01',
            ],
            [
                'customer_name' => 'Luis Hernández',
                'contact_phone' => '+52 55 6677 8899',
                'items'         => "1x Gymshark Vital Hoodie",
                'order_number'  => 'GS-558102',
                'status'        => 'pending',
                'order_date'    => '2026-06-02',
            ],
            [
                'customer_name' => 'Sofía Martínez',
                'contact_phone' => null,
                'items'         => "2x Stanley Quencher 40oz",
                'order_number'  => null,
                'status'        => 'pending',
                'order_date'    => '2026-06-02',
            ],
            [
                'customer_name' => 'Diego Flores',
                'contact_phone' => '+52 44 1122 3344',
                'items'         => "1x Nike Tech Fleece\n2x Nike Dri-FIT Tee",
                'order_number'  => 'NIK-9930241',
                'status'        => 'delivered',
                'order_date'    => '2026-05-19',
            ],
        ];

        foreach ($rows as $row) {
            PurchasedProduct::firstOrCreate(
                ['customer_name' => $row['customer_name'], 'order_date' => $row['order_date']],
                $row
            );
        }
    }
}
