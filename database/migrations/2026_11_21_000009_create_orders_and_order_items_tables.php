<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->string('order_reference', 80)->unique();
            $table->string('status', 24)->default('pending')->index();
            $table->char('currency', 3);
            $table->unsignedBigInteger('gross_minor');
            $table->string('payment_provider', 24)->default('paypal');
            $table->string('idempotency_key', 128)->unique();
            $table->string('paypal_order_id', 128)->nullable()->unique();
            $table->string('approval_url', 2048)->nullable();
            $table->timestamps();
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('digital_media_id')->constrained()->restrictOnDelete();
            $table->foreignId('creator_profile_id')->constrained()->restrictOnDelete();
            $table->string('title_snapshot', 180);
            $table->unsignedBigInteger('unit_price_minor');
            $table->char('currency', 3);
            $table->unsignedSmallInteger('commission_bps');
            $table->unsignedBigInteger('commission_minor');
            $table->unsignedBigInteger('creator_net_minor');
            $table->timestamps();
            $table->index(['creator_profile_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
