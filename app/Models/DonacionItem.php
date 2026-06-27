<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DonacionItem extends Model
{
    use HasFactory;

    protected $table = 'donacion_items';

    protected $fillable = [
        'donacion_id',
        'medicamento_id',
        'cantidad',
        'lote',
        'fecha_vencimiento',
    ];

    public function medicamento()
    {
        return $this->belongsTo(Medicamento::class);
    }

    public function donacion()
    {
        return $this->belongsTo(Donacion::class);
    }
}
