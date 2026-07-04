<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MedicamentoController;
use App\Http\Controllers\DonacionController;
use App\Http\Controllers\MovimientoController;
use App\Http\Controllers\SalidaController;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [AuthController::class, 'logout']);

    /* Medicamentos CRUD */
    Route::get('/medicamentos', [MedicamentoController::class, 'index']);
    Route::post('/medicamentos', [MedicamentoController::class, 'store']);
    Route::get('/medicamentos/{id}', [MedicamentoController::class, 'show']);
    Route::put('/medicamentos/{id}', [MedicamentoController::class, 'update']);
    Route::delete('/medicamentos/{id}', [MedicamentoController::class, 'destroy']);
    Route::get('/medicamentos/{id}/lotes', [MedicamentoController::class, 'lotes']);

    /* Donaciones */
    Route::get('/donaciones', [DonacionController::class, 'index']);
    Route::get('/donaciones/{id}', [DonacionController::class, 'show']);
    Route::post('/donaciones', [DonacionController::class, 'store']);
    Route::put('/donaciones/{donacion}/items/{item}', [DonacionController::class, 'actualizarItem']);
    Route::delete('/donaciones/{donacion}/items/{item}', [DonacionController::class, 'eliminarItem']);
    Route::post('/donaciones/{donacion}/items', [DonacionController::class, 'agregarItem']);
    Route::put('/donaciones/{id}', [DonacionController::class, 'update']);
    Route::delete('/donaciones/{id}', [DonacionController::class, 'destroy']);

    /* Entradas y salidas manuales */
    Route::post('/movimientos/entrada', [MovimientoController::class, 'entrada']);
    Route::post('/movimientos/salida', [MovimientoController::class, 'salida']);

    /* Kardex por medicamento */
    Route::get('/movimientos/medicamento/{id}', [MovimientoController::class, 'movimientosPorMedicamento']);

    /* Movimientos generales */
    Route::get('/movimientos', [MovimientoController::class, 'index']);

    /* Salidas */
    Route::get('/salidas', [SalidaController::class, 'index']);
    Route::post('/salidas', [SalidaController::class, 'store']);
    Route::get('/salidas/{id}', [SalidaController::class, 'show']);
    Route::put('/salidas/{id}', [SalidaController::class, 'update']);
    Route::delete('/salidas/{id}', [SalidaController::class, 'destroy']);
});
