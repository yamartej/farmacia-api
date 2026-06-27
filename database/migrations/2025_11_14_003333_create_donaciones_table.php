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
        Schema::create('donaciones', function (Blueprint $table) {
            $table->id();
            $table->string('donante');
            $table->string('tipo_donante')->nullable();
            $table->string('telefono')->nullable();
            $table->date('fecha_donacion');
            $table->text('descripcion')->nullable();
            $table->timestamps();
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('donaciones');
    }
};
