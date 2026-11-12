import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import { expiredSessionRedirect } from '../../public/assets/session.js';

const source = readFileSync(new URL('../../public/assets/app.js', import.meta.url), 'utf8').replace(/^import .*;\r?\n/gm, '');
function fixture(fetcher) {
    const control = (value = '') => ({ value, listeners: {}, addEventListener(name, fn) { this.listeners[name] = fn; } });
    const fields = { paciente_id: control('23'), medico_id: control('8'), especialidad_id: control('8'), fecha: control('2026-11-09') };
    const hidden = Object.fromEntries(Object.entries(fields).map(([key, field]) => [key, { value: field.value }]));
    const named = (map, selector) => map[selector.match(/name="([^"]+)"/)?.[1]];
    const select = {
        dataset: {}, disabled: false, options: [{ value: '' }, { value: '2026-11-09T08:00:00-04:00' }],
        form: { querySelector: (selector) => named(hidden, selector) },
        replaceChildren(...options) { this.options = options; },
        add(option) { this.options.push(option); }, setAttribute() {}, removeAttribute() {},
    };
    const status = { textContent: '', classList: { toggle() {} } };
    const submit = { disabled: false };
    const retry = control();
    const filters = {
        elements: { namedItem: (name) => fields[name] },
        querySelector: (selector) => selector === '[name="medico_id"]' ? null : named(fields, selector),
    };
    const section = {
        dataset: { availability: 'http://internal.example/citas/disponibilidad' },
        querySelector(selector) {
            return ({ '[name="inicio"]': select, '[data-availability-status]': status,
                '[data-availability-retry]': retry, '[data-book-appointment]': submit,
                '[data-appointment-filter]': filters })[selector] || named(fields, selector);
        },
    };
    const navigations = [];
    vm.runInNewContext(source, {
        document: {
            querySelectorAll: (selector) => selector === '[data-availability]' ? [section] : [],
            querySelector: (selector) => selector.startsWith('meta') ? { content: '/ingresar' } : null,
        },
        window: { location: { origin: 'https://clinica.example', assign: (url) => navigations.push(url) }, addEventListener() {} },
        URL, URLSearchParams, AbortController, expiredSessionRedirect, fetch: fetcher,
        Option: class { constructor(label, value) { this.label = label; this.value = value; } },
    });
    return { fields, hidden, select, submit, retry, status, navigations };
}

test('abrir la página conserva los turnos HTML sin repetir la consulta', () => {
    let calls = 0;
    const f = fixture(() => { calls++; throw Error('No debe consultar'); });
    assert.equal(calls, 0);
    assert.equal(f.select.options.length, 2);
    assert.equal(f.select.disabled, false);
    assert.equal(f.submit.disabled, false);
});

test('actualizar consulta en el origen HTTPS actual y sincroniza los datos a confirmar', async () => {
    let request;
    const f = fixture(async (url) => {
        request = url;
        return { status: 200, ok: true, json: async () => ({ slots: [{ inicio: 'nuevo', label: 'Turno nuevo' }] }) };
    });
    f.fields.paciente_id.value = '24';
    await f.fields.paciente_id.listeners.change();
    assert.equal(request.origin, 'https://clinica.example');
    assert.equal(request.pathname, '/citas/disponibilidad');
    assert.equal(request.searchParams.get('paciente_id'), '24');
    assert.equal(request.searchParams.get('fecha'), '2026-11-09');
    assert.equal(f.hidden.paciente_id.value, '24');
    assert.equal(f.select.disabled, false);
});

test('422 comunica el campo rechazado como texto y bloquea la confirmación', async () => {
    const f = fixture(async () => ({ status: 422, ok: false, json: async () => ({ errors: { fecha: ['Fecha inválida <prueba>.'] } }) }));
    await f.retry.listeners.click();
    assert.equal(f.status.textContent, 'Fecha inválida <prueba>.');
    assert.equal(f.select.disabled, true);
    assert.equal(f.submit.disabled, true);
});

test('médico situado fuera de filtros actualiza el turno y el identificador oculto', async () => {
    const f = fixture(async () => ({ status: 200, ok: true, json: async () => ({ slots: [{ inicio: 'otro', label: 'Otro turno' }] }) }));
    f.fields.medico_id.value = '9';
    await f.fields.medico_id.listeners.change();
    assert.equal(f.hidden.medico_id.value, '9');
    assert.equal(f.select.options[1].value, 'otro');
    assert.equal(f.submit.disabled, false);
});

test('fallo de conexión no permite confirmar ni se presenta como sesión vencida', async () => {
    const f = fixture(async () => { throw new TypeError('Failed to fetch'); });
    await f.retry.listeners.click();
    assert.match(f.status.textContent, /conexión/);
    assert.equal(f.submit.disabled, true);
    assert.equal(f.navigations.length, 0);
});

test('sesión vencida conserva la navegación al ingreso sin reservar', async () => {
    const f = fixture(async () => ({ status: 401, ok: false, json: async () => ({}) }));
    await f.retry.listeners.click();
    assert.deepEqual(f.navigations, ['/ingresar']);
    assert.equal(f.submit.disabled, true);
});
