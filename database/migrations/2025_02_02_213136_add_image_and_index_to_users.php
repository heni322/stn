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
        Schema::table('users', function (Blueprint $table) {
            // Drop the 'name' column
            $table->dropColumn('name');

            // Add first_name and last_name
            $table->string('first_name')->nullable()->after('id');
            $table->string('last_name')->nullable()->after('first_name');

            // Add image column
            $table->string('image')->nullable()->after('password');

            // Create indexes
            $table->index('first_name');
            $table->index('last_name');
            $table->index('image');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop indexes
            $table->dropIndex(['first_name']);
            $table->dropIndex(['last_name']);
            $table->dropIndex(['image']);

            // Drop new columns
            $table->dropColumn('first_name');
            $table->dropColumn('last_name');
            $table->dropColumn('image');

            // Re-add the name column
            $table->string('name')->after('id');
        });
    }
};
