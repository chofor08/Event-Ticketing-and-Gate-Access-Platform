<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')
                ->constrained('orders')
                ->restrictOnDelete();

            $table->foreignId('order_item_id')
                ->constrained('order_items')
                ->restrictOnDelete();

            $table->foreignId('ticket_type_id')
                ->constrained('ticket_types')
                ->restrictOnDelete();

            $table->foreignId('event_id')
                ->constrained('events')
                ->restrictOnDelete();

            $table->foreignId('attendee_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->uuid('public_id')->unique();
            $table->string('credential')->unique();

            $table->enum('status', [
                'issued', 'admitted', 'cancelled', 'refunded',
            ])->default('issued');

            $table->timestamp('admitted_at')->nullable();
            $table->string('admitted_gate')->nullable();

            $table->timestamps();

            $table->index(['event_id', 'status']);
            $table->index(['attendee_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
