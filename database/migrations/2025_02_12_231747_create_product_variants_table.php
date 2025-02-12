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
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade')->index(); // Index on product_id
            $table->string('size')->nullable()->index();   // Index for faster size filtering
            $table->string('color')->nullable()->index();  // Index for color filtering
            $table->decimal('price', 10, 2)->nullable()->index(); // Index for price sorting
            $table->integer('stock')->default(0)->index(); // Index for stock filtering
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
