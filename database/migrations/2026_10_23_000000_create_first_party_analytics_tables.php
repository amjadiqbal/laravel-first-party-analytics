<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('first-party-analytics.database.connection');
    }

    public function up(): void
    {
        Schema::create(config('first-party-analytics.database.visits_table', 'analytics_visits'), function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->string('visitor', 64);
            $table->string('user_id', 64)->nullable()->index();
            $table->timestamp('started_at')->index();
            $table->string('landing_path', 2048);
            $table->string('referrer', 2048)->nullable();
            $table->string('referrer_host')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_term')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('browser', 40)->nullable();
            $table->string('os', 40)->nullable();
            $table->string('device', 16)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('ip', 45)->nullable();

            $table->index(['visitor', 'started_at']);
        });

        Schema::create(config('first-party-analytics.database.events_table', 'analytics_events'), function (Blueprint $table) {
            $table->id();
            $table->string('visit_token', 64)->nullable()->index();
            $table->string('user_id', 64)->nullable()->index();
            $table->string('name', 100);
            $table->string('path', 2048)->nullable();
            $table->json('properties')->nullable();
            $table->timestamp('created_at')->index();

            $table->index(['name', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('first-party-analytics.database.events_table', 'analytics_events'));
        Schema::dropIfExists(config('first-party-analytics.database.visits_table', 'analytics_visits'));
    }
};
