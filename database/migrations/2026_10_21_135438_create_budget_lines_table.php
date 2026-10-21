<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercice_id')->constrained()->restrictOnDelete();
            $table->text('label')->nullable();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 14, 2)->default(1);
            $table->decimal('frequency', 10, 2)->default(1);
            $table->decimal('duration', 10, 2)->default(1);
            $table->unsignedBigInteger('unit_cost')->default(0);
            // Montant en FCFA, recalculé à chaque enregistrement (quantité × fréquence × durée × coût unitaire).
            $table->unsignedBigInteger('amount')->default(0);
            $table->string('economic_nature', 150)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['exercice_id', 'activity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
    }
};
