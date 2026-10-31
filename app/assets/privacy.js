/*
 * Modalita' "importi nascosti": quando <html data-privacy="on">, le cifre degli importi diventano
 * lettere maiuscole e numeri a caso capovolti (segno e € restano). Gli importi si riconoscono dal formato ("1.247,80 €",
 * "−4,50 €", "650 €") e si avvolgono in <span class="money"> a ogni pagina; i grandi saldi, il centro
 * delle ciambelle e le scale dei grafici sono gia' marcati (SCRAMBLE). Il testo vero resta in
 * data-privacy-text e torna quando si rimostrano gli importi.
 * La scelta vale per questo dispositivo (localStorage), letta in <head> prima del disegno.
 */
// Separatori di qualsiasi lingua: "1.247,80 €", "1,247.80 €", "1 247,80 €"
const MONEY = /[−+-]?\d{1,3}(?:[.,\u202F\u00A0]\d{3})*(?:[.,]\d+)?\s?€(?:\/[a-zA-Z]+)?/g;
const SKIP = new Set(['SCRIPT', 'STYLE', 'TEXTAREA', 'INPUT', 'SELECT', 'OPTION', 'TITLE']);
const KEY = 'gruzzolo-privacy';
const SCRAMBLE = '.money, .hero-amount__value, .donut__value, .lc__ticks span';
const DIGITS = /\d[\d.,]*/g;
const RANDOM = 'ABCDEFGHJKLMNPQRSTUVWXYZ0123456789';

function randomText(length) {
    let out = '';
    for (let i = 0; i < length; i++) out += RANDOM[Math.floor(Math.random() * RANDOM.length)];
    return out;
}

/*
 * Sostituisce le cifre (con i loro punti e virgole) con caratteri a caso, capovolti dal CSS. Lunghezza
 * fissa, cosi' non si intuisce l'ordine di grandezza: 3 sui movimenti, 4 sui totali, 2 sulle scale dei grafici.
 */
function scrambledLength(el) {
    if (el.closest('.lc__ticks')) return 2;
    return el.closest('.list-row--movement') ? 3 : 4;
}

function scramble(el) {
    if (el.dataset.privacyText !== undefined || el.closest('[data-privacy-skip]')) return;
    const text = el.textContent;
    DIGITS.lastIndex = 0;
    if (!DIGITS.test(text)) return;
    el.dataset.privacyText = text;
    const fragment = document.createDocumentFragment();
    let last = 0;
    text.replace(DIGITS, (match, offset) => {
        if (offset > last) fragment.append(text.slice(last, offset));
        const span = document.createElement('span');
        span.className = 'scrambled';
        span.textContent = randomText(scrambledLength(el));
        fragment.append(span);
        last = offset + match.length;
        return match;
    });
    if (last < text.length) fragment.append(text.slice(last));
    el.replaceChildren(fragment);
}

function unscramble(el) {
    if (el.dataset.privacyText === undefined) return;
    el.textContent = el.dataset.privacyText;
    delete el.dataset.privacyText;
}

function wrap(root) {
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
        acceptNode(node) {
            const parent = node.parentElement;
            if (!parent || SKIP.has(parent.tagName) || parent.closest('.money, [data-privacy-skip], svg')) return NodeFilter.FILTER_REJECT;
            MONEY.lastIndex = 0;
            return MONEY.test(node.nodeValue) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
        },
    });
    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);
    nodes.forEach((node) => {
        const text = node.nodeValue;
        const fragment = document.createDocumentFragment();
        let last = 0;
        text.replace(MONEY, (match, offset) => {
            if (offset > last) fragment.append(text.slice(last, offset));
            const span = document.createElement('span');
            span.className = 'money';
            span.textContent = match;
            fragment.append(span);
            last = offset + match.length;
            return match;
        });
        if (last < text.length) fragment.append(text.slice(last));
        node.replaceWith(fragment);
    });
}

/* Anche i tooltip (title) e le etichette dei grafici rivelerebbero le cifre. */
function syncTooltips(hidden) {
    document.querySelectorAll('[title], svg title').forEach((el) => {
        const isChartPoint = el.classList?.contains('lc__hit');
        const isSvgTitle = el.tagName.toLowerCase() === 'title';
        const current = isSvgTitle ? el.textContent : el.getAttribute('title');
        if (hidden) {
            if (el.dataset.privacyTitle === undefined && /\d/.test(current || '') && (isSvgTitle || isChartPoint || /€/.test(current))) {
                el.dataset.privacyTitle = current;
                isSvgTitle ? (el.textContent = '') : el.setAttribute('title', '');
            }
        } else if (el.dataset.privacyTitle !== undefined) {
            isSvgTitle ? (el.textContent = el.dataset.privacyTitle) : el.setAttribute('title', el.dataset.privacyTitle);
            delete el.dataset.privacyTitle;
        }
    });
}

export function isHidden() {
    return document.documentElement.dataset.privacy === 'on';
}

export function setHidden(hidden) {
    document.documentElement.dataset.privacy = hidden ? 'on' : 'off';
    try {
        localStorage.setItem(KEY, hidden ? 'on' : 'off');
    } catch (e) {
        // Navigazione privata o storage bloccato: vale finche' la pagina resta aperta.
    }
    refresh();
}

export function refresh() {
    wrap(document.body);
    document.querySelectorAll(SCRAMBLE).forEach(isHidden() ? scramble : unscramble);
    syncTooltips(isHidden());
    document.querySelectorAll('.privacy-toggle').forEach((button) => {
        button.setAttribute('aria-pressed', isHidden() ? 'true' : 'false');
        button.setAttribute('aria-label', isHidden() ? 'Mostra gli importi' : 'Nascondi gli importi');
        button.title = isHidden() ? 'Mostra gli importi' : 'Nascondi gli importi';
    });
}

['turbo:load', 'turbo:render', 'turbo:frame-load'].forEach((event) => document.addEventListener(event, refresh));
