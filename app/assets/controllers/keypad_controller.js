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
        if (!this.hasInputTarget) return;
        const sizing = CSS.supports('field-sizing', 'content');
        // Ascolta tutto il modulo: anche il totale del rifornimento riscrive l'importo
        this.refresh = () => {
            if (!sizing) {
                const length = (this.inputTarget.value || this.inputTarget.placeholder).length;
                this.inputTarget.style.width = `${Math.max(length, 3) + 0.6}ch`;
            }
            this.fit();
        };
        this.element.addEventListener('input', this.refresh);
        window.addEventListener('resize', this.refresh);
        this.refresh();
        document.fonts?.ready.then(() => this.inputTarget && this.refresh());
    }

    disconnect() {
        if (!this.refresh) return;
        this.element.removeEventListener('input', this.refresh);
        window.removeEventListener('resize', this.refresh);
    }

    // Cifre grandi: il carattere si rimpicciolisce finche' l'importo sta tutto nella riga
    fit() {
        const input = this.inputTarget;
        input.style.fontSize = '';
        let size = parseFloat(getComputedStyle(input).fontSize);
        while (input.scrollWidth > input.clientWidth + 1 && size > 28) {
            size -= 2;
            input.style.fontSize = `${size}px`;
        }
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
