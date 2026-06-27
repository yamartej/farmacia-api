<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movimientos', function (Blueprint $table) {
            $table->id();

            // Relación con medicamento
            $table->foreignId('medicamento_id')
                ->constrained('medicamentos')
                ->onDelete('cascade');

            // Tipo de movimiento: entrada o salida
            $table->enum('tipo', ['entrada', 'salida']);

            // Cantidad de unidades involucradas
            $table->integer('cantidad');

            // Fecha en la que ocurrió el movimiento
            $table->date('fecha')->default(now());

            // Origen del movimiento (donación, compra, salida)
            $table->string('origen')->nullable();    // ejemplo: "donacion", "compra", "salida"
            $table->integer('origen_id')->nullable(); // id de la donación, compra o salida

            // Nota o descripción opcional
            $table->text('descripcion')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movimientos');
    }
};
