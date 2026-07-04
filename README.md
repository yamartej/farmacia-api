# Sistema de Farmacia - API

API REST desarrollada con **Laravel 12** y **Laravel Sanctum** para el sistema de gestión de farmacia.

Este backend es consumido por el frontend ubicado en el repositorio `farmacia`.

## Funcionalidades principales

- Autenticación con token Bearer mediante Sanctum.
- CRUD de medicamentos.
- Registro y edición de donaciones.
- Registro y edición de salidas.
- Movimientos de inventario.
- Control de stock general y por lote.
- Validación de stock antes de registrar salidas.

## Requisitos

- PHP 8.2 o superior.
- Composer.
- SQLite, MySQL o MariaDB.
- Node.js / npm si se usan assets del proyecto Laravel.

## Instalación

```bash
composer install
cp .env.example .env
php artisan key:generate
```

## Base de datos

Por defecto puedes usar SQLite:

```bash
php artisan migrate
php artisan db:seed
```

Para MySQL/XAMPP, configura en `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=farmacia
DB_USERNAME=root
DB_PASSWORD=
```

Luego ejecuta:

```bash
php artisan migrate
php artisan db:seed
```

## Ejecutar API

```bash
php artisan serve
```

URL local por defecto:

```text
http://127.0.0.1:8000
```

## CORS

El frontend debe estar dentro de `CORS_ALLOWED_ORIGINS`.

Ejemplo local:

```env
CORS_ALLOWED_ORIGINS=http://localhost:3000,http://127.0.0.1:3000
```

Cuando publiques el frontend, agrega el dominio de producción.

## Endpoints principales

### Autenticación

```text
POST /api/login
GET  /api/user
POST /api/logout
```

### Medicamentos

```text
GET    /api/medicamentos
POST   /api/medicamentos
GET    /api/medicamentos/{id}
PUT    /api/medicamentos/{id}
DELETE /api/medicamentos/{id}
GET    /api/medicamentos/{id}/lotes
```

### Donaciones

```text
GET    /api/donaciones
POST   /api/donaciones
GET    /api/donaciones/{id}
PUT    /api/donaciones/{id}
DELETE /api/donaciones/{id}
```

### Salidas

```text
GET    /api/salidas
POST   /api/salidas
GET    /api/salidas/{id}
PUT    /api/salidas/{id}
DELETE /api/salidas/{id}
```

### Movimientos

```text
GET  /api/movimientos
POST /api/movimientos/entrada
POST /api/movimientos/salida
GET  /api/movimientos/medicamento/{id}
```

## Notas de mejora pendientes

- Crear endpoint de estadísticas para alimentar el dashboard.
- Optimizar cálculo de stock en listados grandes.
- Agregar roles de usuario.
- Agregar pruebas automatizadas para login, donaciones, salidas y stock.
