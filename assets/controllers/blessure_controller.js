import { Controller } from '@hotwired/stimulus';

/*
 * Formulaire de blessure (infirmerie/blessure.html.twig) :
 *  - la catégorie filtre la liste des joueurs ; choisir un joueur règle la catégorie ;
 *  - l'équipe au moment de la blessure se remplit avec la catégorie du joueur (si elle est vide) ;
 *  - « Localiser sur le corps » ouvre l'atlas anatomique du joueur choisi, en emportant la date saisie
 *    (valeur atlas : adresse de l'atlas avec « 0 » à la place de l'id du joueur ; vide en modification).
 */
export default class extends Controller {
    static targets = ['atlas', 'aide'];
    static values = { atlas: String };

    connect() {
        this.categorie = this.champ('categorie');
        this.joueur = this.champ('joueur');
        if (!this.joueur) return;
        // Copie de toutes les options : la liste filtrée est reconstruite (masquer une option ne marche pas partout).
        this.options = [...this.joueur.options].map((o) => o.cloneNode(true));
        this.surCategorie = () => this.filtrer();
        this.surJoueur = () => this.joueurChoisi();
        this.categorie?.addEventListener('change', this.surCategorie);
        this.joueur.addEventListener('change', this.surJoueur);
        if (this.categorie?.value) this.filtrer();
        this.majAtlas();
    }

    disconnect() {
        this.categorie?.removeEventListener('change', this.surCategorie);
        this.joueur?.removeEventListener('change', this.surJoueur);
    }

    champ(nom) {
        return this.element.querySelector(`[name$="[${nom}]"]`);
    }

    filtrer() {
        const cat = this.categorie.value;
        const choisi = this.joueur.value;
        this.joueur.replaceChildren(...this.options
            .filter((o) => !o.value || !cat || o.dataset.categorie === cat)
            .map((o) => o.cloneNode(true)));
        this.joueur.value = [...this.joueur.options].some((o) => o.value === choisi) ? choisi : '';
        this.majAtlas();
    }

    joueurChoisi() {
        const option = this.joueur.selectedOptions[0];
        if (option?.value && this.categorie && !this.categorie.disabled && this.categorie.value !== option.dataset.categorie) {
            this.categorie.value = option.dataset.categorie || '';
            this.filtrer();
            this.joueur.value = option.value;
        }
        const equipe = this.champ('equipe');
        if (equipe && !equipe.value && option?.dataset.equipe) equipe.value = option.dataset.equipe;
        this.majAtlas();
    }

    majAtlas() {
        if (!this.hasAtlasTarget) return;
        const choisi = !!this.joueur?.value;
        this.atlasTarget.disabled = !choisi;
        if (this.hasAideTarget) this.aideTarget.hidden = choisi;
    }

    atlas() {
        if (!this.atlasValue || !this.joueur?.value) return;
        let url = this.atlasValue.replace('/0/', `/${encodeURIComponent(this.joueur.value)}/`);
        const date = this.champ('date')?.value;
        if (date) url += `?date=${encodeURIComponent(date)}`;
        this.aller(url);
    }

    aller(url) {
        window.location.href = url;
    }
}
