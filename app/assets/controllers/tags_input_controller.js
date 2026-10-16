import { Controller } from '@hotwired/stimulus';

/* Etichette come hashtag: Invio, virgola o spazio aggiungono, Backspace a vuoto toglie l'ultima. */
export default class extends Controller {
    static targets = ['list', 'input'];

    keydown(event) {
        if (['Enter', ',', ' '].includes(event.key)) {
            event.preventDefault();
            this.commit();
        } else if (event.key === 'Backspace' && !this.inputTarget.value) {
            const chips = this.listTarget.querySelectorAll('.tag-chip');
            chips[chips.length - 1]?.remove();
        }
    }

    commit() {
        const name = this.normalize(this.inputTarget.value);
        this.inputTarget.value = '';
        if (!name) return;
        const existing = [...this.listTarget.querySelectorAll('input[type="hidden"]')].map((input) => input.value);
        if (existing.includes(name)) return;

        const chip = document.createElement('button');
        chip.type = 'button';
        chip.className = 'tag-chip';
        chip.dataset.action = 'tags-input#remove';
        chip.setAttribute('aria-label', `Togli ${name}`);
        chip.append(`#${name} ×`);
        const hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'tags[]';
        hidden.value = name;
        chip.append(hidden);
        this.listTarget.insertBefore(chip, this.inputTarget);
    }

    remove(event) {
        event.currentTarget.remove();
        this.inputTarget.focus();
    }

    normalize(text) {
        return text.toLowerCase().trim().replace(/^#/, '')
            .normalize('NFC').replace(/[^\p{L}\p{N}]+/gu, '-').replace(/^-+|-+$/g, '').slice(0, 60);
    }
}
