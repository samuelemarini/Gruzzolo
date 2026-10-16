import { Controller } from '@hotwired/stimulus';

/*
 * Rifornimento: dei tre valori prezzo/litri/totale si calcola quello che non e' fra gli ultimi due
 * modificati. Il totale e' l'importo del movimento (l'input grande in alto), tenuto allineato.
 * Mostra anche i km dall'ultimo rifornimento e una stima del consumo.
 */
export default class extends Controller {
    static targets = ['vehicle', 'odometer', 'lastKm', 'delta', 'price', 'liters', 'total', 'full', 'missed',
        'priceBox', 'litersBox', 'totalBox', 'priceLabel', 'litersLabel',
        'preview', 'effValue', 'effUnit', 'per100Value', 'per100Unit', 'perKmValue'];

    static values = { odometers: Object };

    connect() {
        if (!this.hasPriceTarget) return;
        this.amount = this.element.closest('form').querySelector('input[name="amount"]');
        this.order = [];
        // In modifica i tre valori ci sono gia': si considerano "scelti" prezzo e totale.
        if (this.priceTarget.value && this.totalTarget.value) this.order = ['total', 'price'];
        this.onAmount = () => {
            this.totalTarget.value = this.amount.value;
            this.touch('total');
            this.compute();
        };
        this.amount.addEventListener('input', this.onAmount);
        this.vehicleChanged();
        this.markComputed();
    }

    disconnect() {
        this.amount?.removeEventListener('input', this.onAmount);
    }

    edited(event) {
        const field = event.currentTarget.dataset.field;
        this.touch(field);
        if (field === 'total') this.amount.value = this.totalTarget.value;
        this.compute();
    }

    touch(field) {
        this.order = [field, ...this.order.filter((f) => f !== field)].slice(0, 2);
    }

    get computedField() {
        return this.order.length === 2 ? ['price', 'liters', 'total'].find((f) => !this.order.includes(f)) : null;
    }

    compute() {
        const price = this.num(this.priceTarget.value);
        const liters = this.num(this.litersTarget.value);
        const total = this.num(this.totalTarget.value);
        switch (this.computedField) {
            case 'total':
                if (price && liters) {
                    this.totalTarget.value = this.fmt(price * liters, 2);
                    this.amount.value = this.totalTarget.value;
                }
                break;
            case 'liters':
                if (price && total) this.litersTarget.value = this.fmt(total / price, 2);
                break;
            case 'price':
                if (liters && total) this.priceTarget.value = this.fmt(total / liters, 3);
                break;
        }
        this.markComputed();
        this.update();
    }

    markComputed() {
        const computed = this.computedField;
        this.priceBoxTarget.classList.toggle('is-computed', computed === 'price');
        this.litersBoxTarget.classList.toggle('is-computed', computed === 'liters');
        this.totalBoxTarget.classList.toggle('is-computed', computed === 'total');
    }

    vehicleChanged() {
        const electric = this.selectedVehicle?.dataset.electric === '1';
        this.priceLabelTarget.textContent = electric ? '€ / kWh' : '€ / litro';
        this.litersLabelTarget.textContent = electric ? 'kWh' : 'Litri';
        this.effUnitTarget.textContent = electric ? 'km/kWh' : 'km/l';
        this.per100UnitTarget.textContent = electric ? 'kWh/100 km' : 'l/100 km';
        this.update();
    }

    get selectedVehicle() {
        return this.vehicleTargets.find((input) => input.checked);
    }

    update() {
        if (!this.hasOdometerTarget) return;
        const vehicle = this.selectedVehicle;
        const last = vehicle ? this.odometersValue[vehicle.value] : null;
        this.lastKmTarget.textContent = last ? `ultimo: ${this.int(last)} km` : '';
        const odometer = parseInt(this.odometerTarget.value.replace(/\D/g, ''), 10) || null;
        const km = last && odometer && odometer > last ? odometer - last : null;
        this.deltaTarget.textContent = km ? `+${this.int(km)} km` : '';

        const liters = this.num(this.litersTarget.value);
        const total = this.num(this.totalTarget.value);
        const show = Boolean(km && liters && this.fullTarget.checked && !this.missedTarget.checked);
        this.previewTarget.hidden = !show;
        if (show) {
            this.effValueTarget.textContent = this.fmt(km / liters, 1);
            this.per100ValueTarget.textContent = this.fmt((liters / km) * 100, 2);
            this.perKmValueTarget.textContent = total ? this.fmt(total / km, 2) : '—';
        }
    }

    num(text) {
        let value = String(text || '').trim().replace(/[\s€]/g, '');
        if (value.includes(',')) value = value.replace(/\./g, '').replace(',', '.');
        const n = parseFloat(value);
        return Number.isFinite(n) && n > 0 ? n : null;
    }

    fmt(n, decimals) {
        return n.toLocaleString('it-IT', { minimumFractionDigits: decimals, maximumFractionDigits: decimals, useGrouping: false });
    }

    int(n) {
        return Math.round(n).toLocaleString('it-IT');
    }
}
