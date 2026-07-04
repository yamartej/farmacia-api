<?php

namespace App\Http\Controllers;

use App\Models\Donacion;
use App\Models\Medicamento;
use App\Models\Movimiento;
use App\Models\Salida;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function stats()
    {
        $entradas = (int) Movimiento::where('tipo', 'entrada')->sum('cantidad');
        $salidas = (int) Movimiento::where('tipo', 'salida')->sum('cantidad');

        $stockBajoSubquery = "
            (
                (
                    SELECT COALESCE(SUM(m1.cantidad), 0)
                    FROM movimientos m1
                    WHERE m1.medicamento_id = medicamentos.id
                    AND m1.tipo = 'entrada'
                )
                -
                (
                    SELECT COALESCE(SUM(m2.cantidad), 0)
                    FROM movimientos m2
                    WHERE m2.medicamento_id = medicamentos.id
                    AND m2.tipo = 'salida'
                )
            ) <= 10
        ";

        return response()->json([
            'medicamentos_total' => Medicamento::count(),
            'donaciones_total' => Donacion::count(),
            'salidas_total' => Salida::count(),
            'movimientos_total' => Movimiento::count(),
            'stock_total' => $entradas - $salidas,
            'entradas_total' => $entradas,
            'salidas_unidades_total' => $salidas,
            'stock_bajo_total' => Medicamento::whereRaw($stockBajoSubquery)->count(),
            'vencimientos_proximos_total' => DB::table('donacion_items')
                ->whereNotNull('fecha_vencimiento')
                ->whereDate('fecha_vencimiento', '>=', now())
                ->whereDate('fecha_vencimiento', '<=', now()->addDays(30))
                ->count(),
            'ultimos_movimientos' => Movimiento::with('medicamento:id,nombre')
                ->orderBy('fecha', 'desc')
                ->orderBy('id', 'desc')
                ->take(5)
                ->get([
                    'id',
                    'medicamento_id',
                    'tipo',
                    'cantidad',
                    'fecha',
                    'origen',
                    'descripcion',
                ]),
        ]);
    }
}
