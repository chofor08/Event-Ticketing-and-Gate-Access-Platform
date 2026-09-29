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
        Schema::create('gates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['event_id', 'name']);
        });

        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('unit_number');
            $table->string('credential_key_id', 32);
            $table->char('code_hash', 64)->unique();
            $table->string('status')->default('issued');
            $table->timestamp('admitted_at')->nullable();
            $table->foreignId('admitted_gate_id')->nullable()->constrained('gates')->nullOnDelete();
            $table->timestamp('admission_conflicted_at')->nullable();
            $table->timestamps();
            $table->unique(['order_item_id', 'unit_number']);
            $table->index(['event_id', 'status']);
            $table->index(['user_id', 'event_id']);
        });

        Schema::create('gate_staff_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->foreignId('invited_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['event_id', 'email']);
        });

        Schema::create('gate_staff_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('invitation_id')->nullable()->constrained('gate_staff_invitations')->nullOnDelete();
            $table->foreignId('assigned_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['event_id', 'user_id']);
        });

        Schema::create('gate_devices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('gate_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->timestamp('last_snapshot_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['gate_id', 'revoked_at']);
        });

        Schema::create('gate_device_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('device_id')->constrained('gate_devices')->restrictOnDelete();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->string('event_status');
            $table->char('payload_hash', 64);
            $table->string('signing_key_id', 32);
            $table->text('signature');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['device_id', 'expires_at']);
        });

        Schema::create('gate_device_snapshot_tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('gate_device_snapshots')->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained('tickets')->restrictOnDelete();
            $table->char('code_hash', 64);
            $table->string('ticket_status');
            $table->timestamps();
            $table->unique(['snapshot_id', 'ticket_id']);
            $table->unique(['snapshot_id', 'code_hash']);
        });

        Schema::create('scan_attempts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('attempt_id')->unique();
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->char('ticket_code_hash', 64)->nullable();
            $table->foreignId('event_id')->constrained()->restrictOnDelete();
            $table->foreignId('staff_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('gate_id')->constrained()->restrictOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('gate_devices')->nullOnDelete();
            $table->foreignId('snapshot_id')->nullable()->constrained('gate_device_snapshots')->nullOnDelete();
            $table->string('source');
            $table->string('outcome');
            $table->string('reported_outcome')->nullable();
            $table->string('reconciled_outcome')->nullable();
            $table->json('reconciliation_flags')->nullable();
            $table->timestamp('scanned_at');
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->index(['ticket_id', 'scanned_at']);
            $table->index(['event_id', 'scanned_at']);
            $table->index(['device_id', 'synced_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scan_attempts');
        Schema::dropIfExists('gate_device_snapshot_tickets');
        Schema::dropIfExists('gate_device_snapshots');
        Schema::dropIfExists('gate_devices');
        Schema::dropIfExists('gate_staff_assignments');
        Schema::dropIfExists('gate_staff_invitations');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('gates');
    }
};
