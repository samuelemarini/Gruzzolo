/*
 * Modalita' "importi nascosti": quando <html data-privacy="on">, il CSS sfoca gli importi.
 * Gli importi si riconoscono dal formato ("1.247,80 €", "−4,50 €", "650 €") e si avvolgono in
 * <span class="money"> a ogni pagina; i grandi saldi (.hero-amount__value) sono gia' marcati dal CSS.
 * La scelta vale per questo dispositivo (localStorage), letta in <head> prima del disegno.
 */
const MONEY = /[−+-]?\d{1,3}(?:\.\d{3})*(?:,\d+)?\s?€(?:\/[a-zA-Z]+)?/g;
const SKIP = new Set(['SCRIPT', 'STYLE', 'TEXTAREA', 'INPUT', 'SELECT', 'OPTION', 'TITLE']);
const KEY = 'gruzzolo-privacy';

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
    syncTooltips(isHidden());
    document.querySelectorAll('.privacy-toggle').forEach((button) => {
        button.setAttribute('aria-pressed', isHidden() ? 'true' : 'false');
        button.setAttribute('aria-label', isHidden() ? 'Mostra gli importi' : 'Nascondi gli importi');
        button.title = isHidden() ? 'Mostra gli importi' : 'Nascondi gli importi';
    });
}

['turbo:load', 'turbo:render', 'turbo:frame-load'].forEach((event) => document.addEventListener(event, refresh));
