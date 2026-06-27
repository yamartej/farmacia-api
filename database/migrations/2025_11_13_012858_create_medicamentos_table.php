<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('medicamentos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('presentacion')->nullable();    // ej: tabletas, jarabe
            $table->string('categoria')->nullable();       // analgésico, antibiótico, etc.
            $table->string('unidad')->nullable();          // cajas, frascos
            $table->integer('cantidad')->default(0);
            $table->date('fecha_vencimiento')->nullable();
            $table->text('descripcion')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medicamentos');
    }
};
