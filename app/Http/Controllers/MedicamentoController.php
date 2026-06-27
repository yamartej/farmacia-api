<?php

namespace App\Http\Controllers;

use App\Models\Medicamento;
use App\Models\Movimiento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MedicamentoController extends Controller
{
    public function index(Request $request)
    {
        $query = Medicamento::query();

        // 🔍 Buscador general
        if (!empty($request->search)) {
            $query->where(function ($q) use ($request) {
                $term = '%' . $request->search . '%';
                $q->where('nombre', 'like', $term)
                    ->orWhere('categoria', 'like', $term)
                    ->orWhere('presentacion', 'like', $term);
            });
        }

        // 🟦 Filtro por categoría
        if (!empty($request->categoria)) {
            $query->where('categoria', 'like', '%' . $request->categoria . '%');
        }

        // 🟪 Filtro por unidad
        if (!empty($request->unidad)) {
            $query->where('unidad', 'like', '%' . $request->unidad . '%');
        }

        // 🟥 Filtro: vence en 30 días
        if ($request->vencimiento === 'proximo') {
            $query->whereDate('fecha_vencimiento', '<=', now()->addDays(30));
        }

        // 🟧 Filtro: stock bajo
        if ($request->stock === 'bajo') {
            $query->where('cantidad', '<=', 10);
        }

        return $query->orderBy('nombre')->paginate(10);
    }





    public function store(Request $request)
    {
        $validated = $request->validate([
            'nombre' => 'required|string|max:255',
            'presentacion' => 'required|string|max:255',
            'categoria' => 'required|string|max:255',
            'unidad' => 'required|string|max:255',
            'descripcion' => 'nullable|string|max:500',
        ]);

        $medicamento = Medicamento::create($validated);

        return response()->json([
            'message' => 'Medicamento creado correctamente',
            'data' => $medicamento
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
            'nombre' => 'required',
            'presentacion' => 'nullable',
            'categoria' => 'nullable',
            'unidad' => 'nullable',
            'fecha_vencimiento' => 'nullable|date',
            'descripcion' => 'nullable'
        ]);

        $medicamento->update($data);

        return response()->json($medicamento);
    }

    public function destroy($id)
    {
        $medicamento = Medicamento::find($id);

        if (!$medicamento) {
            return response()->json([
                'message' => 'Medicamento no encontrado'
            ], 404);
        }

        $medicamento->delete();

        return response()->json([
            'message' => 'Medicamento eliminado correctamente'
        ], 200);
    }

    public function lotes($id)
    {
        $lotes = \App\Models\DonacionItem::where('medicamento_id', $id)
            ->selectRaw('lote, fecha_vencimiento, SUM(cantidad) AS stock')
            ->groupBy('lote', 'fecha_vencimiento')
            ->orderBy('fecha_vencimiento')
            ->get();

        return response()->json($lotes);
    }
}
