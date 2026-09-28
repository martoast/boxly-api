<?php

namespace Tests\Feature;

use App\Jobs\FillItemImageJob;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// A cart line / order item without a photo gets the product's own from the catalog service's live read.
class FillItemImageJobTest extends TestCase
{
    public function test_the_product_photo_comes_from_the_catalog_live_read(): void
    {
        config(['services.catalog.url' => 'https://catalog.test']);
        Http::fake(['catalog.test/*' => Http::response(['product' => ['image' => 'http://imgcdn.carhartt.com/is/image/Carhartt/101070_001?hei=800', 'images' => []], 'variants' => []])]);
        $this->assertSame('https://imgcdn.carhartt.com/is/image/Carhartt/101070_001?hei=800', FillItemImageJob::productImage('https://www.carhartt.com/product/105560/x'));
    }

    public function test_no_photo_or_an_unreachable_catalog_is_null(): void
    {
        config(['services.catalog.url' => 'https://catalog.test']);
        Http::fake(['catalog.test/*' => Http::response(['variants' => [], 'error' => 'blocked'])]);
        $this->assertNull(FillItemImageJob::productImage('https://www.example-shop.com/p/1'));
        config(['services.catalog.url' => '']);
        $this->assertNull(FillItemImageJob::productImage('https://www.example-shop.com/p/1'));
    }
}
