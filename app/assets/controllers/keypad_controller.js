import { Controller } from '@hotwired/stimulus';
import { separators } from '../i18n.js';

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
            this.format();
            if (!sizing) {
                const length = (this.inputTarget.value || this.inputTarget.placeholder).length;
                this.inputTarget.style.width = `${Math.max(length, 3) + 0.6}ch`;
            }
            this.fit();
        };
        this.element.addEventListener('input', this.refresh);
        window.addEventListener('resize', this.refresh);
        // Le migliaia sono solo a video: al server va "249680,00" ("249.680" sarebbe 249,68)
        this.unformat = (event) => event.formData.set(this.inputTarget.name, this.inputTarget.value.replaceAll(separators().group, ''));
        this.element.addEventListener('formdata', this.unformat);
        this.refresh();
        document.fonts?.ready.then(() => this.inputTarget && this.refresh());
    }

    disconnect() {
        if (!this.refresh) return;
        this.element.removeEventListener('input', this.refresh);
        this.element.removeEventListener('formdata', this.unformat);
        window.removeEventListener('resize', this.refresh);
    }

    // "249680,5" -> "249.680,5" (separatori della lingua), tenendo il cursore dopo la stessa cifra
    format() {
        const { decimal, group } = separators();
        const input = this.inputTarget;
        const value = input.value;
        const clean = (text) => [...text].filter((c) => /\d/.test(c) || c === decimal).join('');
        const [int, ...decimals] = clean(value).split(decimal);
        let text = int.replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, group);
        if (decimals.length) text += `${decimal}${decimals.join('').slice(0, 2)}`;
        if (text === value) return;
        const kept = clean(value.slice(0, input.selectionStart ?? value.length)).length;
        input.value = text;
        if (document.activeElement !== input) return;
        let caret = 0;
        for (let seen = 0; caret < text.length && seen < kept; caret++) {
            if (text[caret] !== group) seen++;
        }
        input.setSelectionRange(caret, caret);
    }

    // Le migliaia le mette l'app: il punto o la virgola scritti a mano valgono come separatore dei decimali
    typed(event) {
        if (event.inputType !== 'insertText' || !['.', ','].includes(event.data)) return;
        const { decimal } = separators();
        if (event.data === decimal && !this.inputTarget.value.includes(decimal)) return;
        event.preventDefault();
        if (!this.inputTarget.value.includes(decimal)) this.insert(decimal);
    }

    pasted(event) {
        const { decimal } = separators();
        let text = (event.clipboardData?.getData('text') ?? '').replace(/[^\d.,]/g, '');
        // "1247.80" o "1.247,80" copiati da altrove: i decimali sono dopo l'ultimo separatore, se ne ha uno o due cifre
        const match = text.match(/^(.*)[.,](\d{1,2})$/);
        text = match ? match[1].replace(/[.,]/g, '') + decimal + match[2] : text.replace(/[.,]/g, '');
        event.preventDefault();
        this.insert(text);
    }

    insert(text) {
        const input = this.inputTarget;
        input.setRangeText(text, input.selectionStart, input.selectionEnd, 'end');
        input.dispatchEvent(new Event('input', { bubbles: true }));
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
        const { decimal } = separators();
        const key = event.currentTarget.dataset.key;
        let value = this.inputTarget.value;
        if (key === ',') {
            if (value.includes(decimal)) return;
            value = (value || '0') + decimal;
        } else {
            if (value.includes(decimal) && value.split(decimal)[1].length >= 2) return;
            if (value.replace(/\D/g, '').length >= 9) return;
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
