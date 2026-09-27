<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Module-A event and ticket inventory tables.
        Schema::create('events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organizer_id')->constrained('users')->restrictOnDelete();
            $table->string('title');
            $table->string('venue');
            $table->string('town');
            $table->text('description')->nullable();
            $table->date('date');
            $table->time('start_time');
            $table->string('status')->default('draft');
            $table->timestamps();
            $table->index(['status', 'date']);
        });

        Schema::create('ticket_types', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('base_price_cents');
            $table->unsignedTinyInteger('discount')->default(0);
            $table->unsignedInteger('quantity');
            $table->unique(['event_id', 'name']);
            $table->timestamps();
        });

        Schema::create('holds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('ticket_type_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('status')->default('held');
            $table->string('token_hash');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['ticket_type_id', 'status', 'expires_at']);
            $table->index(['user_id', 'status', 'expires_at']);
        });

        // Module-B payment records adapted to hold-backed ticket purchases.
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('payment_intent_id')->nullable()->unique();
            $table->string('session_id')->nullable()->unique();
            $table->string('currency', 3)->default('usd');
            $table->string('payment_method_type')->nullable();
            $table->string('payment_method_id')->nullable();
            $table->string('payment_method_brand')->nullable();
            $table->char('payment_method_last4', 4)->nullable();
            $table->json('payment_method_details')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('amount_cents');
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ticket_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('hold_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_cents');
            $table->unsignedBigInteger('sub_total_cents');
            $table->timestamps();
        });

        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->string('reference_key')->unique();
            $table->bigInteger('amount_cents');
            $table->decimal('payment', 8, 2)->default(0);
            $table->decimal('refund', 8, 2)->default(0);
            $table->decimal('adjustment', 8, 2)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        // Persist scoped refund attempts so partial refunds can be retried safely.
        Schema::create('refund_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('event_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reason');
            $table->json('order_item_ids');
            $table->unsignedBigInteger('amount_cents');
            $table->string('idempotency_key')->unique();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('stripe_refund_id')->nullable()->unique();
            $table->string('status')->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
        });

        // Deduplicate attendee checkout requests.
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('status')->default('processing');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'key']);
        });

        // Deduplicate Stripe webhook deliveries and track retry state.
        Schema::create('stripe_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('stripe_event_id')->unique();
            $table->string('type');
            $table->string('status')->default('received');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('refund_requests');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('holds');
        Schema::dropIfExists('ticket_types');
        Schema::dropIfExists('events');
    }
};
