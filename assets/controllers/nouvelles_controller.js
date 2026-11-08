import { Controller } from '@hotwired/stimulus';

/*
 * Pastille « Messages » du menu, tenue à jour sans recharger la page : toutes les 20 secondes (page visible),
 * demande l'état de la messagerie. Sur une conversation ouverte (élément [data-fil-message]), une nouvelle
 * réponse met la page à jour ; si une réponse est en cours d'écriture, un bandeau le propose seulement.
 */
const INTERVALLE = 20000;

export default class extends Controller {
    static values = { url: String };

    connect() {
        this.minuterie = setInterval(() => this.verifier(), INTERVALLE);
        this.surVisibilite = () => { if (!document.hidden) this.verifier(); };
        document.addEventListener('visibilitychange', this.surVisibilite);
    }

    disconnect() {
        clearInterval(this.minuterie);
        document.removeEventListener('visibilitychange', this.surVisibilite);
    }

    async verifier() {
        if (document.hidden) return;
        const fil = document.querySelector('[data-fil-message]');
        const url = new URL(this.urlValue, window.location.origin);
        if (fil) url.searchParams.set('message', fil.dataset.filMessage);
        let etat;
        try {
            const reponse = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!reponse.ok) return;
            etat = await reponse.json();
        } catch {
            return; // hors ligne : on réessaiera
        }
        const avant = parseInt(this.element.textContent, 10) || 0;
        this.element.textContent = etat.nonLus > 0 ? String(etat.nonLus) : '';
        this.element.hidden = !(etat.nonLus > 0);
        // Liste des messages ouverte : un nouveau message (ou une réponse) y apparaît.
        if (etat.nonLus > avant && document.querySelector('[data-liste-messages]')) {
            this.recharger();
            return;
        }

        if (fil && etat.fil !== undefined && etat.fil !== parseInt(fil.dataset.filSignature, 10)) {
            fil.dataset.filSignature = String(etat.fil);
            this.nouvelleReponse();
        }
    }

    nouvelleReponse() {
        const enCours = [...document.querySelectorAll('form.repondre textarea, form textarea[name="corps"]')].some((t) => t.value.trim() !== '');
        if (!enCours) {
            this.recharger();
            return;
        }
        if (document.querySelector('.bandeau-nouvelle')) return;
        const bandeau = document.createElement('button');
        bandeau.type = 'button';
        bandeau.className = 'bandeau-nouvelle';
        bandeau.textContent = 'Nouvelle réponse : afficher (votre texte en cours sera perdu)';
        bandeau.addEventListener('click', () => this.recharger());
        document.querySelector('main')?.prepend(bandeau);
    }

    recharger() {
        if (window.Turbo) {
            window.Turbo.visit(window.location.href, { action: 'replace' });
        } else {
            window.location.reload();
        }
    }
}
