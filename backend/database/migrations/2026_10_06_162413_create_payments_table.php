<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->foreignId('service_request_id')->constrained()->restrictOnDelete();
            $table->string('phone_number', 10);
            $table->string('operator', 10);
            $table->unsignedInteger('amount');
            $table->string('provider_payment_id', 64)->nullable()->unique();
            $table->string('status', 20);
            $table->string('failure_reason', 50)->nullable();
            $table->timestamp('status_at');
            $table->timestamps();

            $table->index(['status', 'status_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payments
                ADD CONSTRAINT payments_amount_positive CHECK (amount > 0),
                ADD CONSTRAINT payments_phone_number_format CHECK (phone_number ~ '^01[0-9]{8}$'),
                ADD CONSTRAINT payments_operator_known CHECK (operator IN ('MTN', 'MOOV', 'CELTIIS'))
            SQL);

        // Un seul paiement en cours par demande, garanti par la base (index unique partiel).
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX payments_one_pending_per_service_request
                ON payments (service_request_id)
                WHERE status = 'PENDING'
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
