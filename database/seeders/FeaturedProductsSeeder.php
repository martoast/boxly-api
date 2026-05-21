<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Local-dev seed: a small set of stores, categories, and 8 featured
 * products so the landing-page "Trending en USA" rail has something
 * real to render. Idempotent — uses firstOrCreate on slugs.
 *
 *     php artisan db:seed --class=FeaturedProductsSeeder
 */
class FeaturedProductsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Stores (Boxly Store curation — flagged active + landing)
        $storeDefs = [
            ['name' => 'Nike',          'base_url' => 'https://www.nike.com'],
            ['name' => 'adidas',        'base_url' => 'https://www.adidas.com'],
            ['name' => 'Lululemon',     'base_url' => 'https://shop.lululemon.com'],
            ['name' => 'Stanley',       'base_url' => 'https://www.stanley1913.com'],
            ['name' => 'Sol de Janeiro','base_url' => 'https://soldejaneiro.com'],
            ['name' => 'YoungLA',       'base_url' => 'https://www.youngla.com'],
            ['name' => 'Owala',         'base_url' => 'https://owalalife.com'],
            ['name' => 'Coach',         'base_url' => 'https://www.coach.com'],
        ];
        $stores = [];
        foreach ($storeDefs as $i => $def) {
            $stores[$def['name']] = Store::firstOrCreate(
                ['slug' => Str::slug($def['name'])],
                [
                    'name'            => $def['name'],
                    'base_url'        => $def['base_url'],
                    'is_active'       => true,
                    'show_on_landing' => true,
                    'sort_order'      => 10 + $i,
                ],
            );
        }

        // 2. Categories
        $catDefs = [
            'Sneakers',
            'Gym Clothes',
            'Beauty',
            'Lifestyle',
            'Bags',
        ];
        $categories = [];
        foreach ($catDefs as $i => $name) {
            $categories[$name] = Category::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'is_active' => true, 'sort_order' => 10 + $i],
            );
        }

        // 3. Featured products. Images use Unsplash photo IDs — stable
        // hotlinks that won't break locally. Prices are MXN cents.
        $products = [
            [
                'name'   => 'Nike Air Max 1 Premium',
                'store'  => 'Nike',
                'cats'   => ['Sneakers'],
                'price'  => 320000,
                'cost'   => 220000,
                'images' => ['https://images.unsplash.com/photo-1542291026-7eec264c27ff?w=800&q=80'],
            ],
            [
                'name'   => 'adidas Samba OG White',
                'store'  => 'adidas',
                'cats'   => ['Sneakers'],
                'price'  => 280000,
                'cost'   => 200000,
                'images' => ['https://images.unsplash.com/photo-1525966222134-fcfa99b8ae77?w=800&q=80'],
            ],
            [
                'name'   => 'Lululemon Align High-Rise Pant',
                'store'  => 'Lululemon',
                'cats'   => ['Gym Clothes'],
                'price'  => 240000,
                'cost'   => 170000,
                'images' => ['https://images.unsplash.com/photo-1506629082955-511b1aa562c8?w=800&q=80'],
            ],
            [
                'name'   => 'YoungLA Immortal Hoodie',
                'store'  => 'YoungLA',
                'cats'   => ['Gym Clothes'],
                'price'  => 145000,
                'cost'   => 95000,
                'images' => ['https://images.unsplash.com/photo-1556821840-3a63f95609a7?w=800&q=80'],
            ],
            [
                'name'   => 'Stanley Quencher 40oz Tumbler',
                'store'  => 'Stanley',
                'cats'   => ['Lifestyle'],
                'price'  => 85000,
                'cost'   => 55000,
                'images' => ['https://images.unsplash.com/photo-1602143407151-7111542de6e8?w=800&q=80'],
            ],
            [
                'name'   => 'Sol de Janeiro Brazilian Crush Cheirosa 62',
                'store'  => 'Sol de Janeiro',
                'cats'   => ['Beauty'],
                'price'  => 95000,
                'cost'   => 65000,
                'images' => ['https://images.unsplash.com/photo-1592945403244-b3fbafd7f539?w=800&q=80'],
            ],
            [
                'name'   => 'Owala FreeSip 24oz Bottle',
                'store'  => 'Owala',
                'cats'   => ['Lifestyle'],
                'price'  => 65000,
                'cost'   => 38000,
                'images' => ['https://images.unsplash.com/photo-1602143407151-7111542de6e8?w=800&q=80'],
            ],
            [
                'name'   => 'Coach Tabby Shoulder Bag 26',
                'store'  => 'Coach',
                'cats'   => ['Bags'],
                'price'  => 850000,
                'cost'   => 580000,
                'images' => ['https://images.unsplash.com/photo-1584917865442-de89df76afd3?w=800&q=80'],
            ],
        ];

        foreach ($products as $i => $def) {
            $product = Product::firstOrCreate(
                ['slug' => Str::slug($def['name'])],
                [
                    'name'         => $def['name'],
                    'description'  => 'Curated by Boxly. Shipped from USA to your door in Mexico.',
                    'price_cents'  => $def['price'],
                    'cost_cents'   => $def['cost'],
                    'markup_percent' => 10,
                    'weight_kg'    => 1.5,
                    'length_cm'    => 30,
                    'width_cm'     => 25,
                    'height_cm'    => 15,
                    'status'       => Product::STATUS_ACTIVE,
                    'is_featured'  => true,
                    'store_id'     => $stores[$def['store']]->id,
                    'images'       => $def['images'],
                ],
            );

            $catIds = array_map(fn ($c) => $categories[$c]->id, $def['cats']);
            $product->categories()->syncWithoutDetaching($catIds);
        }

        $this->command->info(sprintf(
            'Seeded %d stores, %d categories, %d products (all featured).',
            count($stores),
            count($categories),
            count($products),
        ));
    }
}
