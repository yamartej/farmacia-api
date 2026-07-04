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
        return $this->stockDisponiblePorLoteBase(
            medicamentoId: $medicamentoId,
            lote: $lote,
            fechaVencimiento: $fechaVencimiento
        );
    }

    public function stockDisponiblePorLoteExcluyendoSalida(
        int $medicamentoId,
        ?string $lote,
        ?string $fechaVencimiento,
        int $salidaId
    ): int {
        return $this->stockDisponiblePorLoteBase(
            medicamentoId: $medicamentoId,
            lote: $lote,
            fechaVencimiento: $fechaVencimiento,
            salidaIdExcluida: $salidaId
        );
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
        $this->validarStockSuficientePorLoteConStock(
            medicamentoId: $medicamentoId,
            cantidad: $cantidad,
            lote: $lote,
            fechaVencimiento: $fechaVencimiento,
            stockDisponible: $this->stockDisponiblePorLote($medicamentoId, $lote, $fechaVencimiento)
        );
    }

    public function validarStockSuficientePorLoteExcluyendoSalida(
        int $medicamentoId,
        int $cantidad,
        ?string $lote,
        ?string $fechaVencimiento,
        int $salidaId
    ): void {
        $this->validarStockSuficientePorLoteConStock(
            medicamentoId: $medicamentoId,
            cantidad: $cantidad,
            lote: $lote,
            fechaVencimiento: $fechaVencimiento,
            stockDisponible: $this->stockDisponiblePorLoteExcluyendoSalida(
                medicamentoId: $medicamentoId,
                lote: $lote,
                fechaVencimiento: $fechaVencimiento,
                salidaId: $salidaId
            )
        );
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

    private function stockDisponiblePorLoteBase(
        int $medicamentoId,
        ?string $lote,
        ?string $fechaVencimiento,
        ?int $salidaIdExcluida = null
    ): int {
        $entradas = DonacionItem::where('medicamento_id', $medicamentoId)
            ->where(function ($query) use ($lote) {
                $this->aplicarFiltroLote($query, $lote);
            })
            ->where(function ($query) use ($fechaVencimiento) {
                $this->aplicarFiltroFecha($query, $fechaVencimiento);
            })
            ->sum('cantidad');

        $salidasQuery = SalidaItem::where('medicamento_id', $medicamentoId)
            ->where(function ($query) use ($lote) {
                $this->aplicarFiltroLote($query, $lote);
            })
            ->where(function ($query) use ($fechaVencimiento) {
                $this->aplicarFiltroFecha($query, $fechaVencimiento);
            });

        if ($salidaIdExcluida !== null) {
            $salidasQuery->where('salida_id', '<>', $salidaIdExcluida);
        }

        $salidas = $salidasQuery->sum('cantidad');

        return (int) $entradas - (int) $salidas;
    }

    private function validarStockSuficientePorLoteConStock(
        int $medicamentoId,
        int $cantidad,
        ?string $lote,
        ?string $fechaVencimiento,
        int $stockDisponible
    ): void {
        $this->validarMedicamento($medicamentoId);
        $this->validarCantidad($cantidad);

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

    private function aplicarFiltroLote($query, ?string $lote): void
    {
        if ($lote === null || $lote === '') {
            $query->whereNull('lote')->orWhere('lote', '');
            return;
        }

        $query->where('lote', $lote);
    }

    private function aplicarFiltroFecha($query, ?string $fecha): void
    {
        if ($fecha === null || $fecha === '') {
            $query->whereNull('fecha_vencimiento');
            return;
        }

        $query->whereDate('fecha_vencimiento', $fecha);
    }
}
