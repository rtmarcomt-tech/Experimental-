<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('creator_payouts', function (Blueprint $table): void {
            $table->string('paypal_sender_item_id', 128)->nullable()->unique()->after('paypal_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('creator_payouts', function (Blueprint $table): void {
            $table->dropUnique(['paypal_sender_item_id']);
            $table->dropColumn('paypal_sender_item_id');
        });
    }
};
