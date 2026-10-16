import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

/*
 * Ordinamento delle categorie: ogni lista (principali, figlie di una categoria) si riordina per conto
 * suo, trascinando dalla maniglia o con le frecce. Dopo ogni spostamento si salva l'ordine del gruppo.
 */
export default class extends Controller {
    static targets = ['list', 'status'];
    static values = { url: String, token: String };

    connect() {
        this.sortables = this.listTargets.map((list) => Sortable.create(list, {
            handle: '.sort-handle',
            animation: 160,
            // Ogni lista e' un gruppo a se': una sottocategoria non puo' finire fra le principali.
            group: { name: `g${Math.random()}`, pull: false, put: false },
            ghostClass: 'is-ghost',
            chosenClass: 'is-chosen',
            delay: 120,
            delayOnTouchOnly: true,
            onEnd: (event) => {
                if (event.oldIndex !== event.newIndex) this.save(list);
            },
        }));
    }

    disconnect() {
        this.sortables?.forEach((s) => s.destroy());
    }

    up(event) {
        this.move(event, -1);
    }

    down(event) {
        this.move(event, 1);
    }

    move(event, direction) {
        const item = event.currentTarget.closest('.sort-item');
        const list = item.parentElement;
        const sibling = direction < 0 ? item.previousElementSibling : item.nextElementSibling;
        if (!sibling) return;
        direction < 0 ? list.insertBefore(item, sibling) : list.insertBefore(sibling, item);
        event.currentTarget.focus();
        item.classList.add('is-moved');
        setTimeout(() => item.classList.remove('is-moved'), 400);
        this.save(list);
    }

    async save(list) {
        const body = new URLSearchParams({ _token: this.tokenValue });
        [...list.children].forEach((li) => body.append('ids[]', li.dataset.id));
        this.status('Salvo…');
        try {
            const response = await fetch(this.urlValue, { method: 'POST', body, headers: { 'X-Requested-With': 'fetch' } });
            this.status(response.ok ? 'Salvato' : 'Errore');
        } catch (e) {
            this.status('Errore');
        }
    }

    status(text) {
        if (!this.hasStatusTarget) return;
        this.statusTarget.textContent = text;
        clearTimeout(this.statusTimer);
        if (text === 'Salvato') this.statusTimer = setTimeout(() => { this.statusTarget.textContent = ''; }, 1500);
    }
}
