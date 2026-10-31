import { Controller } from '@hotwired/stimulus';
import { t } from '../i18n.js';

/*
 * Form movimento: mostra solo cio' che serve al tipo scelto.
 * - Uscita/Entrata: senza categoria si vede solo la griglia delle categorie (con importo e tastierino);
 *   scelta la categoria la griglia sparisce, compaiono gli altri campi e la categoria resta in alto
 *   in un pulsante che riapre la griglia. Una categoria con sottocategorie mostra prima le figlie.
 *   Le categorie degli altri tipi/conti sono disabilitate, cosi' non vengono inviate.
 * - Giroconto: conto di destinazione, niente categoria.
 * - Categoria carburante: sezione rifornimento al posto del semplice "Veicolo".
 */
export default class extends Controller {
    static targets = ['categories', 'sign', 'transferOnly', 'accountLabel', 'vehicleRow', 'vehicleSelect', 'fuel', 'account',
        'current', 'currentTile', 'currentName', 'accountChip'];

    connect() {
        this.sync();
        this.setMode(this.needsCategory() ? 'grid' : 'fields');
    }

    kindChanged() {
        this.sync();
        this.setMode(this.needsCategory() ? 'grid' : 'fields');
    }

    // Ogni conto ha le sue categorie: cambiando conto la categoria scelta non vale piu', si riparte dalla griglia.
    accountChanged() {
        this.sync();
        if (this.needsCategory()) this.setMode('grid');
    }

    // Chip dei conti sopra la griglia: stesso effetto del menu Conto fra i campi.
    pickAccount(event) {
        if (!this.hasAccountTarget) return;
        this.accountTarget.value = event.currentTarget.dataset.account;
        this.accountChanged();
    }

    categoryChanged(event) {
        const input = event.target;
        const group = input.closest('[data-kind]');
        // Categoria madre con figlie: prima si scelgono le figlie (o "Solo ...").
        if (input.dataset.hasChildren === '1' && input.closest('.cat-picker')) {
            group.dataset.sub = input.value;
            this.sync();
            return;
        }
        delete group.dataset.sub;
        this.sync();
        this.setMode('fields');
    }

    confirmParent(event) {
        delete event.currentTarget.closest('[data-kind]').dataset.sub;
        this.sync();
        this.setMode('fields');
    }

    backToGrid(event) {
        delete event.currentTarget.closest('[data-kind]').dataset.sub;
        this.sync();
    }

    openGrid() {
        this.setMode('grid');
    }

    get kind() {
        return this.element.querySelector('input[name="kind"]:checked')?.value || 'expense';
    }

    get checkedCategory() {
        return this.element.querySelector('input[name="category"]:checked:not(:disabled)');
    }

    needsCategory() {
        return this.kind !== 'transfer' && !this.checkedCategory;
    }

    setMode(mode) {
        const changed = this.element.dataset.mode !== mode;
        this.element.dataset.mode = mode;
        if (mode === 'grid') {
            this.categoriesTargets.forEach((group) => delete group.dataset.sub);
            this.sync();
        }
        if (changed) window.scrollTo({ top: 0 });
    }

    sync() {
        const kind = this.kind;

        const account = this.hasAccountTarget ? this.accountTarget.value : null;
        this.accountChipTargets.forEach((chip) => {
            const active = chip.dataset.account === account;
            chip.classList.toggle('is-active', active);
            chip.setAttribute('aria-checked', active ? 'true' : 'false');
        });
        this.categoriesTargets.forEach((group) => {
            const active = group.dataset.kind === kind && (!account || group.dataset.account === account);
            group.hidden = !active;
            group.querySelectorAll('input').forEach((input) => { input.disabled = !active; });
        });
        this.signTarget.textContent = kind === 'income' ? '+' : (kind === 'expense' ? '−' : '');
        this.transferOnlyTargets.forEach((el) => {
            el.hidden = kind !== 'transfer';
            el.querySelectorAll('select, input').forEach((input) => { input.disabled = kind !== 'transfer'; });
        });
        this.accountLabelTarget.textContent = kind === 'transfer' ? t('Da conto') : t('Conto');

        // Sottocategorie: si vedono al posto della griglia mentre si sceglie fra le figlie di una madre.
        this.element.querySelectorAll('[data-children-of]').forEach((row) => {
            row.hidden = row.closest('[data-kind]').dataset.sub !== row.dataset.childrenOf;
        });

        const checked = this.checkedCategory;
        this.renderCurrent(kind !== 'transfer' ? checked : null);

        const isFuel = kind === 'expense' && checked?.dataset.fuel === '1';
        if (this.hasFuelTarget) {
            this.fuelTarget.hidden = !isFuel;
            this.fuelTarget.querySelectorAll('input[name="vehicle"]').forEach((input) => { input.disabled = !isFuel; });
        }
        if (this.hasVehicleRowTarget) {
            const show = kind === 'expense' && !isFuel && this.vehicleSelectTarget.options.length > 1;
            this.vehicleRowTarget.hidden = !show;
            this.vehicleSelectTarget.disabled = !show;
        }
    }

    // Pulsante in alto con icona e nome della categoria scelta (per le figlie, l'icona della madre).
    renderCurrent(checked) {
        if (!this.hasCurrentTarget) return;
        this.currentTarget.hidden = !checked;
        if (!checked) return;
        const parentPick = this.element.querySelector(`.cat-pick input[value="${checked.dataset.parent}"]`)?.closest('.cat-pick');
        const tile = parentPick?.querySelector('.tile')?.cloneNode(true);
        this.currentTileTarget.replaceChildren(...(tile ? [tile] : []));
        this.currentNameTarget.textContent = checked.dataset.name || '';
    }
}
