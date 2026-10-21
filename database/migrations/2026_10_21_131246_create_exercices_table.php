<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercices', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year')->unique();
            $table->string('label');
            $table->date('starts_on');
            $table->date('ends_on');
            // Montants en FCFA (pas de décimales).
            $table->unsignedBigInteger('budget_total')->default(0);
            $table->string('status', 20)->default('en_preparation');
            $table->boolean('is_current')->default(false);
            $table->timestamps();
        });

        // Un seul exercice courant à la fois (index partiel, PostgreSQL et SQLite).
        DB::statement('CREATE UNIQUE INDEX exercices_single_current ON exercices (is_current) WHERE is_current');
    }

    public function down(): void
    {
        Schema::dropIfExists('exercices');
    }
};
