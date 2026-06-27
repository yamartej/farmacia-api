<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalidaItem extends Model
{
    protected $table = 'salida_items';
    protected $fillable = [
        'salida_id',
        'medicamento_id',
        'lote',
        'fecha_vencimiento',
        'cantidad',
    ];

    public function salida()
    {
        return $this->belongsTo(Salida::class);
    }

    public function medicamento()
    {
        return $this->belongsTo(Medicamento::class);
    }
}
