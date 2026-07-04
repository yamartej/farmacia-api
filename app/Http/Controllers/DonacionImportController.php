<?php

namespace App\Http\Controllers;

use App\Services\Imports\DonacionImportProcessor;
use App\Services\Imports\DonacionImportValidator;
use App\Services\Imports\SpreadsheetPreviewReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DonacionImportController extends Controller
{
    public function preview(
        Request $request,
        SpreadsheetPreviewReader $reader,
        DonacionImportValidator $validator
    ): JsonResponse {
        [$file, $extension] = $this->validateUploadedFile($request);

        try {
            $result = $reader->read($file, maxRows: 1000, previewRows: 25);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'No fue posible leer el archivo.',
                'error' => $exception->getMessage(),
            ], 422);
        }

        return response()->json(
            $this->buildPreviewResponse(
                result: $result,
                validator: $validator,
                filename: $file->getClientOriginalName(),
                extension: $extension
            )
        );
    }

    public function confirm(
        Request $request,
        SpreadsheetPreviewReader $reader,
        DonacionImportValidator $validator,
        DonacionImportProcessor $processor
    ): JsonResponse {
        [$file, $extension] = $this->validateUploadedFile($request);

        try {
            $result = $reader->read($file, maxRows: 1000, previewRows: 25);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'No fue posible leer el archivo.',
                'error' => $exception->getMessage(),
            ], 422);
        }

        $previewResponse = $this->buildPreviewResponse(
            result: $result,
            validator: $validator,
            filename: $file->getClientOriginalName(),
            extension: $extension
        );

        if (! $previewResponse['valid']) {
            return response()->json([
                'message' => 'El archivo contiene errores. No se puede confirmar la importación.',
                'valid' => false,
                'resumen' => $previewResponse['resumen'],
                'errores' => $previewResponse['errores'],
                'advertencias' => $previewResponse['advertencias'],
                'preview' => $previewResponse['preview'],
            ], 422);
        }

        try {
            $summary = $processor->process($result['rows']);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'No fue posible completar la importación.',
                'error' => $exception->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => 'Importación completada correctamente.',
            'valid' => true,
            'resumen' => $summary,
            'advertencias' => $previewResponse['advertencias'],
        ], 201);
    }

    private function validateUploadedFile(Request $request): array
    {
        $request->validate([
            'archivo' => ['required', 'file', 'max:5120'],
        ], [
            'archivo.required' => 'Debe seleccionar un archivo para importar.',
            'archivo.file' => 'El archivo enviado no es válido.',
            'archivo.max' => 'El archivo no debe superar los 5 MB.',
        ]);

        $file = $request->file('archivo');
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, ['xlsx', 'csv'], true)) {
            throw ValidationException::withMessages([
                'archivo' => [
                    'Formato no soportado. En esta fase se aceptan archivos .xlsx o .csv. Si tiene un .xls, guárdelo como .xlsx desde Excel.',
                ],
            ]);
        }

        return [$file, $extension];
    }

    private function buildPreviewResponse(
        array $result,
        DonacionImportValidator $validator,
        string $filename,
        string $extension
    ): array {
        $headers = $result['headers'];
        $rows = $result['rows'];

        $missingRequiredColumns = array_values(array_diff($validator->requiredColumns(), $headers));
        $missingExpectedColumns = array_values(array_diff($validator->expectedColumns(), $headers));

        $validation = $validator->validate($rows, $headers);
        $validStructure = empty($missingRequiredColumns);
        $validData = $validation['valid_data'];
        $valid = $validStructure && $validData;

        return [
            'message' => $valid
                ? 'Archivo validado correctamente. Puede confirmar la importación.'
                : 'El archivo fue leído, pero contiene errores que deben corregirse.',
            'valid' => $valid,
            'valid_structure' => $validStructure,
            'valid_data' => $validData,
            'filename' => $filename,
            'extension' => $extension,
            'sheet' => $result['sheet'],
            'total_filas_detectadas' => $result['total_rows'],
            'total_filas_mostradas' => count($result['preview']),
            'columnas_detectadas' => $headers,
            'columnas_requeridas' => $validator->requiredColumns(),
            'columnas_esperadas' => $validator->expectedColumns(),
            'columnas_obligatorias_faltantes' => $missingRequiredColumns,
            'columnas_esperadas_faltantes' => $missingExpectedColumns,
            'resumen' => $validation['summary'],
            'errores' => $validation['errors'],
            'advertencias' => $validation['warnings'],
            'preview' => array_slice($validation['rows'], 0, 25),
            'nota' => 'Esta fase valida y permite confirmar la importación si no hay errores.',
        ];
    }
}
