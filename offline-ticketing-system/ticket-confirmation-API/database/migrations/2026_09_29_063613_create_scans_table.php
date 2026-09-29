<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('scans', function (Blueprint $table) {
            $table->id();

            $table->foreignId('ticket_id')->nullable()
                ->constrained('tickets')
                ->nullOnDelete();

            $table->foreignId('staff_id')->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('event_id')->constrained('events')
                ->restrictOnDelete();

            $table->string('gate');
            $table->string('outcome');
            $table->timestamp('scanned_at');
            $table->timestamps();

            $table->index(['ticket_id', 'scanned_at']);
            $table->index(['event_id', 'scanned_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scans');
    }
};
