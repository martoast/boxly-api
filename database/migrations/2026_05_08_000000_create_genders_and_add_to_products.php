<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('genders', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('image_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('gender_id')
                ->nullable()
                ->after('store_id')
                ->constrained('genders')
                ->nullOnDelete();
            $table->index('gender_id');
        });

        // Seed the two default genders so admins don't have to bootstrap them.
        // Gender-neutral products keep gender_id = null.
        $now = now();
        DB::table('genders')->insert([
            ['name' => 'Hombre', 'slug' => 'hombre', 'is_active' => true, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Mujer',  'slug' => 'mujer',  'is_active' => true, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['gender_id']);
            $table->dropColumn('gender_id');
        });
        Schema::dropIfExists('genders');
    }
};
