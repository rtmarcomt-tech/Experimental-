<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('media_download_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('media_access_grant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('media_asset_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('outcome', 24);
            $table->char('ip_hash', 64)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->timestamp('issued_at');
            $table->timestamps();
            $table->index(['media_access_grant_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_download_events');
    }
};
