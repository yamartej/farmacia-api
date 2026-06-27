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
        // Agregar lote en DONACIONES
        Schema::table('donaciones', function (Blueprint $table) {
            $table->string('lote')->nullable()->after('fecha_donacion');
        });

        // Eliminar columnas innecesarias del ITEM
        Schema::table('donacion_items', function (Blueprint $table) {
            if (Schema::hasColumn('donacion_items', 'presentacion')) {
                $table->dropColumn('presentacion');
            }
            if (Schema::hasColumn('donacion_items', 'lote')) {
                $table->dropColumn('lote');
            }
            // Asegurar fecha de vencimiento
            if (!Schema::hasColumn('donacion_items', 'fecha_vencimiento')) {
                $table->date('fecha_vencimiento')->nullable()->after('cantidad');
            }
        });
    }

    public function down(): void
    {
        Schema::table('donaciones', function (Blueprint $table) {
            $table->dropColumn('lote');
        });

        Schema::table('donacion_items', function (Blueprint $table) {
            $table->string('presentacion')->nullable();
            $table->string('lote')->nullable();
            if (Schema::hasColumn('donacion_items', 'fecha_vencimiento')) {
                $table->dropColumn('fecha_vencimiento');
            }
        });
    }
};
