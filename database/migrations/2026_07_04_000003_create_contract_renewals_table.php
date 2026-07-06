<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_renewals', function (Blueprint $table) {
            $table->id();
            // contracts.id is a UUID PK, so this FK is UUID too.
            $table->foreignUuid('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->date('previous_end_date')->nullable();
            $table->bigInteger('previous_rent_amount');
            $table->date('new_end_date');
            $table->bigInteger('new_rent_amount');
            $table->text('note')->nullable();
            $table->timestamps();

            // Newest-first lookups per contract.
            $table->index(['contract_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_renewals');
    }
};
