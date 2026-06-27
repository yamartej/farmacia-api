<?php

namespace App\Http\Controllers;

use App\Models\Donacion;
use App\Models\DonacionItem;
use App\Models\SalidaItem;
use App\Services\InventarioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DonacionController extends Controller
{
    public function __construct(private readonly InventarioService $inventario)
    {
    }

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
            $donacion = Donacion::create([
                'donante'        => $validated['donante'],
                'tipo_donante'   => $validated['tipo_donante'] ?? null,
                'telefono'       => $validated['telefono'] ?? null,
                'fecha_donacion' => $validated['fecha_donacion'],
                'descripcion'    => $validated['descripcion'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {
                $donacionItem = DonacionItem::create([
                    'donacion_id'       => $donacion->id,
                    'medicamento_id'    => $item['medicamento_id'],
                    'cantidad'          => $item['cantidad'],
                    'lote'              => $item['lote'] ?? null,
                    'fecha_vencimiento' => $item['fecha_vencimiento'] ?? null,
                ]);

                $this->inventario->registrarEntrada(
                    medicamentoId: $donacionItem->medicamento_id,
                    cantidad: $donacionItem->cantidad,
                    fecha: $donacion->fecha_donacion,
                    origen: 'donacion',
                    origenId: $donacionItem->id,
                    descripcion: "Entrada por donación #{$donacion->id}" .
                        ($donacionItem->lote ? " - Lote {$donacionItem->lote}" : '')
                );
            }

            DB::commit();

            return response()->json([
                'message' => 'Donación registrada correctamente',
                'data'    => $donacion->load('items.medicamento'),
            ], 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
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
            'descripcion'    => 'sometimes|nullable|string',
        ]);

        $donacion->update($validated);

        return response()->json([
            'message' => 'Donación actualizada correctamente',
            'data'    => $donacion->fresh(),
        ]);
    }

    public function destroy($id)
    {
        $donacion = Donacion::with('items')->find($id);

        if (!$donacion) {
            return response()->json(['error' => 'Donación no encontrada'], 404);
        }

        $bloqueo = $this->validarEliminacionDonacion($donacion);

        if ($bloqueo) {
            return response()->json(['message' => $bloqueo], 409);
        }

        DB::beginTransaction();

        try {
            foreach ($donacion->items as $item) {
                $this->inventario->revertirEntrada(
                    medicamentoId: $item->medicamento_id,
                    cantidad: $item->cantidad,
                    fecha: now()->toDateString(),
                    origen: 'ajuste_donacion',
                    origenId: $item->id,
                    descripcion: "Reverso por eliminación de donación #{$donacion->id}"
                );
            }

            $donacion->items()->delete();
            $donacion->delete();

            DB::commit();

            return response()->json(['message' => 'Donación eliminada correctamente']);
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Error al eliminar la donación',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function actualizarItem(Request $request, $donacionId, $itemId)
    {
        $item = DonacionItem::where('donacion_id', $donacionId)
            ->where('id', $itemId)
            ->first();

        if (!$item) {
            return response()->json(['error' => 'Ítem no encontrado'], 404);
        }

        $donacion = Donacion::findOrFail($donacionId);

        $validated = $request->validate([
            'cantidad'          => 'required|integer|min:1',
            'medicamento_id'    => 'required|exists:medicamentos,id',
            'fecha_vencimiento' => 'nullable|date',
            'lote'              => 'nullable|string|max:255',
        ]);

        $bloqueo = $this->validarCambioItem(
            item: $item,
            nuevoMedicamentoId: (int) $validated['medicamento_id'],
            nuevaCantidad: (int) $validated['cantidad'],
            nuevoLote: $validated['lote'] ?? null,
            nuevaFechaVencimiento: $validated['fecha_vencimiento'] ?? null
        );

        if ($bloqueo) {
            return response()->json(['message' => $bloqueo], 409);
        }

        DB::beginTransaction();

        try {
            $this->inventario->revertirEntrada(
                medicamentoId: $item->medicamento_id,
                cantidad: $item->cantidad,
                fecha: now()->toDateString(),
                origen: 'ajuste_donacion',
                origenId: $item->id,
                descripcion: 'Reverso por edición de ítem en donación'
            );

            $item->update([
                'cantidad'          => $validated['cantidad'],
                'medicamento_id'    => $validated['medicamento_id'],
                'fecha_vencimiento' => $validated['fecha_vencimiento'] ?? null,
                'lote'              => $validated['lote'] ?? null,
            ]);

            $this->inventario->registrarEntrada(
                medicamentoId: $item->medicamento_id,
                cantidad: $item->cantidad,
                fecha: $donacion->fecha_donacion,
                origen: 'donacion',
                origenId: $item->id,
                descripcion: 'Entrada ajustada por edición de ítem en donación'
            );

            DB::commit();

            return response()->json([
                'message' => 'Ítem actualizado correctamente',
                'item' => $item->fresh()->load('medicamento'),
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function eliminarItem($donacionId, $itemId)
    {
        $item = DonacionItem::where('donacion_id', $donacionId)
            ->where('id', $itemId)
            ->first();

        if (!$item) {
            return response()->json(['error' => 'Ítem no encontrado'], 404);
        }

        $bloqueo = $this->validarCambioItem(
            item: $item,
            nuevoMedicamentoId: $item->medicamento_id,
            nuevaCantidad: 0,
            nuevoLote: $item->lote,
            nuevaFechaVencimiento: $item->fecha_vencimiento
        );

        if ($bloqueo) {
            return response()->json(['message' => $bloqueo], 409);
        }

        DB::beginTransaction();

        try {
            $this->inventario->revertirEntrada(
                medicamentoId: $item->medicamento_id,
                cantidad: $item->cantidad,
                fecha: now()->toDateString(),
                origen: 'ajuste_donacion',
                origenId: $item->id,
                descripcion: 'Reverso por eliminación de ítem en donación'
            );

            $item->delete();

            DB::commit();

            return response()->json(['message' => 'Ítem eliminado correctamente']);
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function agregarItem(Request $request, $donacionId)
    {
        $validated = $request->validate([
            'medicamento_id'    => 'required|exists:medicamentos,id',
            'cantidad'          => 'required|integer|min:1',
            'fecha_vencimiento' => 'nullable|date',
            'lote'              => 'nullable|string|max:255',
        ]);

        $donacion = Donacion::find($donacionId);

        if (!$donacion) {
            return response()->json(['error' => 'Donación no encontrada'], 404);
        }

        DB::beginTransaction();

        try {
            $item = DonacionItem::create([
                'donacion_id'       => $donacionId,
                'medicamento_id'    => $validated['medicamento_id'],
                'cantidad'          => $validated['cantidad'],
                'fecha_vencimiento' => $validated['fecha_vencimiento'] ?? null,
                'lote'              => $validated['lote'] ?? null,
            ]);

            $this->inventario->registrarEntrada(
                medicamentoId: $item->medicamento_id,
                cantidad: $item->cantidad,
                fecha: $donacion->fecha_donacion,
                origen: 'donacion',
                origenId: $item->id,
                descripcion: "Entrada por ítem agregado a donación #{$donacion->id}"
            );

            DB::commit();

            return response()->json([
                'message' => 'Ítem agregado correctamente',
                'item'    => $item->load('medicamento'),
            ]);
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function validarCambioItem(
        DonacionItem $item,
        int $nuevoMedicamentoId,
        int $nuevaCantidad,
        ?string $nuevoLote,
        ?string $nuevaFechaVencimiento
    ): ?string {
        $entradasSinItem = DonacionItem::where('id', '<>', $item->id)
            ->where('medicamento_id', $item->medicamento_id)
            ->where(function ($query) use ($item) {
                $this->aplicarFiltroLote($query, $item->lote);
            })
            ->where(function ($query) use ($item) {
                $this->aplicarFiltroFecha($query, $item->fecha_vencimiento);
            })
            ->sum('cantidad');

        $esMismoGrupo =
            $item->medicamento_id === $nuevoMedicamentoId &&
            ($item->lote ?? '') === ($nuevoLote ?? '') &&
            (string) $item->fecha_vencimiento === (string) $nuevaFechaVencimiento;

        $entradasDespues = (int) $entradasSinItem + ($esMismoGrupo ? $nuevaCantidad : 0);

        $salidas = SalidaItem::where('medicamento_id', $item->medicamento_id)
            ->where(function ($query) use ($item) {
                $this->aplicarFiltroLote($query, $item->lote);
            })
            ->where(function ($query) use ($item) {
                $this->aplicarFiltroFecha($query, $item->fecha_vencimiento);
            })
            ->sum('cantidad');

        if ((int) $salidas > $entradasDespues) {
            return 'No se puede modificar o eliminar este ítem porque ya existen salidas asociadas a ese medicamento, lote y fecha de vencimiento. La modificación dejaría el inventario sin respaldo.';
        }

        return null;
    }

    private function validarEliminacionDonacion(Donacion $donacion): ?string
    {
        $itemsAgrupados = $donacion->items
            ->groupBy(fn ($item) => $item->medicamento_id . '|' . ($item->lote ?? '') . '|' . (string) $item->fecha_vencimiento);

        foreach ($itemsAgrupados as $items) {
            $primerItem = $items->first();

            $cantidadDonacion = $items->sum('cantidad');

            $entradasTotales = DonacionItem::where('medicamento_id', $primerItem->medicamento_id)
                ->where(function ($query) use ($primerItem) {
                    $this->aplicarFiltroLote($query, $primerItem->lote);
                })
                ->where(function ($query) use ($primerItem) {
                    $this->aplicarFiltroFecha($query, $primerItem->fecha_vencimiento);
                })
                ->sum('cantidad');

            $entradasDespues = (int) $entradasTotales - (int) $cantidadDonacion;

            $salidas = SalidaItem::where('medicamento_id', $primerItem->medicamento_id)
                ->where(function ($query) use ($primerItem) {
                    $this->aplicarFiltroLote($query, $primerItem->lote);
                })
                ->where(function ($query) use ($primerItem) {
                    $this->aplicarFiltroFecha($query, $primerItem->fecha_vencimiento);
                })
                ->sum('cantidad');

            if ((int) $salidas > $entradasDespues) {
                return 'No se puede eliminar esta donación porque uno o más de sus lotes ya tienen salidas registradas. Eliminarla rompería la trazabilidad del inventario.';
            }
        }

        return null;
    }

    private function aplicarFiltroLote($query, ?string $lote): void
    {
        if ($lote === null || $lote === '') {
            $query->whereNull('lote')->orWhere('lote', '');
            return;
        }

        $query->where('lote', $lote);
    }

    private function aplicarFiltroFecha($query, $fecha): void
    {
        if ($fecha === null || $fecha === '') {
            $query->whereNull('fecha_vencimiento');
            return;
        }

        $query->whereDate('fecha_vencimiento', $fecha);
    }
}
