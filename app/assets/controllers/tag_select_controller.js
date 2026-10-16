import { Controller } from '@hotwired/stimulus';

/* Tendina delle etichette: si apre e chiude dalla riga, resta aperta mentre si spuntano. */
export default class extends Controller {
    static targets = ['toggle', 'menu', 'summary'];

    toggle() {
        this.menuTarget.hidden ? this.open() : this.close();
    }

    open() {
        this.menuTarget.hidden = false;
        this.toggleTarget.setAttribute('aria-expanded', 'true');
        this.menuTarget.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    close() {
        this.menuTarget.hidden = true;
        this.toggleTarget.setAttribute('aria-expanded', 'false');
    }

    outside(event) {
        if (!this.menuTarget.hidden && !this.element.contains(event.target)) this.close();
    }

    changed() {
        const names = [...this.menuTarget.querySelectorAll('input:checked')].map((input) => `#${input.value}`);
        this.summaryTarget.textContent = names.length ? names.join(', ') : 'nessuna';
    }
}
