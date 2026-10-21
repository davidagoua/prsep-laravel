<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exécution financière : engagements et paiements par activité, regroupement des paiements
     * dans des demandes de remboursement de fonds (DRF). Montants en FCFA.
     */
    public function up(): void
    {
        Schema::create('engagements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercice_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->date('engaged_on');
            $table->string('reference', 100)->nullable();
            $table->string('supplier')->nullable();
            $table->string('label')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['exercice_id', 'activity_id']);
        });

        Schema::create('drfs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercice_id')->constrained()->restrictOnDelete();
            $table->string('number', 50);
            $table->date('date');
            $table->string('label')->nullable();
            $table->string('status', 20)->default('brouillon');
            $table->date('submitted_on')->nullable();
            $table->date('reimbursed_on')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['exercice_id', 'number']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exercice_id')->constrained()->restrictOnDelete();
            $table->foreignId('engagement_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('drf_id')->nullable()->constrained('drfs')->nullOnDelete();
            $table->unsignedBigInteger('amount');
            $table->date('paid_on');
            $table->string('reference', 100)->nullable();
            $table->string('label')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['exercice_id', 'activity_id']);
            $table->index('drf_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('drfs');
        Schema::dropIfExists('engagements');
    }
};
