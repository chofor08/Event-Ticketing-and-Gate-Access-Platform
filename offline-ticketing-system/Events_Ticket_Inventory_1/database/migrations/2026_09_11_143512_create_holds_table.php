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
        Schema::create('holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_type_id')->constrained('ticket_types');
            $table->integer('quantity');
            $table->string('status');
            $table->string('token');
            $table->timestamp('expires_at');
            $table->timestamps();

            // $table->check('quantity > 0');
            // $table->check("status IN ('confirmed', 'held','expired', 'released')");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('holds');
    }
};
