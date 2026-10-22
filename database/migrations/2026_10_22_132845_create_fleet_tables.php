<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Parc automobile : véhicules, sorties (missions, courses, déplacements), incidents et entretiens.
     */
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('plate', 30)->unique();
            $table->string('brand', 100);
            $table->string('model', 100);
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('chassis', 100)->nullable();
            $table->string('status', 20)->default('disponible');
            $table->unsignedInteger('mileage')->default(0);
            // Prochain entretien prévu (au premier des deux atteint).
            $table->unsignedInteger('next_service_km')->nullable();
            $table->date('next_service_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicle_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->text('purpose')->nullable();
            $table->string('destination')->nullable();
            $table->string('manager_name');
            $table->string('driver_name');
            $table->foreignId('direction_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('departed_at');
            $table->dateTime('expected_return_at')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->unsignedInteger('km_out');
            $table->unsignedInteger('km_in')->nullable();
            $table->text('return_notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['vehicle_id', 'returned_at']);
        });

        Schema::create('vehicle_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trip_id')->nullable()->constrained('vehicle_trips')->nullOnDelete();
            $table->string('type', 30);
            $table->date('occurred_on');
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicle_maintenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->json('types');
            $table->date('done_on');
            $table->unsignedInteger('mileage');
            $table->unsignedBigInteger('cost')->default(0);
            $table->string('garage')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_maintenances');
        Schema::dropIfExists('vehicle_incidents');
        Schema::dropIfExists('vehicle_trips');
        Schema::dropIfExists('vehicles');
    }
};
