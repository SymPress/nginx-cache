const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const page = fs.readFileSync(path.join(__dirname, '../../src/Admin/SettingsPage.php'), 'utf8');
const scripts = page.slice(page.indexOf('private function renderScripts()'));
const script = scripts.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/<\?php[\s\S]*?\?>/g, 'null');
const prefix = 'sympress_nginx_cache_';

function events(target = {}) {
    const handlers = new Map();
    return Object.assign(target, {
        addEventListener(name, handler) {
            handlers.set(name, [...(handlers.get(name) || []), handler]);
        },
        async fire(name, event = {}) {
            for (const handler of handlers.get(name) || []) await handler(event);
        },
        click() {},
    });
}

function fixture(active = 'dashboard') {
    const panels = ['dashboard', 'cache', 'preload', 'advanced', 'cdn'].map(id => {
        let selected = id === active;
        return {
            dataset: { sympressPanel: id },
            classList: {
                contains() { return selected; },
                toggle(name, value) { selected = value; },
            },
            querySelectorAll() { return fields.filter(field => field.panel === this); },
        };
    });
    const fields = [];
    function field(name, value, panel, type = 'text', extra = {}) {
        const result = {
            name, value, type, disabled: false,
            panel: panels.find(item => item.dataset.sympressPanel === panel) || null,
            closest() { return this.panel; },
            ...extra,
        };
        fields.push(result);
        return result;
    }
    field('option_page', 'sympress_nginx_cache', null, 'hidden');
    field('action', 'update', null, 'hidden');
    field('_wpnonce', 'test-only-nonce', null, 'hidden');
    field('nginx_cache_path', '/var/cache/nginx/wordpress', 'dashboard');
    field('nginx_cache_path', '/var/cache/nginx/wordpress', 'cache');
    field(prefix + 'prewarm_enabled', '0', 'preload', 'hidden');
    const prewarm = field(prefix + 'prewarm_enabled', '1', 'preload', 'checkbox', { checked: true });
    field(prefix + 'prewarm_urls', 'https://example.test/important/', 'dashboard', 'textarea');
    field(prefix + 'prewarm_urls', 'https://example.test/important/', 'preload', 'textarea');
    field(prefix + 'valid_seconds', '600', 'cache', 'number');
    field(prefix + 'queue_enabled', '0', 'cache', 'hidden');
    field(prefix + 'queue_enabled', '1', 'cache', 'checkbox', { checked: false });
    field(prefix + 'url_purge_roles[]', '', 'advanced', 'hidden');
    field(prefix + 'url_purge_roles[]', 'editor', 'advanced', 'checkbox', { checked: true });
    field(prefix + 'url_purge_roles[]', 'author', 'advanced', 'checkbox', { checked: false });
    const secret = field(prefix + 'remote_secret', 'test-only-unsaved-secret', 'cdn', 'password');
    field(prefix + 'remote_secret_clear', '1', 'cdn', 'checkbox', { checked: true });
    field(prefix + 'profile', 'safe', 'cache', 'select-one', { disabled: true });

    const form = events({
        elements: fields,
        querySelectorAll(selector) {
            const name = selector.match(/^\[name="(.*)"\]$/)[1];
            return fields.filter(item => item.name === name);
        },
    });
    const exportButton = events();
    const importInput = events();
    let download;
    const root = {
        querySelectorAll(selector) { return selector === '[data-sympress-panel]' ? panels : []; },
        querySelector(selector) {
            return {
                '[data-sympress-settings-form]': form,
                '[data-sympress-dirty-state]': { textContent: '' },
                '[data-sympress-export-settings]': exportButton,
                '[data-sympress-import-settings-trigger]': events(),
                '[data-sympress-import-settings]': importInput,
            }[selector] || null;
        },
    };
    vm.runInNewContext(script, {
        document: { querySelector: () => root, createElement: () => events() },
        window: { location: { hash: '#' + active }, history: { replaceState() {} } },
        CSS: { escape: value => value },
        Blob,
        URL: { createObjectURL(blob) { download = blob; return 'blob:test-only'; }, revokeObjectURL() {} },
    });
    return {
        fields, prewarm, secret,
        async export() {
            await exportButton.fire('click');
            return JSON.parse(await download.text());
        },
        async import(settings) {
            importInput.files = [{ text: async () => JSON.stringify(settings) }];
            await importInput.fire('change');
        },
        async submit() {
            await form.fire('submit');
            return Object.fromEntries(fields
                .filter(item => !item.disabled && (!['checkbox', 'radio'].includes(item.type) || item.checked))
                .map(item => [item.name, item.value]));
        },
    };
}

test('export includes settings from every tab, including checked and unchecked switches', async () => {
    const result = await fixture('dashboard').export();
    assert.equal(result[prefix + 'prewarm_enabled'], '1');
    assert.equal(result[prefix + 'valid_seconds'], '600');
    assert.equal(result[prefix + 'queue_enabled'], '0');
    assert.equal(result[prefix + 'prewarm_urls'], 'https://example.test/important/');
    assert.deepEqual(result[prefix + 'url_purge_roles[]'], ['editor']);
});

test('export excludes form metadata, unsaved secrets, secret-clear actions and managed fields', async () => {
    const result = await fixture('cdn').export();
    for (const name of ['action', 'option_page', '_wpnonce', prefix + 'remote_secret', prefix + 'remote_secret_clear', prefix + 'profile']) {
        assert.equal(Object.hasOwn(result, name), false, name);
    }
});

test('one save submits imported settings from other tabs and keeps WordPress form metadata', async () => {
    const ui = fixture('dashboard');
    await ui.import({ [prefix + 'prewarm_enabled']: true, [prefix + 'valid_seconds']: '1800', [prefix + 'url_purge_roles[]']: ['author'] });
    assert.deepEqual((await ui.export())[prefix + 'url_purge_roles[]'], ['author']);
    const submitted = await ui.submit();
    assert.equal(submitted[prefix + 'prewarm_enabled'], '1');
    assert.equal(submitted[prefix + 'valid_seconds'], '1800');
    assert.equal(submitted.action, 'update');
    assert.equal(submitted._wpnonce, 'test-only-nonce');
    assert.equal(ui.fields.filter(item => item.name === 'nginx_cache_path' && !item.disabled).length, 1);
});

test('import leaves checkbox fallbacks at zero so an imported switch can still be turned off', async () => {
    const ui = fixture('preload');
    await ui.import({ [prefix + 'prewarm_enabled']: true });
    ui.prewarm.checked = false;
    assert.equal((await ui.submit())[prefix + 'prewarm_enabled'], '0');
});

test('import cannot replace form metadata, managed fields or secret controls', async () => {
    const ui = fixture();
    await ui.import({ action: 'tampered', _wpnonce: 'tampered', [prefix + 'profile']: 'commerce', [prefix + 'remote_secret']: 'replacement', [prefix + 'remote_secret_clear']: false });
    const submitted = await ui.submit();
    assert.equal(submitted.action, 'update');
    assert.equal(submitted._wpnonce, 'test-only-nonce');
    assert.equal(ui.secret.value, 'test-only-unsaved-secret');
    assert.equal(ui.fields.find(item => item.name === prefix + 'profile').value, 'safe');
});
