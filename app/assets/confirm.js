/*
 * Conferme dei form (data-turbo-confirm) nel pannello dell'app al posto della finestra del browser.
 * "Eliminare il conto? I movimenti restano." diventa titolo (la domanda) e testo (il resto);
 * pulsante, icona e colore si ricavano dal verbo con cui comincia la domanda.
 */
import { config } from '@hotwired/turbo';

const VERBS = {
    Eliminare: { ok: 'Elimina', icon: 'trash-2', danger: true },
    Togliere: { ok: 'Togli', icon: 'users', danger: true },
    Uscire: { ok: 'Esci', icon: 'log-out', danger: true },
    // "Annulla" e' gia' il pulsante per tornare indietro
    Annullare: { ok: 'Sì, annulla', cancel: 'No', icon: 'undo-2', danger: true },
    Riordinare: { ok: 'Riordina', icon: 'arrow-down-up' },
    Copiare: { ok: 'Copia', icon: 'layout-grid' },
};

config.forms.confirm = (message) => new Promise((resolve) => {
    const dialog = document.getElementById('confirm-dialog');
    if (!dialog) {
        resolve(window.confirm(message));
        return;
    }
    const [, title, text] = message.match(/^(.*?\?)\s*(.*)$/s) ?? [null, message, ''];
    const verb = VERBS[message.split(' ')[0]] ?? { ok: 'Conferma', icon: 'info' };

    dialog.dataset.tone = verb.danger ? 'danger' : '';
    dialog.querySelector('.confirm__title').textContent = title;
    dialog.querySelector('.confirm__text').textContent = text;
    dialog.querySelector('.confirm__text').hidden = !text;
    dialog.querySelector('.confirm__ok').textContent = verb.ok;
    dialog.querySelector('.confirm__cancel').textContent = verb.cancel ?? 'Annulla';
    dialog.querySelectorAll('.confirm__icon [data-icon]').forEach((icon) => {
        icon.hidden = icon.dataset.icon !== verb.icon;
    });

    const backdrop = (event) => {
        if (event.target === dialog) dialog.close();
    };
    dialog.addEventListener('click', backdrop);
    dialog.addEventListener('close', () => {
        dialog.removeEventListener('click', backdrop);
        resolve(dialog.returnValue === 'ok');
    }, { once: true });
    dialog.returnValue = '';
    dialog.showModal();
    // Il focus sul pannello e non su un pulsante: niente anello su Annulla, Invio non conferma per sbaglio
    dialog.focus();
});
