<?php

namespace App\Services;

use App\Models\DonacionItem;
use App\Models\Medicamento;
use App\Models\Movimiento;
use App\Models\SalidaItem;
use Illuminate\Validation\ValidationException;

class InventarioService
{
    public function stockDisponible(int $medicamentoId): int
    {
        $entradas = Movimiento::where('medicamento_id', $medicamentoId)
            ->where('tipo', 'entrada')
            ->sum('cantidad');

        $salidas = Movimiento::where('medicamento_id', $medicamentoId)
            ->where('tipo', 'salida')
            ->sum('cantidad');

        return (int) $entradas - (int) $salidas;
    }

    public function stockDisponiblePorLote(
        int $medicamentoId,
        ?string $lote,
        ?string $fechaVencimiento
    ): int {
        $entradas = DonacionItem::where('medicamento_id', $medicamentoId)
            ->where(function ($query) use ($lote) {
                if ($lote === null || $lote === '') {
                    $query->whereNull('lote')->orWhere('lote', '');
                } else {
                    $query->where('lote', $lote);
                }
            })
            ->where(function ($query) use ($fechaVencimiento) {
                if ($fechaVencimiento === null || $fechaVencimiento === '') {
                    $query->whereNull('fecha_vencimiento');
                } else {
                    $query->whereDate('fecha_vencimiento', $fechaVencimiento);
                }
            })
            ->sum('cantidad');

        $salidas = SalidaItem::where('medicamento_id', $medicamentoId)
            ->where(function ($query) use ($lote) {
                if ($lote === null || $lote === '') {
                    $query->whereNull('lote')->orWhere('lote', '');
                } else {
                    $query->where('lote', $lote);
                }
            })
            ->where(function ($query) use ($fechaVencimiento) {
                if ($fechaVencimiento === null || $fechaVencimiento === '') {
                    $query->whereNull('fecha_vencimiento');
                } else {
                    $query->whereDate('fecha_vencimiento', $fechaVencimiento);
                }
            })
            ->sum('cantidad');

        return (int) $entradas - (int) $salidas;
    }

    public function registrarEntrada(
        int $medicamentoId,
        int $cantidad,
        string $fecha,
        string $origen = 'manual',
        ?int $origenId = null,
        ?string $descripcion = null
    ): Movimiento {
        $this->validarMedicamento($medicamentoId);
        $this->validarCantidad($cantidad);

        return Movimiento::create([
            'medicamento_id' => $medicamentoId,
            'tipo'           => 'entrada',
            'cantidad'       => $cantidad,
            'fecha'          => $fecha,
            'origen'         => $origen,
            'origen_id'      => $origenId,
            'descripcion'    => $descripcion,
        ]);
    }

    public function registrarSalida(
        int $medicamentoId,
        int $cantidad,
        string $fecha,
        string $origen = 'manual',
        ?int $origenId = null,
        ?string $descripcion = null
    ): Movimiento {
        $this->validarMedicamento($medicamentoId);
        $this->validarCantidad($cantidad);
        $this->validarStockSuficiente($medicamentoId, $cantidad);

        return Movimiento::create([
            'medicamento_id' => $medicamentoId,
            'tipo'           => 'salida',
            'cantidad'       => $cantidad,
            'fecha'          => $fecha,
            'origen'         => $origen,
            'origen_id'      => $origenId,
            'descripcion'    => $descripcion,
        ]);
    }

    public function validarStockSuficientePorLote(
        int $medicamentoId,
        int $cantidad,
        ?string $lote,
        ?string $fechaVencimiento
    ): void {
        $this->validarMedicamento($medicamentoId);
        $this->validarCantidad($cantidad);

        $stockDisponible = $this->stockDisponiblePorLote(
            medicamentoId: $medicamentoId,
            lote: $lote,
            fechaVencimiento: $fechaVencimiento
        );

        if ($stockDisponible < $cantidad) {
            $medicamento = Medicamento::find($medicamentoId);

            throw ValidationException::withMessages([
                'cantidad' => sprintf(
                    'No hay suficiente stock del lote seleccionado para %s. Lote: %s. Vence: %s. Stock disponible del lote: %s. Cantidad solicitada: %s.',
                    $medicamento?->nombre ?? 'el medicamento seleccionado',
                    $lote ?: 'SIN LOTE',
                    $fechaVencimiento ?: 'SIN FECHA',
                    $stockDisponible,
                    $cantidad
                ),
            ]);
        }
    }

    public function revertirEntrada(
        int $medicamentoId,
        int $cantidad,
        string $fecha,
        string $origen = 'ajuste',
        ?int $origenId = null,
        ?string $descripcion = null
    ): Movimiento {
        return $this->registrarSalida(
            medicamentoId: $medicamentoId,
            cantidad: $cantidad,
            fecha: $fecha,
            origen: $origen,
            origenId: $origenId,
            descripcion: $descripcion
        );
    }

    public function revertirSalida(
        int $medicamentoId,
        int $cantidad,
        string $fecha,
        string $origen = 'ajuste',
        ?int $origenId = null,
        ?string $descripcion = null
    ): Movimiento {
        return $this->registrarEntrada(
            medicamentoId: $medicamentoId,
            cantidad: $cantidad,
            fecha: $fecha,
            origen: $origen,
            origenId: $origenId,
            descripcion: $descripcion
        );
    }

    private function validarMedicamento(int $medicamentoId): void
    {
        Medicamento::findOrFail($medicamentoId);
    }

    private function validarCantidad(int $cantidad): void
    {
        if ($cantidad <= 0) {
            throw ValidationException::withMessages([
                'cantidad' => 'La cantidad debe ser mayor a cero.',
            ]);
        }
    }

    private function validarStockSuficiente(int $medicamentoId, int $cantidad): void
    {
        $stockDisponible = $this->stockDisponible($medicamentoId);

        if ($stockDisponible < $cantidad) {
            $medicamento = Medicamento::find($medicamentoId);

            throw ValidationException::withMessages([
                'cantidad' => sprintf(
                    'No hay suficiente stock para %s. Stock disponible: %s. Cantidad solicitada: %s.',
                    $medicamento?->nombre ?? 'el medicamento seleccionado',
                    $stockDisponible,
                    $cantidad
                ),
            ]);
        }
    }
}
