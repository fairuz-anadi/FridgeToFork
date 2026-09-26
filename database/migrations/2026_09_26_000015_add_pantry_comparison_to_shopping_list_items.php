<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generated shopping-list rows now record what the fridge already holds, so
 * the list can show "you have 200 g, buy 300 g" instead of silently dropping
 * anything that is in the fridge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shopping_list_items', function (Blueprint $table) {
            $table->decimal('needed_quantity', 10, 2)->nullable()->after('unit');
            $table->decimal('pantry_quantity', 10, 2)->nullable()->after('needed_quantity');
            $table->string('pantry_unit')->nullable()->after('pantry_quantity');
            // null = not in the fridge | partial | covered | check (amount unknown)
            $table->string('pantry_status', 12)->nullable()->after('pantry_unit');
        });
    }

    public function down(): void
    {
        Schema::table('shopping_list_items', function (Blueprint $table) {
            $table->dropColumn(['needed_quantity', 'pantry_quantity', 'pantry_unit', 'pantry_status']);
        });
    }
};
