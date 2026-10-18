/*
 * Impedisce lo zoom accidentale. Il viewport (maximum-scale=1, user-scalable=no) e touch-action bastano
 * su Android; Safari iOS li ignora per il pizzico, quindi si bloccano qui i suoi gesti e i tocchi a due
 * dita. Sul computer si blocca lo zoom col pizzico del trackpad (rotella + Ctrl).
 */
const stop = (event) => event.preventDefault();

['gesturestart', 'gesturechange', 'gestureend'].forEach((name) => document.addEventListener(name, stop, { passive: false }));

document.addEventListener('touchmove', (event) => {
    if (event.touches.length > 1) event.preventDefault();
}, { passive: false });

document.addEventListener('wheel', (event) => {
    if (event.ctrlKey) event.preventDefault();
}, { passive: false });
