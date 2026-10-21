<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rapportage : rapports attendus (obligations par type, période et direction),
     * rapports déposés avec leurs versions, publics des rapports consolidés.
     */
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercice_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->foreignId('periode_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('direction_id')->nullable()->constrained()->nullOnDelete();
            // Rapport consolidé par la coordination à partir des rapports des directions.
            $table->boolean('is_consolidated')->default(false);
            $table->string('title');
            $table->string('state', 30)->default('draft');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['exercice_id', 'type', 'periode_id']);
        });

        Schema::create('report_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('disk', 30);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['report_id', 'version']);
        });

        Schema::create('report_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercice_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->foreignId('periode_id')->constrained()->cascadeOnDelete();
            $table->foreignId('direction_id')->constrained()->cascadeOnDelete();
            $table->date('due_on');
            $table->timestamps();

            $table->unique(['exercice_id', 'type', 'periode_id', 'direction_id']);
        });

        Schema::create('report_audiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->string('role', 50)->nullable();
            $table->foreignId('direction_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_audiences');
        Schema::dropIfExists('report_obligations');
        Schema::dropIfExists('report_documents');
        Schema::dropIfExists('reports');
    }
};
