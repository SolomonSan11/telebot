<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->text('description')->nullable();
        });

        Schema::table('telegram_users', function (Blueprint $table) {
            $table->json('shopping_cart')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('description');
        });

        Schema::table('telegram_users', function (Blueprint $table) {
            $table->dropColumn('shopping_cart');
        });
    }
};
