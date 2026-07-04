<?php

namespace App\Http\Controllers;

use App\Models\Medicamento;
use Illuminate\Http\Response;

class MedicamentoCatalogController extends Controller
{
    public function excel(): Response
    {
        $medicamentos = Medicamento::query()
            ->orderBy('nombre')
            ->orderBy('presentacion')
            ->get();

        $headers = [
            'medicamento_id',
            'nombre',
            'presentacion',
            'categoria',
            'unidad',
            'stock',
            'descripcion',
        ];

        $generatedAt = now()->format('Y-m-d H:i:s');

        $html = '<!DOCTYPE html>';
        $html .= '<html>';
        $html .= '<head>';
        $html .= '<meta charset="UTF-8">';
        $html .= '<style>';
        $html .= 'body{font-family:Arial,sans-serif;}';
        $html .= 'h1{color:#891200;}';
        $html .= 'p{color:#1D1D1F;}';
        $html .= 'table{border-collapse:collapse;width:100%;}';
        $html .= 'th{background:#891200;color:#ffffff;font-weight:bold;border:1px solid #1D1D1F;padding:8px;text-align:left;}';
        $html .= 'td{border:1px solid #1D1D1F;padding:8px;mso-number-format:"\@";}';
        $html .= '</style>';
        $html .= '</head>';
        $html .= '<body>';
        $html .= '<h1>Catálogo de medicamentos</h1>';
        $html .= '<p>Banco de Medicamentos San José Gregorio Hernández - Zona 4</p>';
        $html .= '<p>Generado: ' . $this->escape($generatedAt) . '</p>';
        $html .= '<table>';
        $html .= '<thead><tr>';

        foreach ($headers as $header) {
            $html .= '<th>' . $this->escape($header) . '</th>';
        }

        $html .= '</tr></thead>';
        $html .= '<tbody>';

        foreach ($medicamentos as $medicamento) {
            $html .= '<tr>';
            $html .= '<td>' . $this->escape((string) $medicamento->id) . '</td>';
            $html .= '<td>' . $this->escape((string) $medicamento->nombre) . '</td>';
            $html .= '<td>' . $this->escape((string) ($medicamento->presentacion ?? '')) . '</td>';
            $html .= '<td>' . $this->escape((string) ($medicamento->categoria ?? '')) . '</td>';
            $html .= '<td>' . $this->escape((string) ($medicamento->unidad ?? '')) . '</td>';
            $html .= '<td>' . $this->escape((string) ($medicamento->stock ?? 0)) . '</td>';
            $html .= '<td>' . $this->escape((string) ($medicamento->descripcion ?? '')) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody>';
        $html .= '</table>';
        $html .= '</body>';
        $html .= '</html>';

        return response("\xEF\xBB\xBF" . $html, 200, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="catalogo-medicamentos.xls"',
            'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
