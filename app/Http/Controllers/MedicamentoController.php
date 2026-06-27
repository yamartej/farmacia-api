<?php

namespace App\Http\Controllers;

use App\Models\DonacionItem;
use App\Models\Medicamento;
use App\Models\Movimiento;
use App\Models\SalidaItem;
use Illuminate\Http\Request;

class MedicamentoController extends Controller
{
    public function index(Request $request)
    {
        $query = Medicamento::query();

        if (!empty($request->search)) {
            $query->where(function ($q) use ($request) {
                $term = '%' . $request->search . '%';
                $q->where('nombre', 'like', $term)
                    ->orWhere('categoria', 'like', $term)
                    ->orWhere('presentacion', 'like', $term);
            });
        }

        if (!empty($request->categoria)) {
            $query->where('categoria', 'like', '%' . $request->categoria . '%');
        }

        if (!empty($request->unidad)) {
            $query->where('unidad', 'like', '%' . $request->unidad . '%');
        }

        if ($request->vencimiento === 'proximo') {
            $query->whereDate('fecha_vencimiento', '<=', now()->addDays(30));
        }

        if ($request->stock === 'bajo') {
            $query->whereRaw("
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
            ");
        }

        return $query->orderBy('nombre')->paginate(10);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre'       => 'required|string|max:255',
            'presentacion' => 'required|string|max:255',
            'categoria'    => 'required|string|max:255',
            'unidad'       => 'required|string|max:255',
            'descripcion'  => 'nullable|string|max:500',
        ]);

        $medicamento = Medicamento::create($validated);

        return response()->json([
            'message' => 'Medicamento creado correctamente',
            'data' => $medicamento,
        ], 201);
    }

    public function show($id)
    {
        return Medicamento::findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $medicamento = Medicamento::findOrFail($id);

        $data = $request->validate([
            'nombre'            => 'required|string|max:255',
            'presentacion'      => 'nullable|string|max:255',
            'categoria'         => 'nullable|string|max:255',
            'unidad'            => 'nullable|string|max:255',
            'fecha_vencimiento' => 'nullable|date',
            'descripcion'       => 'nullable|string|max:500',
        ]);

        $medicamento->update($data);

        return response()->json([
            'message' => 'Medicamento actualizado correctamente',
            'data' => $medicamento->fresh(),
        ]);
    }

    public function destroy($id)
    {
        $medicamento = Medicamento::find($id);

        if (!$medicamento) {
            return response()->json([
                'message' => 'Medicamento no encontrado',
            ], 404);
        }

        $tieneMovimientos = Movimiento::where('medicamento_id', $id)->exists();
        $tieneDonaciones = DonacionItem::where('medicamento_id', $id)->exists();
        $tieneSalidas = SalidaItem::where('medicamento_id', $id)->exists();

        if ($tieneMovimientos || $tieneDonaciones || $tieneSalidas) {
            return response()->json([
                'message' => 'No se puede eliminar este medicamento porque tiene historial de inventario, donaciones o salidas. Puedes editar sus datos, pero no eliminarlo para conservar la trazabilidad.',
            ], 409);
        }

        $medicamento->delete();

        return response()->json([
            'message' => 'Medicamento eliminado correctamente',
        ]);
    }

    public function lotes($id)
    {
        Medicamento::findOrFail($id);

        $entradas = DonacionItem::where('medicamento_id', $id)
            ->selectRaw('lote, fecha_vencimiento, SUM(cantidad) AS entradas')
            ->groupBy('lote', 'fecha_vencimiento')
            ->get();

        $salidas = SalidaItem::where('medicamento_id', $id)
            ->selectRaw('lote, fecha_vencimiento, SUM(cantidad) AS salidas')
            ->groupBy('lote', 'fecha_vencimiento')
            ->get();

        $lotes = [];

        foreach ($entradas as $entrada) {
            $key = ($entrada->lote ?? 'SIN_LOTE') . '|' . ($entrada->fecha_vencimiento ?? 'SIN_FECHA');

            $lotes[$key] = [
                'lote' => $entrada->lote,
                'fecha_vencimiento' => $entrada->fecha_vencimiento,
                'entradas' => (int) $entrada->entradas,
                'salidas' => 0,
                'stock' => (int) $entrada->entradas,
            ];
        }

        foreach ($salidas as $salida) {
            $key = ($salida->lote ?? 'SIN_LOTE') . '|' . ($salida->fecha_vencimiento ?? 'SIN_FECHA');

            if (!isset($lotes[$key])) {
                $lotes[$key] = [
                    'lote' => $salida->lote,
                    'fecha_vencimiento' => $salida->fecha_vencimiento,
                    'entradas' => 0,
                    'salidas' => 0,
                    'stock' => 0,
                ];
            }

            $lotes[$key]['salidas'] += (int) $salida->salidas;
            $lotes[$key]['stock'] = $lotes[$key]['entradas'] - $lotes[$key]['salidas'];
        }

        return response()->json(
            collect($lotes)
                ->filter(fn ($lote) => $lote['stock'] > 0)
                ->sortBy('fecha_vencimiento')
                ->values()
        );
    }
}
