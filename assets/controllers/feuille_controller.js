import { Controller } from '@hotwired/stimulus';

/*
 * Formulaire de match :
 * - la feuille de match ne propose que les joueurs de la catégorie du match
 *   (case « autres catégories » pour un surclassement) ;
 * - Buteur et Passeur ne proposent que les joueurs de la feuille (ou de la catégorie
 *   tant que la feuille est vide), et jamais le même joueur des deux côtés ;
 * - pour les remplacements, dans l'ordre des minutes : « Sort » ne propose que les joueurs
 *   sur le terrain, « Entre » que ceux qui n'y sont pas. Au départ sont sur le terrain
 *   les titulaires (n° 1 à 11, hors « Non utilisé ») ; un joueur sorti peut revenir.
 * Le serveur contrôle aussi ces règles à l'enregistrement.
 */
export default class extends Controller {
    static targets = ['categorie', 'autres', 'convoque', 'joueur'];

    connect() {
        this.element.addEventListener('change', (event) => {
            if (this.convoqueTargets.includes(event.target) || this.joueurTargets.includes(event.target)) {
                this.filtrer();
            }
        });
        // Numéros, postes et minutes changent l'état du terrain.
        this.element.addEventListener('input', (event) => {
            if (event.target.matches('[data-feuille-numero], [data-feuille-poste], [data-feuille-minute]')) {
                this.filtrer();
            }
        });
        this.filtrer();
    }

    // Lignes ajoutées ou retirées par le contrôleur « collection ».
    convoqueTargetConnected() { this.filtrer(); }
    convoqueTargetDisconnected() { this.filtrer(); }
    joueurTargetConnected() { this.filtrer(); }
    joueurTargetDisconnected() { this.filtrer(); }

    filtrer() {
        const categorie = this.hasCategorieTarget ? this.categorieTarget.value : '';
        const toutes = categorie === '' || (this.hasAutresTarget && this.autresTarget.checked);
        const dansCategorie = (option) => toutes || option.dataset.categorie === categorie;

        this.convoqueTargets.forEach((select) => this.restreindre(select, dansCategorie));

        const convoques = new Set(this.convoqueTargets.map((select) => select.value).filter((valeur) => valeur !== ''));
        const surFeuille = convoques.size > 0 ? (option) => convoques.has(option.value) : dansCategorie;

        // Buts : pas de passe décisive à soi-même.
        this.joueurTargets.filter((select) => !select.dataset.feuilleSens).forEach((select) => {
            const pris = this.voisin(select)?.value ?? '';
            this.restreindre(select, (option) => option.value !== pris && surFeuille(option));
        });

        this.filtrerRemplacements(surFeuille);
    }

    filtrerRemplacements(surFeuille) {
        const titulaires = new Set();
        this.convoqueTargets.forEach((select) => {
            const ligne = select.closest('tr');
            const numero = parseInt(ligne.querySelector('[data-feuille-numero]')?.value, 10);
            const poste = (ligne.querySelector('[data-feuille-poste]')?.value ?? '').trim().toLowerCase();
            if (select.value !== '' && numero >= 1 && numero <= 11 && poste !== 'non utilisé') {
                titulaires.add(select.value);
            }
        });

        // Lignes de remplacement dans l'ordre des minutes (sans minute : à la fin, dans l'ordre de saisie).
        const lignes = [...new Set(this.joueurTargets.filter((s) => s.dataset.feuilleSens).map((s) => s.closest('tr')))]
            .map((ligne, ordre) => ({ ligne, ordre, minute: parseInt(ligne.querySelector('[data-feuille-minute]')?.value, 10) }))
            .sort((a, b) => (isNaN(a.minute) ? Infinity : a.minute) - (isNaN(b.minute) ? Infinity : b.minute) || a.ordre - b.ordre);

        // true : sur le terrain ; false : sur le banc ou sorti ; inconnu si aucun titulaire n'est indiqué.
        const surTerrain = new Map([...titulaires].map((id) => [id, true]));
        const etat = (id) => (surTerrain.has(id) ? surTerrain.get(id) : (titulaires.size > 0 ? false : null));

        for (const { ligne } of lignes) {
            const sortant = ligne.querySelector('[data-feuille-sens="sortant"]');
            const entrant = ligne.querySelector('[data-feuille-sens="entrant"]');
            this.restreindre(sortant, (o) => o.value !== entrant.value && surFeuille(o) && etat(o.value) !== false);
            this.restreindre(entrant, (o) => o.value !== sortant.value && surFeuille(o) && etat(o.value) !== true);
            if (sortant.value !== '') surTerrain.set(sortant.value, false);
            if (entrant.value !== '') surTerrain.set(entrant.value, true);
        }
    }

    // L'autre liste de joueurs de la même ligne (passeur pour le buteur, et inversement).
    voisin(select) {
        return [...select.closest('tr').querySelectorAll('select')].find((s) => s !== select && this.joueurTargets.includes(s));
    }

    restreindre(select, garder) {
        for (const option of select.options) {
            // On garde l'option vide et le joueur déjà choisi, pour ne rien effacer sans le dire.
            const visible = option.value === '' || option.selected || garder(option);
            option.hidden = !visible;
            option.disabled = !visible;
        }
    }
}
