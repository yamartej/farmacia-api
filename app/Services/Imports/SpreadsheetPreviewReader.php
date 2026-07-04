<?php

namespace App\Services\Imports;

use Illuminate\Http\UploadedFile;
use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class SpreadsheetPreviewReader
{
    private const SPREADSHEET_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const OFFICE_RELATIONSHIP_NAMESPACE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const PACKAGE_RELATIONSHIP_NAMESPACE = 'http://schemas.openxmlformats.org/package/2006/relationships';

    public function read(UploadedFile $file, int $maxRows = 500, int $previewRows = 25): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        return match ($extension) {
            'csv' => $this->readCsv($file, $maxRows, $previewRows),
            'xlsx' => $this->readXlsx($file, $maxRows, $previewRows),
            default => throw new RuntimeException('Formato de archivo no soportado.'),
        };
    }

    private function readCsv(UploadedFile $file, int $maxRows, int $previewRows): array
    {
        $path = $file->getRealPath();

        if (! $path || ! is_readable($path)) {
            throw new RuntimeException('No se pudo leer el archivo CSV.');
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('No se pudo abrir el archivo CSV.');
        }

        $headers = [];
        $rows = [];
        $lineNumber = 0;

        while (($data = fgetcsv($handle, 0, ',')) !== false) {
            $lineNumber++;

            if ($this->isEmptyRow($data)) {
                continue;
            }

            if (empty($headers)) {
                $headers = $this->normalizeHeaders($data);
                continue;
            }

            if (count($rows) >= $maxRows) {
                break;
            }

            $rows[] = $this->buildAssocRow($headers, $data, $lineNumber);
        }

        fclose($handle);

        return [
            'sheet' => 'CSV',
            'headers' => $headers,
            'total_rows' => count($rows),
            'rows' => $rows,
            'preview' => array_slice($rows, 0, $previewRows),
        ];
    }

    private function readXlsx(UploadedFile $file, int $maxRows, int $previewRows): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('La extensión PHP ZipArchive no está disponible. Habilite ext-zip para leer archivos .xlsx.');
        }

        $zip = new ZipArchive();
        $path = $file->getRealPath();

        if (! $path || $zip->open($path) !== true) {
            throw new RuntimeException('No se pudo abrir el archivo .xlsx.');
        }

        try {
            $sharedStrings = $this->readSharedStrings($zip);
            $dateStyleIndexes = $this->readDateStyleIndexes($zip);
            $worksheetPath = $this->getFirstWorksheetPath($zip);

            $worksheetXml = $zip->getFromName($worksheetPath);

            if ($worksheetXml === false) {
                throw new RuntimeException("No se encontró la hoja del archivo Excel: {$worksheetPath}.");
            }

            $xml = simplexml_load_string($worksheetXml);

            if (! $xml instanceof SimpleXMLElement) {
                throw new RuntimeException('La hoja del Excel no tiene un formato XML válido.');
            }

            $namespace = $this->spreadsheetNamespace($xml);
            $sheetRows = $this->worksheetRows($xml, $namespace);

            $headers = [];
            $rows = [];

            foreach ($sheetRows as $row) {
                $excelRowNumber = (int) $this->attribute($row, 'r', null, '0');
                $rowData = $this->xlsxRowToArray($row, $sharedStrings, $dateStyleIndexes, $namespace);

                if ($this->isEmptyRow($rowData)) {
                    continue;
                }

                if (empty($headers)) {
                    $headers = $this->normalizeHeaders($rowData);
                    continue;
                }

                if (count($rows) >= $maxRows) {
                    break;
                }

                $rows[] = $this->buildAssocRow($headers, $rowData, $excelRowNumber);
            }

            return [
                'sheet' => basename($worksheetPath),
                'headers' => $headers,
                'total_rows' => count($rows),
                'rows' => $rows,
                'preview' => array_slice($rows, 0, $previewRows),
            ];
        } finally {
            $zip->close();
        }
    }

    private function readSharedStrings(ZipArchive $zip): array
    {
        $content = $zip->getFromName('xl/sharedStrings.xml');

        if ($content === false) {
            return [];
        }

        $xml = simplexml_load_string($content);

        if (! $xml instanceof SimpleXMLElement) {
            return [];
        }

        $namespace = $this->spreadsheetNamespace($xml);
        $xml->registerXPathNamespace('main', $namespace);

        $items = $xml->xpath('/main:sst/main:si') ?: [];
        $strings = [];

        foreach ($items as $item) {
            $item->registerXPathNamespace('main', $namespace);
            $textNodes = $item->xpath('.//main:t') ?: [];
            $text = '';

            foreach ($textNodes as $textNode) {
                $text .= (string) $textNode;
            }

            $strings[] = $text;
        }

        return $strings;
    }

    private function readDateStyleIndexes(ZipArchive $zip): array
    {
        $content = $zip->getFromName('xl/styles.xml');

        if ($content === false) {
            return [];
        }

        $xml = simplexml_load_string($content);

        if (! $xml instanceof SimpleXMLElement) {
            return [];
        }

        $namespace = $this->spreadsheetNamespace($xml);
        $xml->registerXPathNamespace('main', $namespace);

        $builtInDateFormatIds = [
            14, 15, 16, 17, 18, 19, 20, 21, 22,
            27, 30, 36, 45, 46, 47, 50, 57,
        ];

        $customDateFormatIds = [];
        $numFormats = $xml->xpath('/main:styleSheet/main:numFmts/main:numFmt') ?: [];

        foreach ($numFormats as $numFmt) {
            $id = (int) $this->attribute($numFmt, 'numFmtId', null, '0');
            $code = strtolower($this->attribute($numFmt, 'formatCode'));

            if (preg_match('/[dmy]/', $code) && ! preg_match('/\\[h\\]|h+:mm|s+/', $code)) {
                $customDateFormatIds[] = $id;
            }
        }

        $dateStyleIndexes = [];
        $cellFormats = $xml->xpath('/main:styleSheet/main:cellXfs/main:xf') ?: [];

        foreach ($cellFormats as $index => $xf) {
            $numFmtId = (int) $this->attribute($xf, 'numFmtId', null, '0');

            if (in_array($numFmtId, $builtInDateFormatIds, true) || in_array($numFmtId, $customDateFormatIds, true)) {
                $dateStyleIndexes[] = (int) $index;
            }
        }

        return $dateStyleIndexes;
    }

    private function getFirstWorksheetPath(ZipArchive $zip): string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relationshipsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbookXml === false || $relationshipsXml === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        $workbook = simplexml_load_string($workbookXml);
        $relationships = simplexml_load_string($relationshipsXml);

        if (! $workbook instanceof SimpleXMLElement || ! $relationships instanceof SimpleXMLElement) {
            return 'xl/worksheets/sheet1.xml';
        }

        $workbookNamespace = $this->spreadsheetNamespace($workbook);
        $workbook->registerXPathNamespace('main', $workbookNamespace);

        $sheets = $workbook->xpath('/main:workbook/main:sheets/main:sheet') ?: [];
        $firstSheet = $sheets[0] ?? null;

        if (! $firstSheet) {
            return 'xl/worksheets/sheet1.xml';
        }

        $relationshipId = $this->attribute($firstSheet, 'id', self::OFFICE_RELATIONSHIP_NAMESPACE);

        if ($relationshipId === '') {
            return 'xl/worksheets/sheet1.xml';
        }

        $relationshipsNamespace = $this->packageRelationshipNamespace($relationships);
        $relationships->registerXPathNamespace('relpkg', $relationshipsNamespace);

        $relationshipNodes = $relationships->xpath('/relpkg:Relationships/relpkg:Relationship') ?: [];

        foreach ($relationshipNodes as $relationship) {
            if ($this->attribute($relationship, 'Id') === $relationshipId) {
                return $this->normalizeWorksheetTarget($this->attribute($relationship, 'Target'));
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private function normalizeWorksheetTarget(string $target): string
    {
        $target = ltrim($target, '/');

        if (str_starts_with($target, 'xl/')) {
            return $target;
        }

        return 'xl/' . $target;
    }

    private function worksheetRows(SimpleXMLElement $xml, string $namespace): array
    {
        $xml->registerXPathNamespace('main', $namespace);
        $rows = $xml->xpath('/main:worksheet/main:sheetData/main:row');

        if ($rows === false) {
            return [];
        }

        return $rows;
    }

    private function xlsxRowToArray(SimpleXMLElement $row, array $sharedStrings, array $dateStyleIndexes, string $namespace): array
    {
        $row->registerXPathNamespace('main', $namespace);
        $cells = $row->xpath('main:c') ?: [];
        $data = [];
        $sequentialIndex = 0;

        foreach ($cells as $cell) {
            $reference = $this->attribute($cell, 'r');
            $columnIndex = $reference !== ''
                ? $this->columnIndexFromCellReference($reference)
                : $sequentialIndex;

            $data[$columnIndex] = $this->getXlsxCellValue($cell, $sharedStrings, $dateStyleIndexes, $namespace);
            $sequentialIndex++;
        }

        if (empty($data)) {
            return [];
        }

        ksort($data);

        $maxIndex = max(array_keys($data));
        $normalized = [];

        for ($index = 0; $index <= $maxIndex; $index++) {
            $normalized[] = $data[$index] ?? '';
        }

        return $normalized;
    }

    private function getXlsxCellValue(SimpleXMLElement $cell, array $sharedStrings, array $dateStyleIndexes, string $namespace): string
    {
        $type = $this->attribute($cell, 't');
        $styleIndex = (int) $this->attribute($cell, 's', null, '-1');

        $cell->registerXPathNamespace('main', $namespace);

        if ($type === 's') {
            $valueNodes = $cell->xpath('main:v') ?: [];
            $index = isset($valueNodes[0]) ? (int) ((string) $valueNodes[0]) : 0;

            return trim((string) ($sharedStrings[$index] ?? ''));
        }

        if ($type === 'inlineStr') {
            $textNodes = $cell->xpath('.//main:t') ?: [];
            $text = '';

            foreach ($textNodes as $textNode) {
                $text .= (string) $textNode;
            }

            return trim($text);
        }

        if ($type === 'b') {
            $valueNodes = $cell->xpath('main:v') ?: [];
            $rawBoolean = isset($valueNodes[0]) ? (string) $valueNodes[0] : '0';

            return $rawBoolean === '1' ? '1' : '0';
        }

        // Los archivos generados por algunas librerías usan t="str" con <v>texto</v>.
        $valueNodes = $cell->xpath('main:v') ?: [];
        $raw = isset($valueNodes[0]) ? trim((string) $valueNodes[0]) : '';

        if ($raw !== '' && in_array($styleIndex, $dateStyleIndexes, true) && is_numeric($raw)) {
            return $this->excelSerialDateToString((float) $raw);
        }

        return $raw;
    }

    private function attribute(SimpleXMLElement $element, string $name, ?string $namespace = null, string $default = ''): string
    {
        $attributes = $namespace
            ? $element->attributes($namespace)
            : $element->attributes();

        if (! $attributes || ! isset($attributes[$name])) {
            return $default;
        }

        return trim((string) $attributes[$name]);
    }

    private function excelSerialDateToString(float $serial): string
    {
        if ($serial <= 0) {
            return '';
        }

        $days = (int) floor($serial);
        $timestamp = strtotime('1899-12-30 +' . $days . ' days');

        if ($timestamp === false) {
            return (string) $serial;
        }

        return date('Y-m-d', $timestamp);
    }

    private function columnIndexFromCellReference(string $reference): int
    {
        if (! preg_match('/^([A-Z]+)/i', $reference, $matches)) {
            return 0;
        }

        $letters = strtoupper($matches[1]);
        $index = 0;

        for ($i = 0; $i < strlen($letters); $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - ord('A') + 1);
        }

        return $index - 1;
    }

    private function normalizeHeaders(array $headers): array
    {
        return array_map(function ($header) {
            $header = trim((string) $header);
            $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
            $header = mb_strtolower($header, 'UTF-8');

            $replacements = [
                'á' => 'a',
                'é' => 'e',
                'í' => 'i',
                'ó' => 'o',
                'ú' => 'u',
                'ñ' => 'n',
            ];

            $header = strtr($header, $replacements);
            $header = preg_replace('/[^a-z0-9]+/', '_', $header) ?? $header;
            $header = trim($header, '_');

            return $header;
        }, $headers);
    }

    private function buildAssocRow(array $headers, array $rowData, int $rowNumber): array
    {
        $row = [
            '_fila_excel' => $rowNumber,
        ];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }

            $row[$header] = trim((string) ($rowData[$index] ?? ''));
        }

        return $row;
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function spreadsheetNamespace(SimpleXMLElement $xml): string
    {
        $namespaces = $xml->getNamespaces(true);

        if (isset($namespaces[''])) {
            return $namespaces[''];
        }

        foreach ($namespaces as $namespace) {
            if ($namespace === self::SPREADSHEET_NAMESPACE) {
                return $namespace;
            }
        }

        return self::SPREADSHEET_NAMESPACE;
    }

    private function packageRelationshipNamespace(SimpleXMLElement $xml): string
    {
        $namespaces = $xml->getNamespaces(true);

        return $namespaces[''] ?? self::PACKAGE_RELATIONSHIP_NAMESPACE;
    }
}
