<?php

namespace App\Services\Imports;

use App\Models\Medicamento;
use DateTimeImmutable;
use DateTimeInterface;

class DonacionImportValidator
{
    private const REQUIRED_COLUMNS = [
        'codigo_donacion',
        'fecha_donacion',
        'donante',
        'accion_medicamento',
        'cantidad',
    ];

    private const EXPECTED_COLUMNS = [
        'codigo_donacion',
        'fecha_donacion',
        'donante',
        'tipo_donante',
        'telefono',
        'descripcion_donacion',
        'accion_medicamento',
        'medicamento_id',
        'medicamento_nombre',
        'presentacion',
        'categoria',
        'unidad',
        'descripcion_medicamento',
        'cantidad',
        'lote',
        'fecha_vencimiento',
        'observacion_item',
    ];

    public function requiredColumns(): array
    {
        return self::REQUIRED_COLUMNS;
    }

    public function expectedColumns(): array
    {
        return self::EXPECTED_COLUMNS;
    }

    public function validate(array $rows, array $headers): array
    {
        $errors = [];
        $warnings = [];
        $rowErrors = [];
        $rowWarnings = [];
        $groups = [];
        $newMedicationKeys = [];
        $newMedicationDuplicateKeys = [];
        $possibleDuplicateMedicationIds = [];
        $validMedicationIds = $this->existingMedicationIds($rows);
        $existingMedicationDetails = $this->existingMedicationDetails($rows);

        foreach (self::REQUIRED_COLUMNS as $column) {
            if (! in_array($column, $headers, true)) {
                $this->addGlobalIssue(
                    $errors,
                    'estructura',
                    "Falta la columna obligatoria: {$column}."
                );
            }
        }

        foreach (self::EXPECTED_COLUMNS as $column) {
            if (! in_array($column, $headers, true)) {
                $this->addGlobalIssue(
                    $warnings,
                    'estructura',
                    "No se encontró la columna esperada: {$column}."
                );
            }
        }

        foreach ($rows as $index => $row) {
            $rowNumber = (int) ($row['_fila_excel'] ?? ($index + 2));
            $codigoDonacion = $this->value($row, 'codigo_donacion');
            $fechaDonacion = $this->value($row, 'fecha_donacion');
            $donante = $this->value($row, 'donante');
            $accion = $this->normalizeAction($this->value($row, 'accion_medicamento'));
            $medicamentoId = $this->value($row, 'medicamento_id');
            $medicamentoNombre = $this->value($row, 'medicamento_nombre');
            $presentacion = $this->value($row, 'presentacion');
            $categoria = $this->value($row, 'categoria');
            $unidad = $this->value($row, 'unidad');
            $cantidad = $this->value($row, 'cantidad');
            $lote = $this->value($row, 'lote');
            $fechaVencimiento = $this->value($row, 'fecha_vencimiento');

            if ($codigoDonacion === '') {
                $this->addRowIssue(
                    $errors,
                    $rowErrors,
                    $rowNumber,
                    'codigo_donacion',
                    'El código de donación es obligatorio.',
                    $codigoDonacion
                );
            }

            if ($fechaDonacion === '') {
                $this->addRowIssue(
                    $errors,
                    $rowErrors,
                    $rowNumber,
                    'fecha_donacion',
                    'La fecha de donación es obligatoria.',
                    $fechaDonacion,
                    $codigoDonacion
                );
            } else {
                $normalizedDonationDate = $this->parseDate($fechaDonacion);

                if ($normalizedDonationDate === null) {
                    $this->addRowIssue(
                        $errors,
                        $rowErrors,
                        $rowNumber,
                        'fecha_donacion',
                        'La fecha de donación no tiene un formato válido. Use YYYY-MM-DD.',
                        $fechaDonacion,
                        $codigoDonacion
                    );
                } elseif ($normalizedDonationDate > date('Y-m-d')) {
                    $this->addRowIssue(
                        $warnings,
                        $rowWarnings,
                        $rowNumber,
                        'fecha_donacion',
                        'La fecha de donación es futura. Verifique si es correcta.',
                        $fechaDonacion,
                        $codigoDonacion
                    );
                }
            }

            if ($donante === '') {
                $this->addRowIssue(
                    $errors,
                    $rowErrors,
                    $rowNumber,
                    'donante',
                    'El nombre del donante es obligatorio.',
                    $donante,
                    $codigoDonacion
                );
            }

            if ($accion === '') {
                $this->addRowIssue(
                    $errors,
                    $rowErrors,
                    $rowNumber,
                    'accion_medicamento',
                    'La acción del medicamento es obligatoria. Use EXISTENTE o NUEVO.',
                    $accion,
                    $codigoDonacion
                );
            } elseif (! in_array($accion, ['EXISTENTE', 'NUEVO'], true)) {
                $this->addRowIssue(
                    $errors,
                    $rowErrors,
                    $rowNumber,
                    'accion_medicamento',
                    'La acción del medicamento debe ser EXISTENTE o NUEVO.',
                    $accion,
                    $codigoDonacion
                );
            }

            if ($accion === 'EXISTENTE') {
                if ($medicamentoId === '') {
                    $this->addRowIssue(
                        $errors,
                        $rowErrors,
                        $rowNumber,
                        'medicamento_id',
                        'El medicamento_id es obligatorio cuando accion_medicamento es EXISTENTE.',
                        $medicamentoId,
                        $codigoDonacion
                    );
                } elseif (! ctype_digit($medicamentoId)) {
                    $this->addRowIssue(
                        $errors,
                        $rowErrors,
                        $rowNumber,
                        'medicamento_id',
                        'El medicamento_id debe ser un número entero.',
                        $medicamentoId,
                        $codigoDonacion
                    );
                } elseif (! in_array($medicamentoId, $validMedicationIds, true)) {
                    $this->addRowIssue(
                        $errors,
                        $rowErrors,
                        $rowNumber,
                        'medicamento_id',
                        "El medicamento_id {$medicamentoId} no existe en el sistema.",
                        $medicamentoId,
                        $codigoDonacion
                    );
                } else {
                    $this->warnIfExistingMedicationTextDiffers(
                        $existingMedicationDetails[$medicamentoId] ?? null,
                        $medicamentoNombre,
                        $presentacion,
                        $unidad,
                        $warnings,
                        $rowWarnings,
                        $rowNumber,
                        $codigoDonacion
                    );
                }
            }

            if ($accion === 'NUEVO') {
                if ($medicamentoId !== '') {
                    $this->addRowIssue(
                        $errors,
                        $rowErrors,
                        $rowNumber,
                        'medicamento_id',
                        'Cuando accion_medicamento es NUEVO, medicamento_id debe quedar vacío.',
                        $medicamentoId,
                        $codigoDonacion
                    );
                }

                if ($medicamentoNombre === '') {
                    $this->addRowIssue(
                        $errors,
                        $rowErrors,
                        $rowNumber,
                        'medicamento_nombre',
                        'El nombre del medicamento es obligatorio cuando accion_medicamento es NUEVO.',
                        $medicamentoNombre,
                        $codigoDonacion
                    );
                }

                if ($presentacion === '') {
                    $this->addRowIssue(
                        $errors,
                        $rowErrors,
                        $rowNumber,
                        'presentacion',
                        'La presentación es obligatoria cuando accion_medicamento es NUEVO.',
                        $presentacion,
                        $codigoDonacion
                    );
                }

                if ($unidad === '') {
                    $this->addRowIssue(
                        $errors,
                        $rowErrors,
                        $rowNumber,
                        'unidad',
                        'La unidad es obligatoria cuando accion_medicamento es NUEVO.',
                        $unidad,
                        $codigoDonacion
                    );
                }

                if ($categoria === '') {
                    $this->addRowIssue(
                        $warnings,
                        $rowWarnings,
                        $rowNumber,
                        'categoria',
                        'Se recomienda indicar la categoría del medicamento nuevo.',
                        $categoria,
                        $codigoDonacion
                    );
                }

                if ($medicamentoNombre !== '' && $presentacion !== '' && $unidad !== '') {
                    $newKey = $this->medicationKey($medicamentoNombre, $presentacion, $unidad);
                    $newMedicationKeys[] = $newKey;

                    $possibleDuplicate = $this->findExistingMedicationByKey($medicamentoNombre, $presentacion, $unidad);

                    if ($possibleDuplicate) {
                        $possibleDuplicateMedicationIds[] = (string) $possibleDuplicate->id;

                        $this->addRowIssue(
                            $warnings,
                            $rowWarnings,
                            $rowNumber,
                            'medicamento_nombre',
                            "Este medicamento parece existir en el sistema con ID {$possibleDuplicate->id}. Considere usar accion_medicamento EXISTENTE.",
                            $medicamentoNombre,
                            $codigoDonacion
                        );
                    }
                }
            }

            if ($cantidad === '') {
                $this->addRowIssue(
                    $errors,
                    $rowErrors,
                    $rowNumber,
                    'cantidad',
                    'La cantidad es obligatoria.',
                    $cantidad,
                    $codigoDonacion
                );
            } elseif (! $this->isPositiveInteger($cantidad)) {
                $this->addRowIssue(
                    $errors,
                    $rowErrors,
                    $rowNumber,
                    'cantidad',
                    'La cantidad debe ser un número entero mayor que cero.',
                    $cantidad,
                    $codigoDonacion
                );
            }

            if ($lote === '') {
                $this->addRowIssue(
                    $warnings,
                    $rowWarnings,
                    $rowNumber,
                    'lote',
                    'Se recomienda indicar el lote del medicamento.',
                    $lote,
                    $codigoDonacion
                );
            }

            if ($fechaVencimiento === '') {
                $this->addRowIssue(
                    $warnings,
                    $rowWarnings,
                    $rowNumber,
                    'fecha_vencimiento',
                    'Se recomienda indicar la fecha de vencimiento.',
                    $fechaVencimiento,
                    $codigoDonacion
                );
            } else {
                $normalizedExpirationDate = $this->parseDate($fechaVencimiento);

                if ($normalizedExpirationDate === null) {
                    $this->addRowIssue(
                        $errors,
                        $rowErrors,
                        $rowNumber,
                        'fecha_vencimiento',
                        'La fecha de vencimiento no tiene un formato válido. Use YYYY-MM-DD.',
                        $fechaVencimiento,
                        $codigoDonacion
                    );
                } elseif ($normalizedExpirationDate < date('Y-m-d')) {
                    $this->addRowIssue(
                        $warnings,
                        $rowWarnings,
                        $rowNumber,
                        'fecha_vencimiento',
                        'El medicamento aparece vencido. Verifique antes de aceptar la donación.',
                        $fechaVencimiento,
                        $codigoDonacion
                    );
                }
            }

            if ($codigoDonacion !== '') {
                $groups[$codigoDonacion][] = [
                    'row_number' => $rowNumber,
                    'codigo_donacion' => $codigoDonacion,
                    'fecha_donacion' => $this->parseDate($fechaDonacion) ?? $fechaDonacion,
                    'donante' => $this->normalizeText($donante),
                    'tipo_donante' => $this->normalizeText($this->value($row, 'tipo_donante')),
                    'telefono' => $this->normalizeText($this->value($row, 'telefono')),
                    'descripcion_donacion' => $this->normalizeText($this->value($row, 'descripcion_donacion')),
                ];
            }
        }

        $this->validateDonationGroups($groups, $errors, $warnings, $rowErrors, $rowWarnings);

        $newMedicationCounts = array_count_values($newMedicationKeys);

        foreach ($newMedicationCounts as $key => $count) {
            if ($count > 1) {
                $newMedicationDuplicateKeys[] = $key;
            }
        }

        $annotatedRows = array_map(function (array $row) use ($rowErrors, $rowWarnings) {
            $rowNumber = (int) ($row['_fila_excel'] ?? 0);
            $errorsForRow = $rowErrors[$rowNumber] ?? [];
            $warningsForRow = $rowWarnings[$rowNumber] ?? [];
            $accion = $this->normalizeAction($this->value($row, 'accion_medicamento'));

            return array_merge($row, [
                '_accion_medicamento_normalizada' => $accion,
                '_estado' => empty($errorsForRow) ? 'valida' : 'error',
                '_errores' => $errorsForRow,
                '_advertencias' => $warningsForRow,
            ]);
        }, $rows);

        $rowsWithErrors = array_keys($rowErrors);
        $distinctDonationCodes = array_keys($groups);
        $distinctMedicationIds = $this->distinctMedicationIds($rows);

        return [
            'valid_data' => count($errors) === 0,
            'summary' => [
                'total_filas' => count($rows),
                'filas_validas' => count($rows) - count($rowsWithErrors),
                'filas_con_error' => count($rowsWithErrors),
                'total_errores' => count($errors),
                'total_advertencias' => count($warnings),
                'donaciones_detectadas' => count($distinctDonationCodes),
                'items_detectados' => count($rows),
                'medicamentos_distintos_detectados' => count($distinctMedicationIds),
                'medicamentos_existentes_detectados' => count($distinctMedicationIds),
                'medicamentos_nuevos_detectados' => count(array_unique($newMedicationKeys)),
                'medicamentos_nuevos_repetidos_en_archivo' => count($newMedicationDuplicateKeys),
                'posibles_medicamentos_duplicados' => count(array_unique($possibleDuplicateMedicationIds)),
            ],
            'errors' => $errors,
            'warnings' => $warnings,
            'rows' => $annotatedRows,
        ];
    }

    private function warnIfExistingMedicationTextDiffers(
        ?Medicamento $medicamento,
        string $medicamentoNombre,
        string $presentacion,
        string $unidad,
        array &$warnings,
        array &$rowWarnings,
        int $rowNumber,
        string $codigoDonacion
    ): void {
        if (! $medicamento) {
            return;
        }

        if ($medicamentoNombre !== '' && $this->normalizeText($medicamentoNombre) !== $this->normalizeText((string) $medicamento->nombre)) {
            $this->addRowIssue(
                $warnings,
                $rowWarnings,
                $rowNumber,
                'medicamento_nombre',
                "El nombre escrito no coincide con el medicamento_id {$medicamento->id}. Se usará el registro del sistema: {$medicamento->nombre}.",
                $medicamentoNombre,
                $codigoDonacion
            );
        }

        if ($presentacion !== '' && $this->normalizeText($presentacion) !== $this->normalizeText((string) ($medicamento->presentacion ?? ''))) {
            $this->addRowIssue(
                $warnings,
                $rowWarnings,
                $rowNumber,
                'presentacion',
                "La presentación escrita no coincide con el medicamento_id {$medicamento->id}. Se usará el registro del sistema.",
                $presentacion,
                $codigoDonacion
            );
        }

        if ($unidad !== '' && $this->normalizeText($unidad) !== $this->normalizeText((string) ($medicamento->unidad ?? ''))) {
            $this->addRowIssue(
                $warnings,
                $rowWarnings,
                $rowNumber,
                'unidad',
                "La unidad escrita no coincide con el medicamento_id {$medicamento->id}. Se usará el registro del sistema.",
                $unidad,
                $codigoDonacion
            );
        }
    }

    private function validateDonationGroups(
        array $groups,
        array &$errors,
        array &$warnings,
        array &$rowErrors,
        array &$rowWarnings
    ): void {
        foreach ($groups as $codigoDonacion => $rows) {
            $dates = $this->uniqueValues($rows, 'fecha_donacion');
            $donors = $this->uniqueValues($rows, 'donante');

            if (count($dates) > 1) {
                foreach ($rows as $row) {
                    $this->addRowIssue(
                        $errors,
                        $rowErrors,
                        $row['row_number'],
                        'fecha_donacion',
                        "Las filas con codigo_donacion {$codigoDonacion} deben tener la misma fecha de donación.",
                        $row['fecha_donacion'],
                        $codigoDonacion
                    );
                }
            }

            if (count($donors) > 1) {
                foreach ($rows as $row) {
                    $this->addRowIssue(
                        $errors,
                        $rowErrors,
                        $row['row_number'],
                        'donante',
                        "Las filas con codigo_donacion {$codigoDonacion} deben pertenecer al mismo donante.",
                        $row['donante'],
                        $codigoDonacion
                    );
                }
            }

            foreach (['tipo_donante', 'telefono', 'descripcion_donacion'] as $optionalField) {
                $values = $this->uniqueValues($rows, $optionalField);

                if (count($values) > 1) {
                    foreach ($rows as $row) {
                        $this->addRowIssue(
                            $warnings,
                            $rowWarnings,
                            $row['row_number'],
                            $optionalField,
                            "Las filas con codigo_donacion {$codigoDonacion} tienen datos distintos en {$optionalField}. Se usará el valor de la primera fila en la importación.",
                            $row[$optionalField],
                            $codigoDonacion
                        );
                    }
                }
            }
        }
    }

    private function existingMedicationIds(array $rows): array
    {
        $ids = $this->distinctMedicationIds($rows);

        if (empty($ids)) {
            return [];
        }

        return Medicamento::query()
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    private function existingMedicationDetails(array $rows): array
    {
        $ids = $this->distinctMedicationIds($rows);

        if (empty($ids)) {
            return [];
        }

        return Medicamento::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy(fn (Medicamento $medicamento) => (string) $medicamento->id)
            ->all();
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

    private function distinctMedicationIds(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $id = $this->value($row, 'medicamento_id');

            if ($id !== '' && ctype_digit($id)) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
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

    private function addGlobalIssue(array &$target, string $field, string $message): void
    {
        $target[] = [
            'fila' => null,
            'campo' => $field,
            'mensaje' => $message,
            'valor' => null,
            'codigo_donacion' => null,
        ];
    }

    private function addRowIssue(
        array &$target,
        array &$rowTarget,
        int $rowNumber,
        string $field,
        string $message,
        ?string $value = null,
        ?string $codigoDonacion = null
    ): void {
        $issue = [
            'fila' => $rowNumber,
            'campo' => $field,
            'mensaje' => $message,
            'valor' => $value,
            'codigo_donacion' => $codigoDonacion,
        ];

        $target[] = $issue;
        $rowTarget[$rowNumber][] = $issue;
    }

    private function value(array $row, string $field): string
    {
        return trim((string) ($row[$field] ?? ''));
    }

    private function isPositiveInteger(string $value): bool
    {
        $value = str_replace([' ', ','], ['', '.'], trim($value));

        if ($value === '' || ! is_numeric($value)) {
            return false;
        }

        $number = (float) $value;

        return $number > 0 && floor($number) === $number;
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

    private function uniqueValues(array $rows, string $field): array
    {
        $values = [];

        foreach ($rows as $row) {
            $value = trim((string) ($row[$field] ?? ''));

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }
}
