<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('creator_verification_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('creator_verification_case_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 32);
            $table->string('disk', 40)->default('private');
            $table->string('object_key', 1024)->unique();
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('status', 20)->default('submitted')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['creator_verification_case_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_verification_documents');
    }
};
