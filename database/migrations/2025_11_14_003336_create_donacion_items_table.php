<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::create('donacion_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('donacion_id')->constrained('donaciones')->onDelete('cascade');
            $table->foreignId('medicamento_id')->constrained('medicamentos')->onDelete('cascade');
            $table->integer('cantidad');
            $table->string('presentacion')->nullable();
            $table->string('lote')->nullable();
            $table->date('fecha_vencimiento')->nullable();
            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('donacion_items');
    }
};
