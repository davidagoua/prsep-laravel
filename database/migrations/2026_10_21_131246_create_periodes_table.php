<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exercice_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->unsignedTinyInteger('number');
            $table->string('label');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();

            $table->unique(['exercice_id', 'type', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('periodes');
    }
};
