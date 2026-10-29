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
        // clients that registered themselves (RFC 7591); a client metadata document's client is its URL, never stored
        app('db.schema')->create('mcp_oauth_clients', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name')->nullable();
            $table->string('secret', 64)->nullable();
            $table->json('redirect_uris');
            $table->json('grant_types');
            $table->timestamps();
        });

        app('db.schema')->create('mcp_authorization_codes', function (Blueprint $table) {
            $table->string('code', 64)->primary();
            $table->string('grant_id', 32)->index();
            $table->string('client_id');
            $table->foreignId('user_id');
            $table->text('redirect_uri');
            $table->string('code_challenge', 128);
            $table->json('abilities');
            $table->string('resource');
            // whether the client may refresh: a metadata-document client is not fetched again at the token endpoint
            $table->boolean('refreshable');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        app('db.schema')->create('mcp_refresh_tokens', function (Blueprint $table) {
            $table->string('token', 64)->primary();
            $table->string('grant_id', 32)->index();
            $table->string('client_id');
            $table->foreignId('user_id');
            $table->json('abilities');
            $table->string('resource');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        app('db.schema')->dropIfExists('mcp_oauth_clients');
        app('db.schema')->dropIfExists('mcp_authorization_codes');
        app('db.schema')->dropIfExists('mcp_refresh_tokens');
    }
};
