import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../public/assets/payment.js', import.meta.url), 'utf8');
function page(clipboard, autoVerify = false) {
    const status = { textContent: '' };
    const field = { value: 'https://www.sandbox.paypal.com/checkoutnow?token=example', focus() { this.focused = true; }, select() { this.selected = true; } };
    const copy = { addEventListener(_, callback) { this.click = callback; } };
    const button = { textContent: '' };
    const form = autoVerify ? { querySelector: () => button, requestSubmit() { this.submitted = (this.submitted || 0) + 1; } } : null;
    const document = { querySelector: (selector) => selector === '[data-copy-payment]' ? copy : form, getElementById: (id) => id === 'paypal-link' ? field : status };
    vm.runInNewContext(source, { document, navigator: { clipboard } });
    return { status, field, copy, form, button };
}

test('copia el enlace aprobado y confirma la acción', async () => {
    let value;
    const f = page({ writeText: async (text) => { value = text; } });
    await f.copy.click();
    assert.equal(value, f.field.value);
    assert.match(f.status.textContent, /Enlace copiado/);
});

test('si el portapapeles falla permite copiar el enlace manualmente', async () => {
    const f = page({ writeText: async () => { throw Error('denied'); } });
    await f.copy.click();
    assert.equal(f.field.focused, true);
    assert.equal(f.field.selected, true);
    assert.match(f.status.textContent, /copia el enlace/);
});

test('el regreso del cliente envía una sola verificación POST', () => {
    const f = page(null, true);
    assert.equal(f.form.submitted, 1);
    assert.equal(f.button.textContent, 'Verificando…');
});
