import { Controller } from '@hotwired/stimulus';

/* Aggiornamento di un investimento: l'importo e il conto servono solo per versamenti e prelievi. */
export default class extends Controller {
    static targets = ['amount', 'amountLabel', 'account', 'accountHint', 'hint'];

    connect() {
        this.sync();
    }

    sync() {
        const mode = this.element.querySelector('input[name="mode"]:checked')?.value || 'deposit';
        this.amountTarget.hidden = mode === 'value';
        this.amountTarget.querySelector('input').disabled = mode === 'value';
        this.amountLabelTarget.textContent = mode === 'withdraw' ? 'Ritirati €' : 'Versati €';
        const showAccount = mode === 'deposit';
        this.accountTarget.hidden = !showAccount;
        this.accountHintTarget.hidden = !showAccount;
        this.accountTarget.querySelector('select').disabled = !showAccount;
        this.hintTarget.hidden = mode === 'value';
    }
}
