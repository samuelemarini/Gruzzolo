/*
 * Lingua dell'interfaccia per gli script. I testi tradotti li scrive base.html.twig in <script id="i18n">,
 * con la frase italiana come chiave (come nei template, vedi translations/); i separatori numerici
 * stanno su <html data-decimal data-group>.
 */
let cache = null;

/** t('ultimo: %km% km', {km: '1.200'}) */
export function t(key, params = {}) {
    const el = document.getElementById('i18n');
    if (!cache || cache.el !== el) cache = { el, strings: el ? JSON.parse(el.textContent) : {} };
    let text = cache.strings[key] ?? key;
    for (const [name, value] of Object.entries(params)) text = text.replaceAll(`%${name}%`, value);
    return text;
}

export function separators() {
    const root = document.documentElement;
    return { decimal: root.dataset.decimal || ',', group: root.dataset.group ?? '.' };
}

/**
 * Numero scritto dall'utente, come Money::parseLocal: con tutti e due i separatori i decimali sono
 * dopo l'ultimo; l'altro separatore da solo e senza tre cifre dopo vale come decimale ("12.5" in italiano).
 */
export function parseNumber(text) {
    let value = String(text || '').replace(/[\s\u00A0\u202F€']/g, '');
    let { decimal } = separators();
    if (value.includes(',') && value.includes('.')) decimal = value.lastIndexOf(',') > value.lastIndexOf('.') ? ',' : '.';
    const other = decimal === ',' ? '.' : ',';
    if (!value.includes(decimal) && value.split(other).length === 2 && !new RegExp(`\\${other}\\d{3}$`).test(value)) {
        value = value.replace(other, decimal);
    }
    value = value.replaceAll(other, '').replace(decimal, '.');
    const n = parseFloat(value);
    return Number.isFinite(n) ? n : null;
}

export function formatNumber(n, decimals, grouping = true) {
    const { decimal, group } = separators();
    const [int, dec] = Math.abs(n).toFixed(decimals).split('.');
    const text = (grouping ? int.replace(/\B(?=(\d{3})+(?!\d))/g, group) : int) + (dec ? decimal + dec : '');
    return n < 0 ? `−${text}` : text;
}
