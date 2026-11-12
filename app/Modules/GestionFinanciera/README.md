# Gestión financiera

CU21 y soporte de CU22: cobros completos BOB, efectivo/recibido/cambio, comprobantes y devolución completa por administración. Consultas canceladas y ventas sin entrega en resolución son los destinos reembolsables; se conserva el registro original.

PayPalGateway usa exclusivamente api-m.sandbox.paypal.com y variables services.paypal (PAYPAL_SANDBOX_ENABLED, PAYPAL_CLIENT_ID, PAYPAL_CLIENT_SECRET, PAYPAL_MERCHANT_ID). Sin credenciales de fallback, Live, payloads completos persistidos ni tokens almacenados. Tasas/importe comercial/importe USD y destinatario se congelan por intento. No incluir valores sensibles en documentación, registros o paquetes.

PayPalService coordina propietario UUID/versiones/lease3min en PostgreSQL, llamadas HTTP fuera de transacciones y claves estables por operación derivadas de clave_pasarela. Verifica API antes de confirmar; errores inciertos mantienen pendiente y bloquean otro cobro. Reintentos automáticos conservadores5min, después conciliación administrativa verificada de ID conocido. No interpretar retorno GET como aprobación financiera.

Comando paypal:conciliar cada minuto, lotes100 ordenados por última verificación para no bloquear pagos recientes tras órdenes sin aprobar. El lease de base es la exclusión entre procesos, además de withoutOverlapping. Un reembolso solicitado exige captura completada y bloquea entrega hasta resolución. Se verifica propiedad justo antes del POST financiero y al aplicar respuesta.

Migraciones000008/000009 siguen a000007 y dependencias anteriores; solo se probaron en PostgreSQL aislado _test. AWS no actualizado ni código desplegado. Evidencia real de Sandbox pendiente por falta de credenciales; pruebas simuladas no equivalen a una captura externa comprobada.

Guía y evidencia: 03 Seguimiento/CU21-CU26 - Guía de prueba manual local - 2026-11-07.md y 03 Seguimiento/CU21-CU26 - Fases 3 4 5 y validación consolidada - 2026-11-07.md desde la raíz de Obsidian. .runtime contiene auxiliares de pruebas protegidos por TestDatabaseSafety, no rutas o configuración de producción.


## Ventas

La operación de ventas se encuentra en `Controllers/VentaController.php` y `Services/VentaService.php`, junto a pagos y cobros. El servicio usa `GestionInventarioMedico` para descontar inventario. Las rutas públicas existentes se mantienen en `Codigo App Web/routes/web.php`.
