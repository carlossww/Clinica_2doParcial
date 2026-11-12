# Gestión de abastecimiento

Este paquete agrupa la gestión de **proveedores y compras** del análisis funcional. Sus controladores y servicios propios están en `Controllers/ProveedorController.php`, `Controllers/CompraController.php` y `Services/CompraService.php`. La gestión de productos, lotes e inventario médico pertenece a `GestionInventarioMedico`; las ventas y cobros pertenecen a `GestionFinanciera`.

El formulario genérico de catálogos que comparten Proveedores y Productos está en `Shared/Controllers/CatalogoAbastecimientoController.php` y `Shared/Services/CatalogoAbastecimientoService.php`. Esa clase compartida no traslada la responsabilidad de los módulos dueños de cada catálogo.

Las rutas existentes `/abastecimiento/...`, `/compras`, `/lotes`, `/inventario` y `/kardex` conservan sus URI y nombres; su controlador ahora está ubicado según su paquete funcional. Se reutilizan las tablas canónicas y el protocolo de inventario existente; este cambio no añade tablas ni modifica migraciones.

## Evidencia y alcance

La separación de paquetes fue revisada estáticamente en el código local el 12/11/2026. Las rutas se conservaron en `routes/web.php`. No se ejecutaron pruebas funcionales ni se verificó el despliegue con esta reorganización.

Las notas de fases anteriores conservan su valor histórico en [[03 Seguimiento/CU21-CU26 - Fase 2 abastecimiento e inventario - 2026-11-07]] y [[03 Seguimiento/CU21-CU26 - Fases 3 4 5 y validación consolidada - 2026-11-07]].