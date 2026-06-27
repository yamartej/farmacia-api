<?php

namespace App\Http\Controllers;

use App\Models\Donacion;
use App\Models\DonacionItem;
use App\Models\Movimiento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DonacionController extends Controller
{
    public function index(Request $request)
    {
        $query = Donacion::query()->withCount('items');

        if (!empty($request->search)) {
            $term = '%' . $request->search . '%';
            $query->where(function ($q) use ($term) {
                $q->where('donante', 'like', $term)
                    ->orWhere('tipo_donante', 'like', $term);
            });
        }

        if (!empty($request->fecha_desde)) {
            $query->whereDate('fecha_donacion', '>=', $request->fecha_desde);
        }

        if (!empty($request->fecha_hasta)) {
            $query->whereDate('fecha_donacion', '<=', $request->fecha_hasta);
        }

        return $query->orderBy('fecha_donacion', 'desc')->paginate(10);
    }

    public function show($id)
    {
        $donacion = Donacion::with('items.medicamento')->find($id);

        if (!$donacion) {
            return response()->json(['error' => 'Donación no encontrada'], 404);
        }

        return response()->json($donacion);
    }


    public function store(Request $request)
    {
        $validated = $request->validate([
            'donante'        => 'required|string|max:255',
            'tipo_donante'   => 'nullable|string|max:255',
            'telefono'       => 'nullable|string|max:50',
            'fecha_donacion' => 'required|date',
            'descripcion'    => 'nullable|string',

            'items'                     => 'required|array|min:1',
            'items.*.medicamento_id'    => 'required|exists:medicamentos,id',
            'items.*.cantidad'          => 'required|integer|min:1',
            'items.*.fecha_vencimiento' => 'nullable|date',
            'items.*.lote'              => 'nullable|string|max:255',
        ]);

        DB::beginTransaction();

        try {
            // 🟦 Crear la donación
            $donacion = Donacion::create([
                'donante'        => $validated['donante'],
                'tipo_donante'   => $validated['tipo_donante'] ?? null,
                'telefono'       => $validated['telefono'] ?? null,
                'fecha_donacion' => $validated['fecha_donacion'],
                'descripcion'    => $validated['descripcion'] ?? null,
            ]);

            // 🟩 Agregar cada ítem
            foreach ($validated['items'] as $item) {
                DonacionItem::create([
                    'donacion_id'      => $donacion->id,
                    'medicamento_id'   => $item['medicamento_id'],
                    'cantidad'         => $item['cantidad'],
                    'lote'             => $item['lote'] ?? null,
                    'fecha_vencimiento' => $item['fecha_vencimiento'] ?? null,
                ]);
            }


            DB::commit();

            return response()->json([
                'message' => 'Donación registrada correctamente',
                'data'    => $donacion->load('items.medicamento'),
            ], 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al registrar la donación',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }



    public function update(Request $request, $id)
    {
        $donacion = Donacion::find($id);

        if (!$donacion) {
            return response()->json(['error' => 'Donación no encontrada'], 404);
        }

        $validated = $request->validate([
            'donante'        => 'sometimes|string|max:255',
            'tipo_donante'   => 'sometimes|nullable|string|max:255',
            'telefono'       => 'sometimes|nullable|string|max:50',
            'fecha_donacion' => 'sometimes|date',
            'lote'           => 'sometimes|nullable|string|max:255',
            'descripcion'    => 'sometimes|nullable|string',
        ]);

        $donacion->update($validated);


        $donacion->update($validated);

        return response()->json([
            'message' => 'Donación actualizada correctamente',
            'data'    => $donacion,
        ]);
    }


    public function destroy($id)
    {
        $donacion = Donacion::find($id);

        if (!$donacion) {
            return response()->json(['error' => 'Donación no encontrada'], 404);
        }

        $donacion->delete();

        return response()->json(['message' => 'Donación eliminada correctamente']);
    }


    public function actualizarItem(Request $request, $donacionId, $itemId)
    {
        $item = DonacionItem::where("donacion_id", $donacionId)
            ->where("id", $itemId)
            ->first();

        if (!$item) {
            return response()->json(["error" => "Ítem no encontrado"], 404);
        }

        $donacion = Donacion::findOrFail($donacionId);

        $request->validate([
            "cantidad" => "required|integer|min:1",
            "medicamento_id" => "required|exists:medicamentos,id"
        ]);

        DB::beginTransaction();

        try {
            // 1️⃣ Revertir movimiento anterior
            Movimiento::create([
                'medicamento_id' => $item->medicamento_id,
                'tipo' => 'salida',
                'cantidad' => $item->cantidad,
                'fecha' => $donacion->fecha_donacion,
                'origen' => 'ajuste',
                'origen_id' => $donacion->id,
                'descripcion' => 'Reverso por edición de item en donación',
            ]);

            // 2️⃣ Actualizar item
            $item->update([
                "cantidad" => $request->cantidad,
                "medicamento_id" => $request->medicamento_id
            ]);

            // 3️⃣ Registrar nuevo movimiento
            Movimiento::create([
                'medicamento_id' => $request->medicamento_id,
                'tipo' => 'entrada',
                'cantidad' => $request->cantidad,
                'fecha' => $donacion->fecha_donacion,
                'origen' => 'donacion',
                'origen_id' => $donacion->id,
                'descripcion' => 'Entrada ajustada por edición de item',
            ]);

            DB::commit();

            return response()->json(["message" => "Ítem actualizado", "item" => $item]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(["error" => $e->getMessage()], 500);
        }
    }


    public function eliminarItem($donacionId, $itemId)
    {
        $item = DonacionItem::where("donacion_id", $donacionId)
            ->where("id", $itemId)
            ->first();

        if (!$item) {
            return response()->json(["error" => "Ítem no encontrado"], 404);
        }

        $donacion = Donacion::findOrFail($donacionId);

        DB::beginTransaction();

        try {
            // 1️⃣ Revertir el movimiento original
            Movimiento::create([
                'medicamento_id' => $item->medicamento_id,
                'tipo' => 'salida',
                'cantidad' => $item->cantidad,
                'fecha' => $donacion->fecha_donacion,
                'origen' => 'donacion',
                'origen_id' => $donacion->id,
                'descripcion' => 'Salida por eliminación de item de donación',
            ]);

            // 2️⃣ Eliminar item
            $item->delete();

            DB::commit();

            return response()->json(["message" => "Ítem eliminado correctamente"]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(["error" => $e->getMessage()], 500);
        }
    }


    public function agregarItem(Request $request, $donacionId)
    {
        $request->validate([
            'medicamento_id' => 'required|exists:medicamentos,id',
            'cantidad'       => 'required|integer|min:1',
            'fecha_vencimiento' => 'nullable|date',
            'lote'              => 'nullable|string|max:255',
        ]);

        $donacion = Donacion::find($donacionId);
        if (!$donacion) {
            return response()->json(['error' => 'Donación no encontrada'], 404);
        }

        $item = DonacionItem::create([
            'donacion_id'      => $donacionId,
            'medicamento_id'   => $request->medicamento_id,
            'cantidad'         => $request->cantidad,
            'fecha_vencimiento' => $request->fecha_vencimiento,
            'lote'              => $request->lote,
        ]);

        return response()->json([
            'message' => 'Ítem agregado correctamente',
            'item'    => $item->load('medicamento')
        ]);
    }
}
