/*
 * Conferme dei form (data-turbo-confirm) nel pannello dell'app al posto della finestra del browser.
 * "Eliminare il conto? I movimenti restano." diventa titolo (la domanda) e testo (il resto);
 * pulsante, icona e colore vengono dal tipo dichiarato sul form (data-confirm-kind).
 */
import { config } from '@hotwired/turbo';
import { t } from './i18n.js';

const KINDS = {
    delete: { ok: 'Elimina', icon: 'trash-2', danger: true },
    remove: { ok: 'Togli', icon: 'users', danger: true },
    leave: { ok: 'Esci dal conto', icon: 'log-out', danger: true },
    // "Annulla" e' gia' il pulsante per tornare indietro
    undo: { ok: 'Sì, annulla', cancel: 'No', icon: 'undo-2', danger: true },
    sort: { ok: 'Riordina', icon: 'arrow-down-up' },
    copy: { ok: 'Copia', icon: 'layout-grid' },
};

config.forms.confirm = (message, form, submitter) => new Promise((resolve) => {
    const dialog = document.getElementById('confirm-dialog');
    if (!dialog) {
        resolve(window.confirm(message));
        return;
    }
    const [, title, text] = message.match(/^(.*?\?)\s*(.*)$/s) ?? [null, message, ''];
    const kind = KINDS[submitter?.dataset.confirmKind ?? form?.dataset.confirmKind] ?? { ok: 'Conferma', icon: 'info' };

    dialog.dataset.tone = kind.danger ? 'danger' : '';
    dialog.querySelector('.confirm__title').textContent = title;
    dialog.querySelector('.confirm__text').textContent = text;
    dialog.querySelector('.confirm__text').hidden = !text;
    dialog.querySelector('.confirm__ok').textContent = t(kind.ok);
    dialog.querySelector('.confirm__cancel').textContent = t(kind.cancel ?? 'Annulla');
    dialog.querySelectorAll('.confirm__icon [data-icon]').forEach((icon) => {
        icon.hidden = icon.dataset.icon !== kind.icon;
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
