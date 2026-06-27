<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabla SALIDAS
        Schema::create('salidas', function (Blueprint $table) {
            $table->id();
            $table->string('responsable'); // persona responsable de la salida
            $table->string('tipo_salida'); // paciente, departamento, campaña, etc.
            $table->string('destino')->nullable();
            $table->date('fecha_salida');
            $table->text('descripcion')->nullable();
            $table->timestamps();
        });

        // Tabla SALIDA ITEMS
        Schema::create('salida_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salida_id')->constrained('salidas')->onDelete('cascade');
            $table->foreignId('medicamento_id')->constrained('medicamentos')->onDelete('cascade');

            // Selección del lote exacto entregado
            $table->string('lote');
            $table->date('fecha_vencimiento');

            $table->integer('cantidad');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salida_items');
        Schema::dropIfExists('salidas');
    }
};
