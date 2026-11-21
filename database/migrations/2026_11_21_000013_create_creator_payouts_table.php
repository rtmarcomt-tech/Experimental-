<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('creator_payouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('creator_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('recipient_email', 255);
            $table->string('status', 24)->default('requested')->index();
            $table->string('idempotency_key', 160)->unique();
            $table->string('paypal_batch_id', 128)->nullable()->unique();
            $table->string('paypal_item_id', 128)->nullable()->unique();
            $table->text('failure_reason')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['creator_profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_payouts');
    }
};
