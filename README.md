# Sistema de Gestión - Clínica Vida Salud

## Descripción del proyecto

Este proyecto fue realizado como parte de un examen de la materia. Consiste en desarrollar un sistema web para la gestión de la Clínica Vida Salud, con el objetivo de facilitar la organización de sus actividades y mejorar el manejo de la información.

El sistema permite registrar y administrar pacientes, gestionar citas médicas y horarios, registrar consultas, diagnósticos y tratamientos, y gestionar órdenes y resultados de laboratorio. También incluye roles de usuario, un portal para pacientes, reportes, bitácora de auditoría, pagos y gestión de inventario y abastecimiento.

Este README describe el código de esta carpeta. No acredita que cada función o migración esté instalada o desplegada.

## Organización funcional del backend

El código editable se agrupa por paquetes funcionales: `GestionAbastecimiento` gestiona proveedores y compras; `GestionInventarioMedico`, productos, lotes e inventario; y `GestionFinanciera`, pagos y ventas. Las pantallas genéricas compartidas de catálogo están bajo `app/Modules/Shared/`. Las rutas actuales se conservan aunque algunos controladores hayan cambiado de carpeta.
## Tecnologías utilizadas

- PHP 8.4.1 o superior para satisfacer las dependencias fijadas en `composer.lock` (aunque `composer.json` declara PHP `^8.3`).
- Laravel 13.
- PostgreSQL.
- Blade.
- HTML, CSS y JavaScript.

## Requisitos

Antes de instalar el proyecto, es necesario tener instalado:

- PHP 8.4.1 o superior, con `pdo_pgsql`, OpenSSL, mbstring y las extensiones requeridas por Composer.
- Composer 2.
- PostgreSQL.
- PowerShell 5.1 o superior para los scripts de Windows.
- Node.js y npm si se ejecutan las pruebas de JavaScript o se compilan recursos con Vite.

## Instalación

### 1. Descargar el proyecto

Clonar el repositorio indicado:

```bash
git clone https://github.com/nanahhlina/Sistema-de-Gesti-n-para-la-Cl-nica-Vida-Salud.git
cd Sistema-de-Gesti-n-para-la-Cl-nica-Vida-Salud
```

La procedencia de esta URL no se ha cotejado con la carpeta de trabajo. Si el proyecto ya está descargado, abrir la terminal en `Codigo App Web/`, donde se encuentran `artisan` y `scripts/`.

### 2. Crear la base de datos

En PostgreSQL, crear o seleccionar la base de datos que se configurará para la aplicación. El instalador sugiere `Parcial2` como nombre predeterminado. Laravel usa el esquema `vida_salud` (con `public` en la ruta de búsqueda).

Si la base ya contiene información, respaldarla y comparar su esquema y las migraciones registradas antes de instalar. No ejecutar el instalador contra una base operativa sin revisar primero su efecto.

### 3. Instalar y configurar

Desde `Codigo App Web/`, en PowerShell, ejecutar:

```powershell
.\scripts\install.ps1
```

El instalador puede crear `.env`, solicita conexión si aún no está configurada, instala dependencias, prepara la clave de Laravel si falta y ejecuta `artisan migrate --seed`. Por tanto, puede modificar el esquema y cargar datos iniciales; confirmar antes que la conexión apunte al entorno previsto.

Para volver a solicitar la configuración de la base de datos:

```powershell
.\scripts\install.ps1 -ConfigureDatabase
```

No sobrescribir un `.env` existente sin revisar su configuración. Mantener el archivo local y fuera del repositorio y los ZIP de producción.

### 4. Iniciar el sistema

Desde `Codigo App Web/`, ejecutar en Windows:

```powershell
php artisan serve
```

Abrir en el navegador:

[http://127.0.0.1:8000](http://127.0.0.1:8000)

El PHP de la terminal debe cumplir los requisitos y contar con `pdo_pgsql`. Para detener el servidor, presionar `Ctrl + C`. Este servidor está destinado al desarrollo y las pruebas locales.

## Usuarios de prueba

El seeder define estas cuentas de demostración para probar funciones asociadas a los roles. La existencia de los nombres no confirma que estén activos en una base determinada ni que conserven una contraseña inicial; solicitar acceso al administrador.

| Usuario | Función |
|---|---|
| `admin` | Administración general del sistema |
| `recepcion` | Gestión de pacientes, médicos, especialidades y citas |
| `medico` | Agenda, consultas, historias clínicas, diagnósticos y tratamientos propios |
| `laboratorio` | Gestión de órdenes y resultados de laboratorio; rol `Laboratorista` |
| `paciente` | Acceso a sus citas, historial y resultados publicados |

El código contiene una vista antigua de solicitud de registro, pero no hay una ruta pública conectada para crear cuentas. Las cuentas se crean o activan mediante administración. Las contraseñas no se publican en este README.

## Funcionalidades principales

- Inicio y cierre de sesión con auditoría y confirmación del cierre.
- Gestión de usuarios, roles y permisos.
- Registro y administración de pacientes, contacto de emergencia, vínculo cuenta-paciente e historial de citas.
- Gestión de médicos, especialidades y horarios.
- Consulta de disponibilidad, reserva, cancelación y reprogramación de citas; agenda con filtros.
- Registro de consultas, diagnósticos y tratamientos, según permisos e identidad clínica.
- Gestión de órdenes de laboratorio, análisis, resultados y adjuntos privados; el paciente consulta resultados publicados.
- Portal del paciente con acceso a sus citas, reserva e información autorizada.
- Precios de consulta, tasa manual BOB/USD, cobros en efectivo o PayPal Sandbox, comprobantes informativos y devoluciones completas.
- Productos, proveedores, compras, recepciones, ventas, lotes, inventario, kardex y movimientos.
- Programación, consulta e historial de recordatorios. Los envíos por correo, SMS y WhatsApp son simulados.
- Cinco tipos de reporte: citas, pacientes registrados, atenciones, órdenes y resultados de laboratorio.
- Bitácora de auditoría con filtros por usuario, acción, entidad y fechas, y vista de detalle.

**Acceso a reportes:** el controlador de `/reportes` permite el panel al rol Administrador. El seeder también asigna permisos de reportes a Recepcionista, Medico y Laboratorista, pero esos permisos no habilitan actualmente la ruta por sí solos. La diferencia está pendiente de revisión en el código.

## Consideraciones

- `.env` contiene configuración local y no debe subirse al repositorio ni incluirse en ZIP de producción.
- Las pruebas automatizadas requieren PostgreSQL, `APP_ENV=testing` y una base de datos aislada cuyo nombre termine en `_test`; `phpunit.xml` propone `Parcial2_test`. Algunas pruebas reinician el esquema `vida_salud`, por lo que nunca deben ejecutarse contra una base operativa.
- No ejecutar `migrate:fresh`, restauraciones destructivas ni reconstrucciones sobre la base operativa `Parcial2`.
- Los comandos de conciliación, limpieza y recordatorios dependen de un scheduler activo. La configuración permanente en AWS no está verificada; los envíos externos de recordatorios son simulados.
- Las pruebas registradas en notas de trabajo son históricas, no una verificación realizada al actualizar este README.
- El despliegue documentado usa AWS Elastic Beanstalk; la versión, URL y estado actuales del despliegue no se han verificado.

---

**Proyecto para el 2do Parcial de Ingeniería de Requisitos y Modelado — Sistema de Gestión de la Clínica Vida Salud.**
