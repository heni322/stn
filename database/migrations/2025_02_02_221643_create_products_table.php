<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 8, 3);
            $table->string('source_url')->nullable(); // URL of the product on the external site
            $table->foreignId('site_id')->nullable()->constrained()->onDelete('cascade'); // Relationship with Site
            $table->foreignId('category_id')->nullable()->constrained('categories')->onDelete('cascade'); // Self-referencing relationship for category hierarchyonship with Category
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
