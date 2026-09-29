<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_types', function (Blueprint $table) {
            $table->renameColumn('price', 'base_price');
        });

        Schema::table('ticket_types', function (Blueprint $table) {
            $table->unsignedTinyInteger('discount')->default(0)->after('base_price');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_types', function (Blueprint $table) {
            $table->dropColumn('discount');
            $table->renameColumn('base_price', 'price');
        });
    }
};
