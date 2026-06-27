<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1️⃣ Eliminar lote de donaciones
        Schema::table('donaciones', function (Blueprint $table) {
            if (Schema::hasColumn('donaciones', 'lote')) {
                $table->dropColumn('lote');
            }
        });

        // 2️⃣ Asegurar columnas correctas en donacion_items
        Schema::table('donacion_items', function (Blueprint $table) {

            // Restaurar lote si no existe
            if (!Schema::hasColumn('donacion_items', 'lote')) {
                $table->string('lote')->nullable()->after('cantidad');
            }

            // Restaurar fecha de vencimiento si no existe
            if (!Schema::hasColumn('donacion_items', 'fecha_vencimiento')) {
                $table->date('fecha_vencimiento')->nullable()->after('lote');
            }

            // Quitar presentacion si aún existe
            if (Schema::hasColumn('donacion_items', 'presentacion')) {
                $table->dropColumn('presentacion');
            }
        });
    }

    public function down(): void
    {
        // Volver a colocar lote en donaciones
        Schema::table('donaciones', function (Blueprint $table) {
            $table->string('lote')->nullable();
        });

        // Revertir items
        Schema::table('donacion_items', function (Blueprint $table) {
            if (Schema::hasColumn('donacion_items', 'lote')) {
                $table->dropColumn('lote');
            }
            if (Schema::hasColumn('donacion_items', 'fecha_vencimiento')) {
                $table->dropColumn('fecha_vencimiento');
            }

            // Restaurar presentacion
            $table->string('presentacion')->nullable();
        });
    }
};
