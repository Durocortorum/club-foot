import './bootstrap.js';
import './confirmation.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
import './styles/app.css';

// Page de connexion (donc après une déconnexion) : la saisie de match gardée pour le stade est effacée de l'appareil.
const oublierMatchsGardes = () => {
    if (window.location.pathname === '/connexion' && 'caches' in window) {
        caches.delete('club-direct-v1').catch(() => {});
    }
};
oublierMatchsGardes();
document.addEventListener('turbo:load', oublierMatchsGardes);

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');
