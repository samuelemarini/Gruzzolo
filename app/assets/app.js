import './stimulus_bootstrap.js';
import './styles/app.css';
import './privacy.js';

// Nelle strisce orizzontali porta in vista la voce selezionata.
document.addEventListener('turbo:load', () => {
    document.querySelectorAll('.chips--scroll .is-active, .cat-picker input:checked').forEach((el) => {
        (el.closest('label') || el).scrollIntoView({ block: 'nearest', inline: 'center' });
    });
});
