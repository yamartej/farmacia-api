<?php

namespace App\Services\Imports;

use App\Models\Donacion;
use App\Models\DonacionItem;
use App\Models\Medicamento;
use App\Services\InventarioService;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class DonacionImportProcessor
{
    public function __construct(private readonly InventarioService $inventario)
    {
    }

    public function process(array $rows): array
    {
        return DB::transaction(function () use ($rows) {
            $groups = $this->groupRowsByDonationCode($rows);
            $newMedicationCache = [];

            $summary = [
                'donaciones_creadas' => 0,
                'items_creados' => 0,
                'medicamentos_creados' => 0,
                'medicamentos_nuevos_reutilizados' => 0,
                'medicamentos_existentes_usados' => 0,
                'movimientos_creados' => 0,
            ];

            $createdMedicamentos = [];
            $createdDonaciones = [];

            foreach ($groups as $codigoDonacion => $donationRows) {
                $firstRow = $donationRows[0];

                $donacion = Donacion::create([
                    'donante' => $this->value($firstRow, 'donante'),
                    'tipo_donante' => $this->nullableValue($firstRow, 'tipo_donante'),
                    'telefono' => $this->nullableValue($firstRow, 'telefono'),
                    'fecha_donacion' => $this->parseDate($this->value($firstRow, 'fecha_donacion')) ?? $this->value($firstRow, 'fecha_donacion'),
                    'descripcion' => $this->nullableValue($firstRow, 'descripcion_donacion'),
                ]);

                $summary['donaciones_creadas']++;
                $createdDonaciones[] = [
                    'id' => $donacion->id,
                    'codigo_donacion' => $codigoDonacion,
                    'donante' => $donacion->donante,
                ];

                foreach ($donationRows as $row) {
                    $medicationResult = $this->resolveMedication($row, $newMedicationCache);

                    if ($medicationResult['created']) {
                        $summary['medicamentos_creados']++;
                        $createdMedicamentos[] = [
                            'id' => $medicationResult['medicamento']->id,
                            'nombre' => $medicationResult['medicamento']->nombre,
                            'presentacion' => $medicationResult['medicamento']->presentacion,
                            'unidad' => $medicationResult['medicamento']->unidad,
                        ];
                    } elseif ($medicationResult['reused_new']) {
                        $summary['medicamentos_nuevos_reutilizados']++;
                    } else {
                        $summary['medicamentos_existentes_usados']++;
                    }

                    $item = DonacionItem::create([
                        'donacion_id' => $donacion->id,
                        'medicamento_id' => $medicationResult['medicamento']->id,
                        'cantidad' => (int) $this->value($row, 'cantidad'),
                        'lote' => $this->nullableValue($row, 'lote'),
                        'fecha_vencimiento' => $this->nullableDate($this->value($row, 'fecha_vencimiento')),
                    ]);

                    $summary['items_creados']++;

                    $this->inventario->registrarEntrada(
                        medicamentoId: $item->medicamento_id,
                        cantidad: $item->cantidad,
                        fecha: $donacion->fecha_donacion,
                        origen: 'donacion',
                        origenId: $item->id,
                        descripcion: "Entrada por importación Excel - Donación {$codigoDonacion} #{$donacion->id}" .
                            ($item->lote ? " - Lote {$item->lote}" : '')
                    );

                    $summary['movimientos_creados']++;
                }
            }

            $summary['medicamentos_creados_detalle'] = $createdMedicamentos;
            $summary['donaciones_creadas_detalle'] = $createdDonaciones;

            return $summary;
        });
    }

    private function resolveMedication(array $row, array &$newMedicationCache): array
    {
        $action = $this->normalizeAction($this->value($row, 'accion_medicamento'));

        if ($action === 'EXISTENTE') {
            $medicamento = Medicamento::findOrFail((int) $this->value($row, 'medicamento_id'));

            return [
                'medicamento' => $medicamento,
                'created' => false,
                'reused_new' => false,
            ];
        }

        $name = $this->value($row, 'medicamento_nombre');
        $presentation = $this->value($row, 'presentacion');
        $unit = $this->value($row, 'unidad');
        $key = $this->medicationKey($name, $presentation, $unit);

        if (isset($newMedicationCache[$key])) {
            return [
                'medicamento' => $newMedicationCache[$key],
                'created' => false,
                'reused_new' => true,
            ];
        }

        $existing = $this->findExistingMedicationByKey($name, $presentation, $unit);

        if ($existing) {
            $newMedicationCache[$key] = $existing;

            return [
                'medicamento' => $existing,
                'created' => false,
                'reused_new' => false,
            ];
        }

        $medicamento = Medicamento::create([
            'nombre' => $name,
            'presentacion' => $presentation,
            'categoria' => $this->nullableValue($row, 'categoria'),
            'unidad' => $unit,
            'descripcion' => $this->nullableValue($row, 'descripcion_medicamento'),
        ]);

        $newMedicationCache[$key] = $medicamento;

        return [
            'medicamento' => $medicamento,
            'created' => true,
            'reused_new' => false,
        ];
    }

    private function groupRowsByDonationCode(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $code = $this->value($row, 'codigo_donacion');
            $groups[$code][] = $row;
        }

        return $groups;
    }

    private function findExistingMedicationByKey(string $nombre, string $presentacion, string $unidad): ?Medicamento
    {
        return Medicamento::query()
            ->get()
            ->first(function (Medicamento $medicamento) use ($nombre, $presentacion, $unidad) {
                return $this->medicationKey(
                    (string) $medicamento->nombre,
                    (string) ($medicamento->presentacion ?? ''),
                    (string) ($medicamento->unidad ?? '')
                ) === $this->medicationKey($nombre, $presentacion, $unidad);
            });
    }

    private function medicationKey(string $nombre, string $presentacion, string $unidad): string
    {
        return implode('|', [
            $this->normalizeText($nombre),
            $this->normalizeText($presentacion),
            $this->normalizeText($unidad),
        ]);
    }

    private function normalizeAction(string $value): string
    {
        $value = mb_strtoupper(trim($value), 'UTF-8');

        return match ($value) {
            'EXISTE', 'EXISTENTE', 'EXISTING' => 'EXISTENTE',
            'NUEVA', 'NUEVO', 'NEW' => 'NUEVO',
            default => $value,
        };
    }

    private function value(array $row, string $field): string
    {
        return trim((string) ($row[$field] ?? ''));
    }

    private function nullableValue(array $row, string $field): ?string
    {
        $value = $this->value($row, $field);

        return $value === '' ? null : $value;
    }

    private function nullableDate(string $value): ?string
    {
        if (trim($value) === '') {
            return null;
        }

        return $this->parseDate($value) ?? $value;
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return $this->excelSerialDateToString((float) $value);
        }

        $formats = [
            'Y-m-d',
            'Y-m-d H:i:s',
            'd/m/Y',
            'd-m-Y',
            'd.m.Y',
            'Y/m/d',
        ];

        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            $errors = DateTimeImmutable::getLastErrors();

            if ($date instanceof DateTimeInterface && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function excelSerialDateToString(float $serial): ?string
    {
        if ($serial <= 0) {
            return null;
        }

        $days = (int) floor($serial);
        $timestamp = strtotime('1899-12-30 +' . $days . ' days');

        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d', $timestamp);
    }

    private function normalizeText(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        $value = mb_strtolower($value, 'UTF-8');

        $replacements = [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'ñ' => 'n',
        ];

        return strtr($value, $replacements);
    }
}
