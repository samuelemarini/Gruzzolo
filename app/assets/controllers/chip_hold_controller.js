import { Controller } from '@hotwired/stimulus';
import { visit } from '@hotwired/turbo';

/*
 * Chip con doppio gesto: il tocco segue il link (href), tenendo premuto si va invece a data-hold-href.
 * Il gesto si annulla se il dito si sposta (scroll della striscia); il click che segue la pressione
 * lunga viene scartato, cosi' non parte anche il link normale.
 */
const DELAY = 450; // ms di pressione per contare come "tenuto premuto"
const SLOP = 8; // px di movimento tollerati prima di considerarlo uno scroll

export default class extends Controller {
    press(event) {
        if (event.button !== 0) return;
        this.cancel();
        this.held = false;
        this.startX = event.clientX;
        this.startY = event.clientY;
        const chip = event.currentTarget;
        this.timer = window.setTimeout(() => {
            this.timer = null;
            this.held = true;
            navigator.vibrate?.(10);
            visit(chip.dataset.holdHref);
        }, DELAY);
    }

    move(event) {
        if (this.timer && Math.hypot(event.clientX - this.startX, event.clientY - this.startY) > SLOP) this.cancel();
    }

    cancel() {
        window.clearTimeout(this.timer);
        this.timer = null;
    }

    click(event) {
        if (!this.held) return;
        this.held = false;
        event.preventDefault();
    }

    // Niente menu del link (Android) sulla pressione lunga.
    menu(event) {
        event.preventDefault();
    }
}
