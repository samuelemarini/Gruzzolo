import { Controller } from '@hotwired/stimulus';
import { isHidden, setHidden } from '../privacy.js';

/* Pulsante occhio: nasconde o mostra gli importi su questo dispositivo. */
export default class extends Controller {
    toggle(event) {
        event.preventDefault();
        setHidden(!isHidden());
    }
}
