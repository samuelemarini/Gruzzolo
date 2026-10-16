import { Controller } from '@hotwired/stimulus';

/* Scelta dei conti nei filtri: "Tutti" e i singoli conti si escludono a vicenda. */
export default class extends Controller {
    static targets = ['all', 'one'];

    all() {
        if (this.allTarget.checked) {
            this.oneTargets.forEach((input) => { input.checked = false; });
        } else if (!this.oneTargets.some((input) => input.checked)) {
            this.allTarget.checked = true;
        }
    }

    one() {
        this.allTarget.checked = !this.oneTargets.some((input) => input.checked);
    }
}
