/*
 * Trascina giu' per aggiornare (touch): dalla cima della pagina, oltre la soglia, ricarica la pagina
 * con Turbo. Non si attiva nei form (si perderebbe quello che si sta scrivendo), nei pannelli <dialog>
 * ne' sugli elementi marcati [data-no-pull]. Il pull-to-refresh nativo del browser e' spento dal CSS
 * (overscroll-behavior), cosi' su Android in una scheda non si ricarica due volte.
 */
import { visit } from '@hotwired/turbo';
import { t } from './i18n.js';

const THRESHOLD = 72; // px trascinati (gia' smorzati) per far partire l'aggiornamento
const MAX = 110;
const ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
    + '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/>'
    + '<path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/></svg>';

let indicator = null;
let startX = 0;
let startY = 0;
let pull = 0;
let tracking = false; // il gesto e' partito in cima e potrebbe diventare un pull
let pulling = false; // e' un pull verticale: lo gestiamo noi al posto dello scroll
let refreshing = false;

function el() {
    if (!indicator || !indicator.isConnected) {
        indicator = document.createElement('div');
        indicator.className = 'ptr';
        indicator.setAttribute('role', 'status');
        indicator.setAttribute('aria-label', t('Aggiornamento'));
        indicator.innerHTML = ICON;
        document.body.append(indicator);
    }

    return indicator;
}

function render(distance) {
    const i = el();
    const progress = Math.min(distance / THRESHOLD, 1);
    i.style.transform = `translate(-50%, ${distance - 56}px)`;
    i.style.opacity = String(Math.min(progress * 1.4, 1));
    i.firstElementChild.style.transform = `rotate(${distance * 3}deg)`;
    i.classList.toggle('is-ready', distance >= THRESHOLD);
}

function reset() {
    pull = 0;
    pulling = false;
    tracking = false;
    if (!indicator) return;
    indicator.classList.add('is-settling');
    indicator.classList.remove('is-ready', 'is-loading');
    indicator.style.transform = 'translate(-50%, -56px)';
    indicator.style.opacity = '0';
}

function enabled(target) {
    return !refreshing
        && window.scrollY <= 0
        && !document.querySelector('form.form, dialog[open]')
        && !target.closest('dialog, input, textarea, select, [contenteditable], [data-no-pull]');
}

document.addEventListener('touchstart', (event) => {
    if (event.touches.length !== 1 || !enabled(event.target)) return;
    tracking = true;
    pulling = false;
    startX = event.touches[0].clientX;
    startY = event.touches[0].clientY;
}, { passive: true });

document.addEventListener('touchmove', (event) => {
    if (!tracking) return;
    const dx = event.touches[0].clientX - startX;
    const dy = event.touches[0].clientY - startY;
    if (!pulling) {
        // Si decide al primo movimento netto: verso l'alto o di lato e' uno scroll normale.
        if (Math.abs(dx) < 6 && Math.abs(dy) < 6) return;
        if (dy <= 0 || Math.abs(dx) > Math.abs(dy) || window.scrollY > 0) {
            tracking = false;
            return;
        }
        pulling = true;
        el().classList.remove('is-settling');
    }
    event.preventDefault();
    // Resistenza crescente: il dito fa piu' strada dell'indicatore.
    pull = Math.min(MAX, Math.max(0, dy) * 0.5);
    render(pull);
}, { passive: false });

function release() {
    if (!pulling) {
        tracking = false;
        return;
    }
    if (pull < THRESHOLD) {
        reset();
        return;
    }
    refreshing = true;
    tracking = false;
    pulling = false;
    const i = el();
    i.classList.add('is-settling', 'is-loading');
    i.classList.remove('is-ready');
    i.style.transform = `translate(-50%, ${THRESHOLD - 56}px)`;
    i.firstElementChild.style.transform = '';
    visit(window.location.href, { action: 'replace' });
    // Se la richiesta non arriva mai (rete assente) l'indicatore non resta li' per sempre.
    window.setTimeout(done, 15000);
}

function done() {
    if (!refreshing) return;
    refreshing = false;
    reset();
}

document.addEventListener('touchend', release);
document.addEventListener('touchcancel', reset);
document.addEventListener('turbo:load', done);
document.addEventListener('turbo:fetch-request-error', done);
// La pagina nuova sostituisce il <body>: l'indicatore va ricreato alla prossima occasione.
document.addEventListener('turbo:before-render', () => { indicator = null; });
