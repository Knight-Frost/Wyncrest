<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_landlord_notes', function (Blueprint $table) {
            $table->id();
            // contracts.id is a UUID PK, so this FK is UUID too.
            $table->foreignUuid('contract_id')->constrained('contracts')->cascadeOnDelete();
            // Author is always the landlord who owns the contract — unlike
            // ContractNote (admin-only), these notes ARE landlord-visible.
            $table->foreignId('landlord_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            // Newest-first lookups per contract.
            $table->index(['contract_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_landlord_notes');
    }
};
