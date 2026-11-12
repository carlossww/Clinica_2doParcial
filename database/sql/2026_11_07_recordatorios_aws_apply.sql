BEGIN;
SET LOCAL lock_timeout='5s';
SET LOCAL statement_timeout='60s';
DO $guard$
BEGIN
 IF current_database() <> 'Parcial2' THEN RAISE EXCEPTION 'Destino inesperado'; END IF;
 IF to_regclass('vida_salud.recordatorio') IS NOT NULL OR to_regclass('vida_salud.historial_recordatorio') IS NOT NULL THEN RAISE EXCEPTION 'Tablas nuevas ya existen: revisar'; END IF;
 IF to_regprocedure('vida_salud.auditar_cambio()') IS NULL THEN RAISE EXCEPTION 'Falta auditoria'; END IF;
END $guard$;
LOCK TABLE vida_salud.migrations IN EXCLUSIVE MODE;
DO $guard$
BEGIN
 IF EXISTS(SELECT 1 FROM vida_salud.migrations WHERE migration='2026_11_07_000010_create_recordatorios_tables') THEN RAISE EXCEPTION 'Migracion ya registrada'; END IF;
END $guard$;
-- CU24 - Gestionar recordatorios
-- DDL para el caso de uso CU24 (Recordatorios para pacientes y médicos)
CREATE TABLE IF NOT EXISTS vida_salud.recordatorio (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    cita_id bigint NOT NULL REFERENCES vida_salud.cita(id) ON DELETE RESTRICT,
    paciente_id bigint NOT NULL REFERENCES vida_salud.paciente(id) ON DELETE RESTRICT,
    medico_id bigint NOT NULL REFERENCES vida_salud.medico(id) ON DELETE RESTRICT,
    destinatario varchar(30) NOT NULL DEFAULT 'Paciente' CHECK (destinatario IN ('Paciente', 'Medico', 'Ambos')),
    medio varchar(30) NOT NULL CHECK (medio IN ('Correo', 'SMS', 'WhatsApp', 'Sistema')),
    contacto_destino varchar(150) NOT NULL CHECK (btrim(contacto_destino) <> ''),
    contacto_doctor varchar(150),
    fecha_programada timestamptz NOT NULL,
    fecha_envio timestamptz,
    estado varchar(30) NOT NULL DEFAULT 'Programado' CHECK (estado IN ('Programado', 'Enviado', 'Fallido', 'Cancelado')),
    mensaje text NOT NULL CHECK (btrim(mensaje) <> ''),
    motivo_cancelacion text,
    error_envio text,
    intentos integer NOT NULL DEFAULT 0 CHECK (intentos >= 0),
    registrado_por bigint NOT NULL REFERENCES vida_salud.usuario(id) ON DELETE RESTRICT,
    creado_en timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT recordatorio_estado_cancelacion_check CHECK (estado <> 'Cancelado' OR NULLIF(btrim(motivo_cancelacion), '') IS NOT NULL),
    CONSTRAINT recordatorio_estado_envio_check CHECK (estado <> 'Enviado' OR fecha_envio IS NOT NULL)
);

CREATE TABLE IF NOT EXISTS vida_salud.historial_recordatorio (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    recordatorio_id bigint NOT NULL REFERENCES vida_salud.recordatorio(id) ON DELETE CASCADE,
    usuario_id bigint REFERENCES vida_salud.usuario(id) ON DELETE RESTRICT,
    accion varchar(50) NOT NULL CHECK (btrim(accion) <> ''),
    estado_anterior varchar(30),
    estado_nuevo varchar(30),
    detalle text,
    fecha timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS recordatorio_cita_idx ON vida_salud.recordatorio(cita_id);
CREATE INDEX IF NOT EXISTS recordatorio_paciente_idx ON vida_salud.recordatorio(paciente_id);
CREATE INDEX IF NOT EXISTS recordatorio_medico_idx ON vida_salud.recordatorio(medico_id);
CREATE INDEX IF NOT EXISTS recordatorio_estado_fecha_idx ON vida_salud.recordatorio(estado, fecha_programada);
CREATE INDEX IF NOT EXISTS historial_recordatorio_rec_idx ON vida_salud.historial_recordatorio(recordatorio_id);

INSERT INTO vida_salud.permiso (codigo, descripcion)
VALUES ('recordatorios.gestionar', 'Gestionar recordatorios de citas para pacientes y médicos')
ON CONFLICT (codigo) DO NOTHING;

INSERT INTO vida_salud.rol_permiso (rol_id, permiso_id)
SELECT r.id, p.id
FROM vida_salud.rol r, vida_salud.permiso p
WHERE r.nombre IN ('Administrador', 'Recepcionista') AND p.codigo = 'recordatorios.gestionar'
ON CONFLICT DO NOTHING;

DROP TRIGGER IF EXISTS auditar ON vida_salud.recordatorio;
CREATE TRIGGER auditar AFTER INSERT OR UPDATE OR DELETE ON vida_salud.recordatorio
    FOR EACH ROW EXECUTE FUNCTION vida_salud.auditar_cambio();
INSERT INTO vida_salud.migrations(migration,batch)
SELECT '2026_11_07_000010_create_recordatorios_tables',COALESCE(MAX(batch),0)+1 FROM vida_salud.migrations;
DO $verify$
BEGIN
 IF (SELECT count(*) FROM information_schema.columns WHERE table_schema='vida_salud' AND table_name IN ('recordatorio','historial_recordatorio')) <> 26 THEN RAISE EXCEPTION 'Columnas inesperadas'; END IF;
 IF (SELECT count(*) FROM pg_constraint WHERE conrelid IN ('vida_salud.recordatorio'::regclass,'vida_salud.historial_recordatorio'::regclass) AND contype='f') <> 6 THEN RAISE EXCEPTION 'FK inesperadas'; END IF;
END $verify$;
COMMIT;
SELECT current_database() AS database, 'recordatorios_aplicados' AS resultado, (SELECT batch FROM vida_salud.migrations WHERE migration='2026_11_07_000010_create_recordatorios_tables') AS batch;
