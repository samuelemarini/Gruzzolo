import './stimulus_bootstrap.js';
import './styles/app.css';
import './styles/glass.css';
import './privacy.js';
import './confirm.js';
import './pull_refresh.js';
import './no_zoom.js';

// Nelle strisce orizzontali porta in vista la voce selezionata.
document.addEventListener('turbo:load', () => {
    // Solo la prima voce scelta di ogni striscia (con piu' conti selezionati restano visibili i primi).
    document.querySelectorAll('.chips--scroll').forEach((strip) => {
        const el = strip.querySelector('.is-active');
        (el?.closest('label') || el)?.scrollIntoView({ block: 'nearest', inline: 'center' });
    });
});
