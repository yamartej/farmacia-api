<?php

namespace App\Http\Controllers;

use App\Models\Movimiento;
use App\Models\Medicamento;
use Illuminate\Http\Request;

class MovimientoController extends Controller
{
    /**
     * Registrar una ENTRADA
     */
    public function entrada(Request $request)
    {
        $request->validate([
            'medicamento_id' => 'required|exists:medicamentos,id',
            'cantidad' => 'required|integer|min:1',
            'origen' => 'nullable|string',
            'origen_id' => 'nullable|integer',
            'descripcion' => 'nullable|string',
            'fecha' => 'nullable|date',
        ]);

        Movimiento::create([
            'medicamento_id' => $request->medicamento_id,
            'tipo' => 'entrada',
            'cantidad' => $request->cantidad,
            'origen' => $request->origen ?? 'manual',
            'origen_id' => $request->origen_id,
            'descripcion' => $request->descripcion,
            'fecha' => $request->fecha ?? now(),   // usa tu columna fecha
        ]);

        return response()->json(['message' => 'Entrada registrada con éxito']);
    }

    /**
     * Registrar una SALIDA
     */
    public function salida(Request $request)
    {
        $request->validate([
            'medicamento_id' => 'required|exists:medicamentos,id',
            'cantidad' => 'required|integer|min:1',
            'origen' => 'nullable|string',
            'origen_id' => 'nullable|integer',
            'descripcion' => 'nullable|string',
            'fecha' => 'nullable|date',
        ]);

        $medicamento = Medicamento::findOrFail($request->medicamento_id);

        if ($medicamento->stock < $request->cantidad) {
            return response()->json([
                'message' => 'No hay suficiente stock para realizar la salida',
                'stock_actual' => $medicamento->stock
            ], 400);
        }

        Movimiento::create([
            'medicamento_id' => $request->medicamento_id,
            'tipo' => 'salida',
            'cantidad' => $request->cantidad,
            'origen' => $request->origen ?? 'manual',
            'origen_id' => $request->origen_id,
            'descripcion' => $request->descripcion,
            'fecha' => $request->fecha ?? now(),
        ]);

        return response()->json(['message' => 'Salida registrada con éxito']);
    }

    /**
     * Listar movimientos por medicamento (Kardex)
     */
    public function movimientosPorMedicamento($id)
    {
        $medicamento = Medicamento::findOrFail($id);

        $movimientos = $medicamento->movimientos()
            ->orderBy('fecha', 'desc')
            ->get();

        return response()->json([
            'medicamento' => $medicamento,
            'movimientos' => $movimientos,
        ]);
    }

    /**
     * Listar todos los movimientos del sistema
     */
    public function index()
    {
        $movimientos = Movimiento::with('medicamento')
            ->orderBy('fecha', 'desc')
            ->paginate(20);

        return response()->json($movimientos);
    }
}
