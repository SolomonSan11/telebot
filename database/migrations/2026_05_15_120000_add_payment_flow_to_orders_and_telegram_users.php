<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_method', 32)->default('cash')->after('status');
            $table->timestamp('payment_proof_received_at')->nullable()->after('payment_method');
        });

        Schema::table('telegram_users', function (Blueprint $table) {
            $table->foreignId('awaiting_payment_proof_for_order_id')
                ->nullable()
                ->after('shopping_cart')
                ->constrained('orders')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('awaiting_payment_proof_for_order_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'payment_proof_received_at']);
        });
    }
};
