<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('title');
            $table->unsignedInteger('price');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // PostgreSQL ne connaît pas les entiers non signés : on protège le prix par une contrainte.
        DB::statement('ALTER TABLE services ADD CONSTRAINT services_price_positive CHECK (price > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
