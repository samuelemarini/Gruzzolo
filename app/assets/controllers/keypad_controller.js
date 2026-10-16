import { Controller } from '@hotwired/stimulus';

/*
 * Tastierino numerico per l'importo, solo sui telefoni (vedi .keypad in app.css):
 * li' l'input non apre la tastiera di sistema, sul desktop si scrive normalmente.
 */
export default class extends Controller {
    static targets = ['input', 'pad'];

    connect() {
        this.touch = window.matchMedia('(pointer: coarse) and (max-width: 700px)').matches;
        if (this.touch && this.hasInputTarget) {
            this.inputTarget.setAttribute('inputmode', 'none');
        }
        // Larghezza al contenuto, cosi' segno e simbolo dell'euro restano attaccati alla cifra
        // (dove field-sizing non esiste ancora).
        if (this.hasInputTarget && !CSS.supports('field-sizing', 'content')) {
            this.resize = () => {
                const length = (this.inputTarget.value || this.inputTarget.placeholder).length;
                this.inputTarget.style.width = `${Math.max(length, 3) + 0.6}ch`;
            };
            this.inputTarget.addEventListener('input', this.resize);
            this.resize();
        }
    }

    disconnect() {
        if (this.resize) this.inputTarget.removeEventListener('input', this.resize);
    }

    press(event) {
        const key = event.currentTarget.dataset.key;
        let value = this.inputTarget.value;
        if (key === ',') {
            if (value.includes(',')) return;
            value = (value || '0') + ',';
        } else {
            if (value.includes(',') && value.split(',')[1].length >= 2) return;
            if (value.replace(',', '').length >= 9) return;
            value = (value === '0' ? '' : value) + key;
        }
        this.set(value);
    }

    back() {
        this.set(this.inputTarget.value.slice(0, -1));
    }

    set(value) {
        this.inputTarget.value = value;
        this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }));
    }
}
