<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Salida extends Model
{
    protected $table = 'salidas';
    protected $fillable = [
        'responsable',
        'tipo_salida',
        'destino',
        'fecha_salida',
        'descripcion',
    ];

    public function items()
    {
        return $this->hasMany(SalidaItem::class);
    }
}
