<?php

namespace App\Http\Controllers;

use App\Models\Salida;
use App\Models\SalidaItem;
use App\Services\InventarioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalidaController extends Controller
{
    public function __construct(private readonly InventarioService $inventario)
    {
    }

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

        return response()->json($salida);
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
            foreach ($validated['items'] as $item) {
                $this->inventario->validarStockSuficientePorLote(
                    medicamentoId: $item['medicamento_id'],
                    cantidad: $item['cantidad'],
                    lote: $item['lote'],
                    fechaVencimiento: $item['fecha_vencimiento']
                );
            }

            $salida = Salida::create([
                'responsable'   => $validated['responsable'],
                'tipo_salida'   => $validated['tipo_salida'],
                'destino'       => $validated['destino'] ?? null,
                'fecha_salida'  => $validated['fecha_salida'],
                'descripcion'   => $validated['descripcion'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {
                $salidaItem = SalidaItem::create([
                    'salida_id'         => $salida->id,
                    'medicamento_id'    => $item['medicamento_id'],
                    'lote'              => $item['lote'],
                    'fecha_vencimiento' => $item['fecha_vencimiento'],
                    'cantidad'          => $item['cantidad'],
                ]);

                $this->inventario->registrarSalida(
                    medicamentoId: $salidaItem->medicamento_id,
                    cantidad: $salidaItem->cantidad,
                    fecha: $salida->fecha_salida,
                    origen: 'salida',
                    origenId: $salidaItem->id,
                    descripcion: "Salida de medicamento por {$salida->tipo_salida} - Lote {$salidaItem->lote}"
                );
            }

            DB::commit();

            return response()->json([
                'message' => 'Salida registrada correctamente',
                'data' => $salida->load('items.medicamento'),
            ], 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Error al registrar salida',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        $salida = Salida::with('items')->find($id);

        if (!$salida) {
            return response()->json(['error' => 'Salida no encontrada'], 404);
        }

        DB::beginTransaction();

        try {
            foreach ($salida->items as $item) {
                $this->inventario->revertirSalida(
                    medicamentoId: $item->medicamento_id,
                    cantidad: $item->cantidad,
                    fecha: now()->toDateString(),
                    origen: 'ajuste_salida',
                    origenId: $item->id,
                    descripcion: "Reverso por eliminación de salida #{$salida->id}"
                );
            }

            $salida->items()->delete();
            $salida->delete();

            DB::commit();

            return response()->json(['message' => 'Salida eliminada correctamente']);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Error al eliminar la salida',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
