<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the starter kit's users migration.
 *
 * Differences from the kit, all required by the spec:
 *   - no `password` column (R1.1: Google only, no passwords)
 *   - no `email_verified_at` (Google has already verified it)
 *   - no `password_reset_tokens` table at all (R1.2)
 *   - adds `google_id` and `avatar_url`
 *
 * Phase 2 adds the remaining six tables. Only `users` lives here, because
 * Phase 1's auth cannot be exercised without it.
 *
 * Portability (R0.4): plain column types only — this has to migrate on
 * Postgres and on SQLite in-memory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('google_id')->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('avatar_url')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        // SESSION_DRIVER=database: file sessions break across Railway
        // redeploys and replicas.
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
