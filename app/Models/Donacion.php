<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Donacion extends Model
{
    protected $table = 'donaciones';

    protected $fillable = [
        'donante',
        'tipo_donante',
        'telefono',
        'fecha_donacion',
        'lote',  // 👈 agregado
        'descripcion',
    ];

    public function items()
    {
        return $this->hasMany(DonacionItem::class);
    }
}
