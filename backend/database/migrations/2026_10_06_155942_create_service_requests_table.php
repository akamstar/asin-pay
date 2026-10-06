<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_requests', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('unit_price');
            $table->unsignedSmallInteger('quantity');
            $table->unsignedInteger('fees');
            $table->unsignedInteger('amount');
            $table->string('status', 30)->index();
            $table->timestamp('status_at');
            $table->timestamps();
        });

        // Garde-fous en base : le montant reste cohérent même en cas d'écriture hors application.
        DB::statement(<<<'SQL'
            ALTER TABLE service_requests
                ADD CONSTRAINT service_requests_quantity_positive CHECK (quantity > 0),
                ADD CONSTRAINT service_requests_amounts_positive CHECK (unit_price > 0 AND fees >= 0),
                ADD CONSTRAINT service_requests_amount_consistent CHECK (amount = unit_price * quantity + fees)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('service_requests');
    }
};
