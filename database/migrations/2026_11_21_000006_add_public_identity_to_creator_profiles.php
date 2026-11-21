<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('creator_profiles', function (Blueprint $table): void {
            $table->string('presentation', 180)->nullable()->after('public_name');
            $table->string('profile_photo_path', 1024)->nullable()->after('presentation');
            $table->string('bio', 280)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('creator_profiles', function (Blueprint $table): void {
            $table->text('bio')->nullable()->change();
            $table->dropColumn(['presentation', 'profile_photo_path']);
        });
    }
};
