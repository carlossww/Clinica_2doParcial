import test from 'node:test';
import assert from 'node:assert/strict';
import { expiredSessionRedirect, expiredSessionMessage } from '../../public/assets/session.js';
import { requestLogout } from '../../public/assets/logout.js';

test('401 muestra aviso y elige ingreso local sin reenviar operaciones', async () => {
    let notice;
    const target = await expiredSessionRedirect({ status: 401, json: async () => ({ message: expiredSessionMessage, redirect: 'https://example.invalid' }) }, {
        loginUrl: '/ingresar', onMessage: (message) => { notice = message; },
    });
    assert.equal(target, '/ingresar');
    assert.equal(notice, expiredSessionMessage);
});

test('CSRF 419, prohibición y éxito no se interpretan como expiración', async () => {
    for (const status of [200, 403, 419, 422, 500]) {
        assert.equal(await expiredSessionRedirect({ status }, { loginUrl: '/ingresar', onMessage: () => assert.fail('aviso inesperado') }), null);
    }
});

test('401 sin JSON conserva aviso comprensible', async () => {
    let notice;
    assert.equal(await expiredSessionRedirect({ status: 401, json: async () => { throw new Error('json'); } }, {
        loginUrl: '/ingresar', onMessage: (message) => { notice = message; },
    }), '/ingresar');
    assert.equal(notice, expiredSessionMessage);
});

test('salida sin sesión muestra aviso y no reintenta un HTTP 401', async () => {
    let calls = 0;
    let notice;
    const target = await requestLogout({ url: '/salir', csrfToken: 'token', loginUrl: '/ingresar', onExpired: (message) => { notice = message; } }, async () => {
        calls++;
        return { status: 401, json: async () => ({ message: expiredSessionMessage }) };
    });
    assert.equal(target, '/ingresar');
    assert.equal(calls, 1);
    assert.equal(notice, expiredSessionMessage);
});
