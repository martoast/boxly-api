<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores already have a logo (small mark for product cards / breadcrumbs);
 * this adds a separate full-bleed cover/lifestyle image used by the
 * brand showcase tiles on /shop and the /shop/brands index page. Keeps
 * the logo and the editorial photo as independent assets so admins can
 * change one without affecting the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('cover_image_url')->nullable()->after('logo_url');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('cover_image_url');
        });
    }
};
