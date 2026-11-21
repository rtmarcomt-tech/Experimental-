<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('media_rights_declarations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('digital_media_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creator_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('accepted_by')->constrained('users')->restrictOnDelete();
            $table->string('rights_basis', 24);
            $table->string('terms_version', 32);
            $table->timestamp('accepted_at');
            $table->timestamps();
            $table->index(['digital_media_id', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_rights_declarations');
    }
};
