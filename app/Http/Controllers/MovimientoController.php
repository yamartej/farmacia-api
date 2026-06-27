<?php

namespace App\Http\Controllers;

use App\Models\Medicamento;
use App\Models\Movimiento;
use App\Services\InventarioService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MovimientoController extends Controller
{
    public function __construct(private readonly InventarioService $inventario)
    {
    }

    public function entrada(Request $request)
    {
        $validated = $request->validate([
            'medicamento_id' => 'required|exists:medicamentos,id',
            'cantidad'       => 'required|integer|min:1',
            'origen'         => 'nullable|string',
            'origen_id'      => 'nullable|integer',
            'descripcion'    => 'nullable|string',
            'fecha'          => 'nullable|date',
        ]);

        $this->inventario->registrarEntrada(
            medicamentoId: $validated['medicamento_id'],
            cantidad: $validated['cantidad'],
            fecha: $validated['fecha'] ?? now()->toDateString(),
            origen: $validated['origen'] ?? 'manual',
            origenId: $validated['origen_id'] ?? null,
            descripcion: $validated['descripcion'] ?? null
        );

        return response()->json(['message' => 'Entrada registrada con éxito']);
    }

    public function salida(Request $request)
    {
        $validated = $request->validate([
            'medicamento_id' => 'required|exists:medicamentos,id',
            'cantidad'       => 'required|integer|min:1',
            'origen'         => 'nullable|string',
            'origen_id'      => 'nullable|integer',
            'descripcion'    => 'nullable|string',
            'fecha'          => 'nullable|date',
        ]);

        try {
            $this->inventario->registrarSalida(
                medicamentoId: $validated['medicamento_id'],
                cantidad: $validated['cantidad'],
                fecha: $validated['fecha'] ?? now()->toDateString(),
                origen: $validated['origen'] ?? 'manual',
                origenId: $validated['origen_id'] ?? null,
                descripcion: $validated['descripcion'] ?? null
            );

            return response()->json(['message' => 'Salida registrada con éxito']);
        } catch (ValidationException $e) {
            throw $e;
        }
    }

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

    public function index()
    {
        $movimientos = Movimiento::with('medicamento')
            ->orderBy('fecha', 'desc')
            ->paginate(20);

        return response()->json($movimientos);
    }
}
