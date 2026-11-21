<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 24)->default('paypal');
            $table->string('provider_order_id', 128)->nullable()->unique();
            $table->string('provider_capture_id', 128)->nullable()->unique();
            $table->string('order_reference', 80)->unique();
            $table->unsignedBigInteger('gross_minor');
            $table->char('currency', 3);
            $table->string('status', 24)->default('pending')->index();
            $table->string('idempotency_key', 128)->unique();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();
        });

        Schema::create('media_access_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('digital_media_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('grant_type', 12);
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 120)->nullable();
            $table->timestamps();
            $table->unique(['buyer_id', 'digital_media_id']);
            $table->index(['buyer_id', 'revoked_at']);
        });

        Schema::create('creator_ledger_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('creator_profile_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3);
            $table->string('account_type', 20);
            $table->timestamps();
            $table->unique(['creator_profile_id', 'currency', 'account_type'], 'creator_ledger_account_unique');
        });

        Schema::create('creator_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ledger_account_id')->constrained('creator_ledger_accounts')->restrictOnDelete();
            $table->foreignId('payment_transaction_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('entry_type', 32);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('reference_type', 40)->nullable();
            $table->string('reference_id', 100)->nullable();
            $table->string('idempotency_key', 160)->unique();
            $table->timestamp('effective_at');
            $table->timestamps();
            $table->index(['ledger_account_id', 'effective_at']);
        });

        Schema::create('fund_holds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ledger_entry_id')->constrained('creator_ledger_entries')->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->timestamp('release_at')->index();
            $table->string('status', 20)->default('pending')->index();
            $table->string('reason', 80)->default('refund_dispute_window');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
        });

        Schema::create('paypal_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_event_id', 128)->unique();
            $table->string('event_type', 120);
            $table->string('resource_id', 128)->nullable();
            $table->json('payload');
            $table->string('processing_status', 20)->default('received')->index();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paypal_webhook_events');
        Schema::dropIfExists('fund_holds');
        Schema::dropIfExists('creator_ledger_entries');
        Schema::dropIfExists('creator_ledger_accounts');
        Schema::dropIfExists('media_access_grants');
        Schema::dropIfExists('payment_transactions');
    }
};
