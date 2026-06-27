<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Medicamento extends Model
{
    use HasFactory;

    protected $fillable = [
        'nombre',
        'presentacion',
        'categoria',
        'unidad',
        'descripcion',
        'fecha_vencimiento',
    ];

    // Stock dinámico
    protected $appends = ['stock'];

    public function movimientos()
    {
        return $this->hasMany(Movimiento::class);
    }

    public function getStockAttribute()
    {
        $entradas = $this->movimientos()
            ->where('tipo', 'entrada')
            ->sum('cantidad');

        $salidas = $this->movimientos()
            ->where('tipo', 'salida')
            ->sum('cantidad');

        return $entradas - $salidas;
    }
}
