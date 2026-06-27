<?php

namespace App\Http\Controllers;

use App\Models\Salida;
use App\Models\SalidaItem;
use App\Models\Movimiento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SalidaController extends Controller
{
    public function index(Request $request)
    {
        return Salida::withCount('items')
            ->orderBy('fecha_salida', 'desc')
            ->paginate(10);
    }

    public function show($id)
    {
        $salida = Salida::with('items.medicamento')->find($id);

        if (!$salida) {
            return response()->json(['error' => 'Salida no encontrada'], 404);
        }

        return $salida;
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'responsable'   => 'required|string|max:255',
            'tipo_salida'   => 'required|string|max:255',
            'destino'       => 'nullable|string|max:255',
            'fecha_salida'  => 'required|date',
            'descripcion'   => 'nullable|string',

            'items'                     => 'required|array|min:1',
            'items.*.medicamento_id'    => 'required|exists:medicamentos,id',
            'items.*.lote'              => 'required|string|max:255',
            'items.*.fecha_vencimiento' => 'required|date',
            'items.*.cantidad'          => 'required|integer|min:1',
        ]);

        DB::beginTransaction();

        try {
            $salida = Salida::create([
                'responsable'   => $validated['responsable'],
                'tipo_salida'   => $validated['tipo_salida'],
                'destino'       => $validated['destino'] ?? null,
                'fecha_salida'  => $validated['fecha_salida'],
                'descripcion'   => $validated['descripcion'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {

                // Crear el item de salida
                $salidaItem = SalidaItem::create([
                    'salida_id'        => $salida->id,
                    'medicamento_id'   => $item['medicamento_id'],
                    'lote'             => $item['lote'],
                    'fecha_vencimiento' => $item['fecha_vencimiento'],
                    'cantidad'         => $item['cantidad'],
                ]);

                // Crear movimiento (inventario)
                Movimiento::create([
                    'medicamento_id' => $item['medicamento_id'],
                    'tipo'           => 'salida',
                    'cantidad'       => $item['cantidad'],
                    'fecha'          => $validated['fecha_salida'],
                    'origen'         => 'salida',
                    'origen_id'      => $salidaItem->id,
                    'descripcion'    => "Salida de medicamento (lote {$item['lote']})",
                ]);
            }

            DB::commit();
            return response()->json(['message' => 'Salida registrada correctamente', 'data' => $salida->load('items')], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error al registrar salida', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        $salida = Salida::find($id);

        if (!$salida) {
            return response()->json(['error' => 'Salida no encontrada'], 404);
        }

        // Eliminar movimientos
        Movimiento::where('origen', 'salida')
            ->where('origen_id', $id)
            ->delete();

        $salida->delete();

        return response()->json(['message' => 'Salida eliminada']);
    }
}
