<?php

use Voyager\Database\Migrations\Migration;
use Voyager\Database\Schema\Blueprint;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        app('db.schema')->create('mcp_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index();
            // set for a token the built-in authorization server issued: the client, and the grant its code started
            $table->string('client_id')->nullable()->index();
            $table->string('grant_id', 32)->nullable()->index();
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->json('abilities');
            $table->string('resource');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        app('db.schema')->dropIfExists('mcp_access_tokens');
    }
};
