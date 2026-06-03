<?php

namespace Database\Seeders;

use App\Models\BusinessExpense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PurchaseRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Local-dev seed: ~15 months of realistic, growing business activity so the
 * Dashboard V3 (hero graph, network scale, pipeline, performance, insights)
 * actually has data to render.
 *
 * Idempotent: bails if demo customers already exist. To reseed, delete demo
 * data first: User::where('email','like','demo+%@boxly.test')->each->delete();
 * (cascades to their orders/items/PRs), then delete demo expenses.
 *
 *     php artisan db:seed --class=DashboardDemoSeeder
 */
class DashboardDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (User::where('email', 'like', 'demo+%@boxly.test')->exists()) {
            $this->command->warn('Dashboard demo data already present — skipping. Delete demo+ users to reseed.');
            return;
        }

        $faker = fake();

        // Admin to own the business expenses (created_by is NOT NULL).
        $admin = User::where('role', 'admin')->first();
        if (! $admin) {
            $admin = new User();
            $admin->name = 'Demo Admin';
            $admin->email = 'demo+admin@boxly.test';
            $admin->password = Hash::make('password');
            $admin->role = 'admin';
            $admin->email_verified_at = now();
            $admin->save();
        }

        // Weighted Mexican market pool (estado, municipio).
        $marketWeights = [
            ['Ciudad de México', 'Ciudad de México', 10],
            ['Jalisco', 'Guadalajara', 7],
            ['Nuevo León', 'Monterrey', 6],
            ['Estado de México', 'Toluca', 6],
            ['Puebla', 'Puebla', 4],
            ['Querétaro', 'Querétaro', 4],
            ['Guanajuato', 'León', 3],
            ['Baja California', 'Tijuana', 3],
            ['Yucatán', 'Mérida', 2],
            ['Veracruz', 'Veracruz', 2],
            ['Quintana Roo', 'Cancún', 2],
            ['Sonora', 'Hermosillo', 2],
            ['Chihuahua', 'Chihuahua', 1],
            ['Sinaloa', 'Culiacán', 1],
            ['Coahuila', 'Saltillo', 1],
        ];
        $marketPool = [];
        foreach ($marketWeights as $m) {
            for ($i = 0; $i < $m[2]; $i++) {
                $marketPool[] = [$m[0], $m[1]];
            }
        }

        // Weighted box-size pool.
        $boxWeights = ['extra-small' => 1, 'small' => 3, 'medium' => 6, 'large' => 4, 'extra-large' => 2];
        $boxPool = [];
        foreach ($boxWeights as $size => $w) {
            for ($i = 0; $i < $w; $i++) {
                $boxPool[] = $size;
            }
        }

        $products = [
            'YoungLA Hoodie', 'Chubbies Shorts', 'Alo Yoga Leggings', 'Gymshark Vital Top',
            'Stanley Quencher 40oz', 'Owala FreeSip', 'Nike Tech Fleece', 'Coach Tote Bag',
            'Sol de Janeiro Mist', 'adidas Samba', 'Lululemon Belt Bag', 'Apple AirPods Pro',
        ];

        $seq = 0;
        $monthRevenue = [];
        $allCustomers = [];
        $months = 15;

        $randomDateInMonth = function (Carbon $monthStart, bool $isCurrent) {
            $start = $monthStart->copy();
            $end = $isCurrent ? Carbon::now() : $monthStart->copy()->endOfMonth();
            $span = max(1, $start->diffInSeconds($end));
            return $start->copy()->addSeconds(random_int(0, $span));
        };

        for ($mAgo = $months - 1; $mAgo >= 0; $mAgo--) {
            $monthStart = Carbon::now()->subMonths($mAgo)->startOfMonth();
            $isCurrent = ($mAgo === 0);
            $ym = $monthStart->format('Y-m');
            $growth = $months - $mAgo; // 1 (oldest) .. 15 (newest)
            $monthRevenue[$ym] = $monthRevenue[$ym] ?? 0;

            // --- Customers ---
            $custCount = 4 + (int) round($growth * 1.6);
            for ($i = 0; $i < $custCount; $i++) {
                $date = $randomDateInMonth($monthStart, $isCurrent);
                [$estado, $municipio] = $marketPool[array_rand($marketPool)];
                $u = new User();
                $u->name = $faker->name();
                $u->email = 'demo+' . $seq . '_' . uniqid() . '@boxly.test';
                $u->password = Hash::make('password');
                $u->role = 'customer';
                $u->user_type = $faker->randomElement(['expat', 'business', 'shopper']);
                $u->phone = '+52' . $faker->numerify('##########');
                $u->estado = $estado;
                $u->municipio = $municipio;
                $u->email_verified_at = $date;
                $u->created_at = $date;
                $u->updated_at = $date;
                $u->save();
                $allCustomers[] = $u;
                $seq++;
            }

            // --- Revenue orders (delivered/shipped, paid) ---
            $ordCount = 4 + (int) round($growth * 1.8);
            for ($i = 0; $i < $ordCount; $i++) {
                $date = $randomDateInMonth($monthStart, $isCurrent);
                $cust = $allCustomers[array_rand($allCustomers)];
                $amount = random_int(1500, 6500) + $growth * 120;
                $status = $faker->boolean(80) ? 'delivered' : 'shipped';

                $o = new Order();
                $o->user_id = $cust->id;
                $o->order_number = 'DEMO-O-' . $seq;
                $o->tracking_number = 'DEMO-T-' . $seq;
                $o->status = $status;
                $o->order_type = 'shipping';
                $o->box_size = $boxPool[array_rand($boxPool)];
                $o->amount_paid = $amount;
                $o->currency = 'mxn';
                $o->paid_at = $date;
                $o->delivered_at = $status === 'delivered' ? $date->copy()->addDays(random_int(3, 9)) : null;
                $o->shipped_at = $date->copy()->addDays(random_int(1, 3));
                $o->delivery_address = ['estado' => $cust->estado, 'municipio' => $cust->municipio];
                $o->created_at = $date->copy()->subDays(random_int(6, 20));
                $o->updated_at = $date;
                $o->save();

                $this->seedItems($o, $faker, $products, true, $date);
                $monthRevenue[$ym] += $amount;
                $seq++;
            }

            // --- Purchase requests (service-fee revenue + sales count) ---
            $prCount = 2 + (int) round($growth * 0.8);
            for ($i = 0; $i < $prCount; $i++) {
                $date = $randomDateInMonth($monthStart, $isCurrent);
                $cust = $allCustomers[array_rand($allCustomers)];
                $currency = $faker->boolean(70) ? 'usd' : 'mxn';
                $fee = $currency === 'usd' ? random_int(8, 70) : random_int(150, 1300);

                $pr = new PurchaseRequest();
                $pr->request_number = 'DEMO-PR-' . $seq;
                $pr->user_id = $cust->id;
                $pr->status = $faker->boolean(60) ? 'purchased' : 'paid';
                $pr->processing_fee = $fee;
                $pr->currency = $currency;
                $pr->created_at = $date;
                $pr->paid_at = $date;
                $pr->updated_at = $date;
                $pr->save();

                $monthRevenue[$ym] += $currency === 'usd' ? $fee * 18 : $fee;
                $seq++;
            }

            // --- Expenses (~60% of the month's revenue, by category) ---
            $exp = $monthRevenue[$ym] * 0.6;
            $cats = ['ads' => 0.35, 'shipping' => 0.30, 'software' => 0.15, 'office' => 0.10, 'po_box' => 0.05, 'misc' => 0.05];
            foreach ($cats as $cat => $pct) {
                $amt = round($exp * $pct, 2);
                if ($amt < 1) {
                    continue;
                }
                $e = new BusinessExpense();
                $e->category = $cat;
                $e->amount = $amt;
                $e->currency = 'mxn';
                $e->expense_date = $monthStart->copy()->addDays(random_int(1, min(25, $isCurrent ? max(1, Carbon::now()->day - 1) : 25)));
                $e->description = 'Demo ' . $cat . ' ' . $ym;
                $e->created_by = $admin->id;
                $e->created_at = $e->expense_date;
                $e->updated_at = $e->expense_date;
                $e->save();
            }
        }

        // --- Live pipeline snapshot (recent in-progress orders for the control tower) ---
        $pipeline = [
            'awaiting_packages' => 9,
            'packages_complete' => 6,
            'awaiting_payment' => 5,
            'paid' => 7,
            'shipped' => 8,
        ];
        $curYm = Carbon::now()->format('Y-m');
        foreach ($pipeline as $status => $n) {
            for ($i = 0; $i < $n; $i++) {
                $date = Carbon::now()->subDays(random_int(0, 28));
                $cust = $allCustomers[array_rand($allCustomers)];
                $paid = in_array($status, ['paid', 'shipped'], true);

                $o = new Order();
                $o->user_id = $cust->id;
                $o->order_number = 'DEMO-O-' . $seq;
                $o->tracking_number = 'DEMO-T-' . $seq;
                $o->status = $status;
                $o->order_type = 'shipping';
                $o->delivery_address = ['estado' => $cust->estado, 'municipio' => $cust->municipio];
                if ($paid) {
                    $amount = random_int(1500, 6000);
                    $o->amount_paid = $amount;
                    $o->currency = 'mxn';
                    $o->paid_at = $date;
                    $o->box_size = $boxPool[array_rand($boxPool)];
                    if ($status === 'shipped') {
                        $o->shipped_at = $date;
                    }
                    $monthRevenue[$curYm] = ($monthRevenue[$curYm] ?? 0) + $amount;
                }
                $o->created_at = $date->copy()->subDays(random_int(2, 12));
                $o->updated_at = $date;
                $o->save();

                // Items arrived unless still awaiting packages.
                $this->seedItems($o, $faker, $products, $status !== 'awaiting_packages', $date);
                $seq++;
            }
        }

        $totalRevenue = array_sum($monthRevenue);
        $this->command->info(sprintf(
            'Seeded demo data: %d customers, %d orders, %d PRs. ~$%s MXN total revenue across %d months.',
            count($allCustomers),
            Order::where('order_number', 'like', 'DEMO-O-%')->count(),
            PurchaseRequest::where('request_number', 'like', 'DEMO-PR-%')->count(),
            number_format($totalRevenue),
            $months
        ));
    }

    private function seedItems(Order $order, $faker, array $products, bool $arrived, Carbon $date): void
    {
        $n = random_int(1, 4);
        for ($k = 0; $k < $n; $k++) {
            $it = new OrderItem();
            $it->order_id = $order->id;
            $it->product_name = $faker->randomElement($products);
            $it->retailer = $faker->randomElement(['YoungLA', 'Chubbies', 'Amazon', 'Nike', 'Gymshark']);
            $it->quantity = random_int(1, 3);
            $it->declared_value = random_int(20, 180);
            $it->arrived = $arrived;
            $it->arrived_at = $arrived ? $date : null;
            $it->created_at = $order->created_at;
            $it->updated_at = $date;
            $it->save();
        }
    }
}
