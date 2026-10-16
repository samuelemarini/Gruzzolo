import { Controller } from '@hotwired/stimulus';

/*
 * Form movimento: mostra solo cio' che serve al tipo scelto.
 * - Uscita/Entrata: categorie del tipo giusto (le altre disabilitate, cosi' non vengono inviate).
 * - Giroconto: conto di destinazione, niente categoria.
 * - Categoria carburante: sezione rifornimento al posto del semplice "Veicolo".
 */
export default class extends Controller {
    static targets = ['categories', 'sign', 'transferOnly', 'accountLabel', 'vehicleRow', 'vehicleSelect', 'fuel', 'account'];

    connect() {
        this.sync();
    }

    kindChanged() {
        this.sync();
    }

    categoryChanged() {
        this.sync();
    }

    // Ogni conto ha le sue categorie: cambiando conto si vedono le sue.
    accountChanged() {
        this.sync();
    }

    get kind() {
        return this.element.querySelector('input[name="kind"]:checked')?.value || 'expense';
    }

    sync() {
        const kind = this.kind;

        const account = this.hasAccountTarget ? this.accountTarget.value : null;
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
        this.accountLabelTarget.textContent = kind === 'transfer' ? 'Da conto' : 'Conto';

        // Sottocategorie: visibili quando e' scelta la categoria madre o una delle figlie.
        const checked = this.element.querySelector('input[name="category"]:checked:not(:disabled)');
        const parentId = checked?.dataset.parent;
        this.element.querySelectorAll('[data-children-of]').forEach((row) => {
            row.hidden = row.dataset.childrenOf !== parentId || row.closest('[data-kind]').hidden;
        });
        this.element.querySelectorAll('.cat-pick').forEach((pick) => pick.classList.remove('is-parent'));
        if (checked && checked.closest('.cat-children')) {
            this.element.querySelector(`.cat-pick input[value="${parentId}"]`)?.closest('.cat-pick')?.classList.add('is-parent');
        }

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
}
