<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'is_hidden')) {
                $table->boolean('is_hidden')->default(false)->after('price')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'is_hidden')) {
                $table->dropIndex(['is_hidden']);
                $table->dropColumn('is_hidden');
            }
        });
    }
};
