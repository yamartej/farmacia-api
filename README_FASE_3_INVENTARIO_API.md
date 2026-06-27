# Fase 3 - Reingeniería del inventario en la API

Esta fase corrige la lógica de inventario de la API Laravel.

## Problemas corregidos

### 1. Donaciones sin movimientos de entrada

Antes, al registrar una donación se creaban la donación y sus ítems, pero no se creaban movimientos de entrada. Como el stock del medicamento se calcula desde `movimientos`, esas donaciones no aumentaban correctamente el stock.

Ahora, cada ítem de donación genera un movimiento:

```txt
tipo: entrada
origen: donacion
origen_id: id del item de donación
```

### 2. Salidas sin validación centralizada de stock

Se agregó `InventarioService` para centralizar:

- registro de entradas;
- registro de salidas;
- validación de stock disponible;
- reversos de entradas;
- reversos de salidas.

### 3. Eliminación de donaciones y salidas

Ahora, cuando se elimina una donación, se revierte su entrada creando movimientos de salida de ajuste.

Cuando se elimina una salida, se revierte creando movimientos de entrada de ajuste.

Esto conserva mejor la trazabilidad que borrar movimientos históricos.

### 4. Filtro de stock bajo

Antes se usaba:

```php
$query->where('cantidad', '<=', 10);
```

Eso era incorrecto porque `cantidad` no es una columna principal de `medicamentos`. El stock se calcula desde movimientos.

Ahora el filtro usa subconsultas sobre movimientos de entrada y salida.

### 5. Stock por lote

El método `lotes()` ahora cruza:

- `donacion_items` como entradas;
- `salida_items` como salidas;

y devuelve stock por lote y fecha de vencimiento.

## Archivos incluidos

```txt
app/Services/InventarioService.php
app/Http/Controllers/DonacionController.php
app/Http/Controllers/SalidaController.php
app/Http/Controllers/MedicamentoController.php
app/Http/Controllers/MovimientoController.php
```

## Cómo aplicar

Copiar el contenido de este paquete dentro de:

```powershell
C:\xampp\htdocs\farmacia-api
```

Aceptar reemplazar archivos existentes.

## Comandos recomendados

```powershell
cd C:\xampp\htdocs\farmacia-api

php artisan optimize:clear
php artisan config:clear
php artisan route:clear

php artisan test
php artisan serve
```

## Pruebas manuales recomendadas

### 1. Crear medicamento

Crear un medicamento desde API o sistema.

### 2. Crear donación

Registrar una donación con al menos un ítem.

Validar que ahora exista movimiento de entrada:

```powershell
Invoke-RestMethod -Method Get `
  -Uri http://127.0.0.1:8000/api/movimientos `
  -Headers @{ "Accept"="application/json"; "Authorization"="Bearer TU_TOKEN" }
```

### 3. Validar stock

Consultar medicamentos:

```powershell
Invoke-RestMethod -Method Get `
  -Uri http://127.0.0.1:8000/api/medicamentos `
  -Headers @{ "Accept"="application/json"; "Authorization"="Bearer TU_TOKEN" }
```

### 4. Crear salida

Crear una salida con cantidad menor o igual al stock disponible.

### 5. Probar salida mayor al stock

Debe devolver error 422 con mensaje de stock insuficiente.

## Importante

Esta fase no cambia migraciones ni estructura de base de datos. Corrige la lógica actual usando las tablas existentes.

## Git recomendado

```powershell
git checkout -b reingenieria-fase-3-inventario-api
git add .
git commit -m "Corrige logica de inventario y movimientos"
git push -u origin reingenieria-fase-3-inventario-api
```
