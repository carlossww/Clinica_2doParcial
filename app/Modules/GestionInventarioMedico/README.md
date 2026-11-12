# Gestión de inventario médico

Este paquete agrupa el CU23 del análisis: catálogo de productos médicos, lotes, movimientos y consulta de inventario/kardex.

- Controladores: `Controllers/ProductoController.php`, `Controllers/LoteController.php` e `Controllers/InventarioController.php`.
- Servicios: `Services/InventarioService.php` y `Services/LoteService.php`.
- El formulario genérico de catálogo se comparte desde `App\Modules\Shared`; compras consume inventario desde `GestionAbastecimiento` y ventas desde `GestionFinanciera`.

Se conserva la infraestructura de rutas existente (`/abastecimiento/...`, `/lotes`, `/inventario` y `/kardex`) para no cambiar enlaces ni contratos HTTP. Se reutilizan tablas y movimientos canónicos; esta reorganización no agrega tablas ni altera migraciones.

La separación de paquetes se revisó estáticamente en el código local el 12/11/2026. No se ejecutaron pruebas funcionales ni se verificó el despliegue con este cambio.