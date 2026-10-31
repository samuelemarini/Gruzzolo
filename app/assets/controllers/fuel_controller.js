import { Controller } from '@hotwired/stimulus';
import { t, separators, parseNumber, formatNumber } from '../i18n.js';

/*
 * Rifornimento: dei tre valori prezzo/litri/totale si calcola quello che non e' fra gli ultimi due
 * modificati. Il totale e' l'importo del movimento (l'input grande in alto), tenuto allineato.
 * Mostra anche i km dall'ultimo rifornimento, una stima del consumo e, col pieno, la media dell'auto
 * con lo scarto di questo pieno.
 */
export default class extends Controller {
    static targets = ['vehicle', 'odometer', 'lastKm', 'delta', 'price', 'liters', 'total', 'full', 'missed',
        'priceBox', 'litersBox', 'totalBox', 'priceLabel', 'litersLabel',
        'preview', 'effValue', 'effUnit', 'per100Value', 'per100Unit', 'perKmValue', 'average', 'averageValue', 'averageLabel', 'averageCompare'];

    static values = { odometers: Object, averages: Object };

    connect() {
        if (!this.hasPriceTarget) return;
        this.amount = this.element.closest('form').querySelector('input[name="amount"]');
        this.order = [];
        // In modifica i tre valori ci sono gia': si considerano "scelti" prezzo e totale.
        if (this.priceTarget.value && this.totalTarget.value) this.order = ['total', 'price'];
        this.onAmount = () => {
            this.totalTarget.value = this.amount.value.replaceAll(separators().group, '');
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
        if (field === 'total') {
            // L'importo vuole i decimali della lingua: un "1.5" scritto qui in italiano e' 1,50, non 15
            const total = this.num(this.totalTarget.value);
            this.amount.value = total ? this.fmt(total, 2) : '';
        }
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
        this.priceLabelTarget.textContent = electric ? '€ / kWh' : t('€ / litro');
        this.litersLabelTarget.textContent = electric ? 'kWh' : t('Litri');
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
        this.lastKmTarget.textContent = last ? t('ultimo: %km% km', { km: this.int(last) }) : '';
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

        // Media dell'auto: col pieno, se l'auto ha gia' almeno un consumo calcolato.
        const average = vehicle ? this.averagesValue[vehicle.value] : null;
        this.averageTarget.hidden = !(average && this.fullTarget.checked);
        if (average) {
            const unit = vehicle.dataset.electric === '1' ? 'km/kWh' : 'km/l';
            this.averageValueTarget.textContent = `${this.fmt(average.value, 1)} ${unit}`;
            this.averageLabelTarget.textContent = average.label;
            const diff = show ? ((km / liters) / average.value - 1) * 100 : null;
            this.averageCompareTarget.textContent = diff !== null ? t('Questo pieno: %diff%% rispetto alla media', { diff: (diff >= 0 ? '+' : '−') + this.fmt(Math.abs(diff), 0) }) : '';
            this.averageCompareTarget.hidden = diff === null;
            this.averageCompareTarget.classList.toggle('text-income', diff !== null && diff >= 0);
            this.averageCompareTarget.classList.toggle('text-expense', diff !== null && diff < 0);
        }
    }

    num(text) {
        const n = parseNumber(text);
        return n !== null && n > 0 ? n : null;
    }

    fmt(n, decimals) {
        return formatNumber(n, decimals, false);
    }

    int(n) {
        return formatNumber(Math.round(n), 0);
    }
}
