import { Controller } from '@hotwired/stimulus';

/* Zona di caricamento: mostra il nome del file scelto ed evidenzia il trascinamento. */
export default class extends Controller {
    static targets = ['zone', 'name'];

    connect() {
        ['dragenter', 'dragover'].forEach((t) => this.zoneTarget.addEventListener(t, () => this.zoneTarget.classList.add('is-over')));
        ['dragleave', 'drop'].forEach((t) => this.zoneTarget.addEventListener(t, () => this.zoneTarget.classList.remove('is-over')));
    }

    picked(event) {
        const file = event.target.files[0];
        if (!file) return;
        this.nameTarget.textContent = `${file.name} · ${Math.max(1, Math.round(file.size / 1024))} KB`;
        this.zoneTarget.classList.add('has-file');
    }
}
