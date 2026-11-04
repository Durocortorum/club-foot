import { Controller } from '@hotwired/stimulus';

/*
 * En-tête sur petit écran : ouvre et ferme le menu replié derrière le bouton « Menu ».
 * Turbo remplace l'en-tête à chaque page, le menu se referme donc tout seul après un clic.
 */
export default class extends Controller {
    static targets = ['bouton'];

    basculer() {
        const ouvert = this.element.classList.toggle('ouvert');
        this.boutonTarget.setAttribute('aria-expanded', ouvert);
    }
}
