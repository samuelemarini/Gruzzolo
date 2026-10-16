import { Controller } from '@hotwired/stimulus';

/* Bottom sheet su <dialog>: si chiude col tasto Esc, la X o toccando fuori. */
export default class extends Controller {
    static targets = ['dialog'];

    open(event) {
        event?.preventDefault();
        this.dialogTarget.showModal();
    }

    close(event) {
        event?.preventDefault();
        this.dialogTarget.close();
    }

    backdrop(event) {
        if (event.target === this.dialogTarget) this.dialogTarget.close();
    }
}
