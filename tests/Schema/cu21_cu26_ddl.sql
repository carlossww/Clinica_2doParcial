\set ON_ERROR_STOP on
-- Verificacion sin insertar filas, exclusivamente en un servidor de prueba aislado.
BEGIN;
DO $$
BEGIN
    IF current_database() NOT LIKE '%\_test' ESCAPE '\'
        OR inet_server_addr() <> '127.0.0.1'::inet
        OR inet_server_port() <> 55440 THEN
        RAISE EXCEPTION 'Se requiere el servidor aislado local y una base terminada en _test';
    END IF;
END $$;

-- Solo las claves necesarias para probar el DDL; no es una copia de AWS.
CREATE SCHEMA vida_salud;
CREATE TABLE vida_salud.usuario (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY);
CREATE TABLE vida_salud.paciente (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY);
CREATE TABLE vida_salud.cita (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY);

\ir ../../database/sql/2026_11_07_cu21_cu26.sql

DO $$
DECLARE total integer;
BEGIN
    SELECT count(*) INTO total FROM pg_catalog.pg_class c
        JOIN pg_catalog.pg_namespace n ON n.oid=c.relnamespace
        WHERE n.nspname='vida_salud' AND c.relkind='r'
          AND c.relname NOT IN ('usuario','paciente','cita');
    IF total <> 11 THEN RAISE EXCEPTION 'Se esperaban 11 tablas nuevas'; END IF;

    SELECT count(*) INTO total FROM pg_catalog.pg_constraint c
        JOIN pg_catalog.pg_class t ON t.oid=c.conrelid
        JOIN pg_catalog.pg_namespace n ON n.oid=t.relnamespace
        WHERE n.nspname='vida_salud' AND c.contype='f';
    IF total <> 19 THEN RAISE EXCEPTION 'Se esperaban 19 claves foraneas'; END IF;

    IF EXISTS (SELECT 1 FROM pg_catalog.pg_constraint c
        JOIN pg_catalog.pg_class t ON t.oid=c.conrelid
        JOIN pg_catalog.pg_namespace n ON n.oid=t.relnamespace
        WHERE n.nspname='vida_salud' AND NOT c.convalidated) THEN
        RAISE EXCEPTION 'Hay restricciones sin validar';
    END IF;

    SELECT count(*) INTO total FROM information_schema.columns
        WHERE table_schema='vida_salud' AND column_name='subtotal'
          AND table_name IN ('detalle_compra','detalle_venta')
          AND is_generated='ALWAYS' AND numeric_precision=12 AND numeric_scale=2;
    IF total <> 2 THEN RAISE EXCEPTION 'Subtotales generados incorrectos'; END IF;

    SELECT count(*) INTO total FROM pg_catalog.pg_constraint c
        WHERE c.conrelid='vida_salud.movimiento_inventario'::regclass
          AND c.conname IN ('movimiento_lote_producto_fk','movimiento_compra_producto_fk',
            'movimiento_venta_lote_producto_fk') AND c.contype='f' AND cardinality(c.conkey)>1;
    IF total <> 3 THEN RAISE EXCEPTION 'Faltan controles de coherencia por producto/lote'; END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_catalog.pg_constraint
        WHERE conrelid='vida_salud.pago'::regclass AND conname='pago_destino_exclusivo_check'
        AND pg_catalog.pg_get_constraintdef(oid) LIKE '%num_nonnulls(cita_id, venta_id) = 1%') THEN
        RAISE EXCEPTION 'Falta el destino exclusivo del pago';
    END IF;

    SELECT count(*) INTO total FROM pg_catalog.pg_constraint
        WHERE conrelid='vida_salud.transaccion_paypal'::regclass AND contype='u';
    IF total <> 2 THEN RAISE EXCEPTION 'Faltan claves unicas de PayPal'; END IF;

    IF NOT EXISTS (SELECT 1 FROM pg_catalog.pg_constraint
        WHERE conrelid='vida_salud.pago'::regclass AND contype='c'
          AND pg_catalog.pg_get_constraintdef(oid) LIKE '%metodo%'
          AND pg_catalog.pg_get_constraintdef(oid) LIKE '%Efectivo%'
          AND pg_catalog.pg_get_constraintdef(oid) LIKE '%PayPal%'
          AND pg_catalog.pg_get_constraintdef(oid) NOT LIKE '%Transferencia%'
          AND pg_catalog.pg_get_constraintdef(oid) NOT LIKE '%Tarjeta%') THEN
        RAISE EXCEPTION 'Los metodos deben limitarse a Efectivo y PayPal';
    END IF;
END $$;
SELECT 'DDL CU21-CU26 correcto: 11 tablas, 19 FK, restricciones validadas; sin filas insertadas' AS resultado;
ROLLBACK;
