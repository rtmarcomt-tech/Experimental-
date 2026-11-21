<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_transaction_id')->constrained()->restrictOnDelete();
            $table->string('provider_refund_id', 128)->nullable()->unique();
            $table->string('idempotency_key', 160)->unique();
            $table->unsignedBigInteger('gross_minor');
            $table->unsignedBigInteger('creator_reversal_minor')->default(0);
            $table->unsignedBigInteger('platform_reversal_minor')->default(0);
            $table->char('currency', 3);
            $table->string('status', 20)->default('requested')->index();
            $table->string('source', 20)->default('admin');
            $table->string('note', 255)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['payment_transaction_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
};
