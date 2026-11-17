import { Controller } from '@hotwired/stimulus';

/*
 * Formulaire de match :
 * - la feuille de match propose les joueurs de la catégorie du match, puis ceux qui peuvent être
 *   surclassés (catégories juste en dessous, voir Categorie::accueilleEnSurclassement), signalés
 *   par leur catégorie entre parenthèses ; case « tous les joueurs » pour un cas exceptionnel ;
 * - Buteur et Passeur ne proposent que les joueurs de la feuille présents sur le terrain à la minute du but,
 *   et jamais le même joueur des deux côtés ; un carton à une minute où le joueur n'est pas sur le terrain
 *   est signalé (le serveur refuse aussi, voir Rencontre::validerPresences) ;
 * - pour les remplacements, dans l'ordre des minutes : « Sort » ne propose que les joueurs
 *   sur le terrain, « Entre » que ceux qui n'y sont pas. Au départ sont sur le terrain
 *   les titulaires (case « Titulaire », cochée d'office pour les n° 1 à 11, hors « Non utilisé ») ; un joueur sorti peut revenir.
 * - le temps de jeu (« Min. ») n'est pas saisi : il vient des remplacements et de la durée de match
 *   de la catégorie. Un joueur de la feuille qui n'entre pas en cours de match a commencé le match
 *   (sauf poste « Non utilisé » ou numéro au-delà de 11) : non remplacé, il a joué toute la durée,
 *   sinon jusqu'à sa sortie. Un remplaçant joue de son entrée à sa sortie ou à la fin du match.
 *   Carton rouge : le temps s'arrête à la minute du carton. Carton « BL » (exclusion temporaire,
 *   sans remplacement) : 10 minutes en moins.
 *   Ancien match dont les temps enregistrés diffèrent du calcul : gardés tant que la feuille et les
 *   remplacements ne sont pas modifiés.
 * Le serveur contrôle aussi ces règles à l'enregistrement.
 */
export default class extends Controller {
    static targets = ['categorie', 'autres', 'convoque', 'joueur'];

    connect() {
        this.filtrer();
        // Temps enregistrés différents du calcul (anciens matchs) : gardés jusqu'à la première modification.
        const calcul = this.calculerTemps();
        this.tempsGardes = this.lignesFeuille().some(({ temps, joueur }) =>
            temps && temps.value !== '' && joueur.value !== '' && Number(temps.value) !== (calcul.get(joueur.value) ?? 0));
        this.pret = true;
        this.appliquerTemps();

        this.element.addEventListener('change', (event) => {
            if (this.convoqueTargets.includes(event.target) || this.joueurTargets.includes(event.target)
                || event.target.matches('[data-feuille-carton], [data-feuille-minute-carton], [data-score-target="butMinute"]')) {
                this.filtrer();
            }
        });
        // Numéros, postes et minutes changent l'état du terrain. Un numéro coche « Titulaire » (1 à 11) tant que la
        // case n'a pas été changée à la main sur cette ligne, et seulement s'il reste une place sur le terrain.
        this.element.addEventListener('input', (event) => {
            if (event.target.matches('[data-feuille-numero]')) {
                const caseTitulaire = event.target.closest('tr')?.querySelector('[data-feuille-titulaire]');
                const numero = parseInt(event.target.value, 10);
                if (caseTitulaire && !caseTitulaire.dataset.manuel) {
                    caseTitulaire.checked = !isNaN(numero) && numero <= 11 && (caseTitulaire.checked || this.titulaires().size < this.maxTitulaires());
                }
            }
            if (event.target.matches('[data-feuille-numero], [data-feuille-poste], [data-feuille-minute]')) {
                this.filtrer();
            }
        });
        // Case « Titulaire » changée à la main : gardée telle quelle (refusée si le terrain est déjà complet).
        this.element.addEventListener('change', (event) => {
            if (event.target.matches('[data-feuille-titulaire]')) {
                if (event.target.checked && this.titulaires().size > this.maxTitulaires()) {
                    event.target.checked = false;
                    this.compterTitulaires(`Déjà ${this.maxTitulaires()} titulaires : décochez d'abord un titulaire pour le mettre sur le banc.`);
                    return;
                }
                event.target.dataset.manuel = '1';
                this.filtrer();
            }
            // Système de jeu ou catégorie : le nombre de joueurs sur le terrain change.
            if (event.target.matches('[name="rencontre[systeme]"]')) this.compterTitulaires();
        });
        this.compterTitulaires();
    }

    // Lignes ajoutées ou retirées par le contrôleur « collection ».
    convoqueTargetConnected() { this.filtrer(); }
    convoqueTargetDisconnected() { this.filtrer(); }
    joueurTargetConnected() { this.filtrer(); }
    joueurTargetDisconnected() { this.filtrer(); }

    filtrer() {
        const categorie = this.hasCategorieTarget ? this.categorieTarget.value : '';
        const surclassables = new Set((this.hasCategorieTarget ? this.categorieTarget.selectedOptions[0]?.dataset.surclassement ?? '' : '')
            .split(',').filter((id) => id !== ''));
        // Sans catégorie choisie : aucun joueur proposé (sauf case « tous les joueurs du club »).
        const toutes = this.hasAutresTarget && this.autresTarget.checked;
        const surclasse = (option) => option.value !== '' && categorie !== '' && option.dataset.categorie !== categorie;
        const dansCategorie = (option) => toutes
            || (categorie !== '' && (option.dataset.categorie === categorie || surclassables.has(option.dataset.categorie)));

        // Un joueur ne figure qu'une fois sur la feuille : déjà choisi sur une autre ligne, il n'est plus proposé.
        this.convoqueTargets.forEach((select) => {
            const ailleurs = new Set(this.convoqueTargets.filter((autre) => autre !== select).map((autre) => autre.value).filter((v) => v !== ''));
            this.restreindre(select, (option) => dansCategorie(option) && !ailleurs.has(option.value));
            this.ranger(select, surclasse);
            const vide = select.querySelector('option[value=""]');
            if (vide) vide.textContent = categorie === '' && !toutes ? '— Choisissez d\'abord la catégorie —' : '— Joueur —';
        });

        const convoques = new Set(this.convoqueTargets.map((select) => select.value).filter((valeur) => valeur !== ''));
        const surFeuille = (option) => convoques.has(option.value);

        // Buts : buteur et passeur sur le terrain à la minute du but, pas de passe décisive à soi-même.
        const presences = this.presences();
        const absent = 'Ce joueur n\'est pas sur le terrain à cette minute.';
        this.joueurTargets.filter((select) => !select.dataset.feuilleSens).forEach((select) => {
            const pris = this.voisin(select)?.value ?? '';
            const minute = select.closest('tr').querySelector('[data-score-target="butMinute"]')?.value;
            this.restreindre(select, (option) => option.value !== pris && surFeuille(option) && this.estPresent(presences, option.value, minute));
            this.signaler(select, select.value !== '' && !this.estPresent(presences, select.value, minute), absent);
        });

        // Cartons : le joueur doit être sur le terrain à la minute du carton.
        this.lignesFeuille().forEach(({ joueur, ligne }) => {
            const minute = ligne.querySelector('[data-feuille-minute-carton]');
            if (minute) this.signaler(minute, joueur.value !== '' && minute.value !== '' && !this.estPresent(presences, joueur.value, minute.value), absent);
        });

        this.filtrerRemplacements(surFeuille);

        // Toute modification de la feuille, des remplacements ou de la catégorie recalcule les temps de jeu.
        if (this.pret) {
            this.tempsGardes = false;
            this.appliquerTemps();
            this.compterTitulaires();
        }
    }

    // Lignes de la feuille de match : joueur et case du temps de jeu.
    lignesFeuille() {
        return this.convoqueTargets.map((joueur) => ({ joueur, ligne: joueur.closest('tr'), temps: joueur.closest('tr').querySelector('[data-feuille-temps]') }));
    }

    // Titulaire : case « Titulaire » cochée (d'office pour les n° 1 à 11), hors poste « Non utilisé ».
    estTitulaire(ligne) {
        const poste = (ligne.querySelector('[data-feuille-poste]')?.value ?? '').trim().toLowerCase();
        const caseTitulaire = ligne.querySelector('[data-feuille-titulaire]');
        return poste !== 'non utilisé' && (caseTitulaire ? caseTitulaire.checked : true);
    }

    /**
     * Joueurs sur le terrain (gardien compris) : d'après le système de jeu choisi (« 4 4 2 » : 11), sinon la
     * catégorie (foot à 5, à 8 ou à 11). Même règle que Rencontre::joueursSurLeTerrain().
     */
    maxTitulaires() {
        const systeme = this.element.querySelector('[name="rencontre[systeme]"]')?.value ?? '';
        const chiffres = systeme.match(/\d+/g);
        if (chiffres) return chiffres.reduce((s, n) => s + Number(n), 0) + 1;
        const joueurs = this.hasCategorieTarget ? parseInt(this.categorieTarget.selectedOptions[0]?.dataset.joueurs, 10) : NaN;
        return joueurs > 0 ? joueurs : 11;
    }

    /**
     * « Titulaires : 9 / 11 », en rouge s'il y en a trop ; numéros portés par deux joueurs signalés en rouge
     * (l'enregistrement est alors refusé).
     */
    compterTitulaires(message = '') {
        const zone = this.element.querySelector('[data-feuille-compte]');
        if (!zone) return;
        const n = this.titulaires().size;
        const max = this.maxTitulaires();
        // Numéros en double.
        const champs = this.lignesFeuille().filter(({ joueur }) => joueur.value !== '').map(({ ligne }) => ligne.querySelector('[data-feuille-numero]')).filter(Boolean);
        const compte = new Map();
        champs.forEach((c) => { if (c.value !== '') compte.set(c.value, (compte.get(c.value) ?? 0) + 1); });
        const doubles = [...compte].filter(([, fois]) => fois > 1).map(([num]) => num);
        champs.forEach((c) => this.signaler(c, doubles.includes(c.value), 'Ce numéro est déjà porté par un autre joueur.'));
        const lignes = [message || `Titulaires cochés : ${n} / ${max}${n > max ? ' — trop de titulaires : décochez « Titulaire » pour les remplaçants.' : ''}`];
        if (doubles.length) lignes.push(`Numéro${doubles.length > 1 ? 's' : ''} en double : ${doubles.map((d) => `n° ${d}`).join(', ')} — chaque joueur doit avoir son propre numéro.`);
        zone.innerHTML = lignes.map((l) => this.echapper(l)).join('<br>');
        zone.classList.toggle('trop', !!message || n > max || doubles.length > 0);
    }

    echapper(texte) {
        return String(texte).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    titulaires() {
        const titulaires = new Set();
        this.lignesFeuille().forEach(({ joueur, ligne }) => {
            if (joueur.value !== '' && this.estTitulaire(ligne)) {
                titulaires.add(joueur.value);
            }
        });
        return titulaires;
    }

    // Lignes de remplacement dans l'ordre des minutes (sans minute : à la fin, dans l'ordre de saisie).
    lignesRemplacement() {
        return [...new Set(this.joueurTargets.filter((s) => s.dataset.feuilleSens).map((s) => s.closest('tr')))]
            .map((ligne, ordre) => ({
                ligne,
                ordre,
                minute: parseInt(ligne.querySelector('[data-feuille-minute]')?.value, 10),
                sortant: ligne.querySelector('[data-feuille-sens="sortant"]'),
                entrant: ligne.querySelector('[data-feuille-sens="entrant"]'),
            }))
            .sort((a, b) => (isNaN(a.minute) ? Infinity : a.minute) - (isNaN(b.minute) ? Infinity : b.minute) || a.ordre - b.ordre);
    }

    // Durée d'un match de la catégorie choisie (page Catégories), 90 à défaut.
    duree() {
        const duree = this.hasCategorieTarget ? parseInt(this.categorieTarget.selectedOptions[0]?.dataset.duree, 10) : NaN;
        return duree > 0 ? duree : 90;
    }

    // « 45+2 » → 45,02 : pour ranger les minutes, arrêts de jeu compris (comme Rencontre::ordreMinute).
    ordre(valeur) {
        const [base, arret] = String(valeur ?? '').split('+').map((n) => parseInt(n, 10));
        return isNaN(base) ? NaN : base + (arret || 0) / 100;
    }

    /*
     * Moments où chaque joueur de la feuille est sur le terrain : Map joueur → [[début, fin], …].
     * Même règle que Rencontre::presences() côté serveur.
     */
    presences() {
        // Sorties et entrées dans l'ordre des minutes : remplacements, puis expulsions (carton rouge avec sa minute).
        const evenements = this.lignesRemplacement().filter(({ minute }) => !isNaN(minute))
            .map(({ minute, sortant, entrant }) => ({ minute, sort: sortant.value, entre: entrant.value }));
        this.lignesFeuille().forEach(({ joueur, ligne }) => {
            const carton = ligne.querySelector('[data-feuille-carton]');
            const minute = this.ordre(ligne.querySelector('[data-feuille-minute-carton]')?.value);
            if (joueur.value !== '' && carton?.selectedOptions[0]?.dataset.couleur === 'rouge' && !isNaN(minute)) {
                evenements.push({ minute, sort: joueur.value, entre: '' });
            }
        });
        evenements.sort((a, b) => a.minute - b.minute);

        // Premier rôle de chaque joueur dans ces événements : il « entre » d'abord, ou il « sort » d'abord.
        const premierRole = new Map();
        for (const { sort, entre } of evenements) {
            if (sort !== '' && !premierRole.has(sort)) premierRole.set(sort, 'sort');
            if (entre !== '' && !premierRole.has(entre)) premierRole.set(entre, 'entre');
        }
        // Au coup d'envoi : les joueurs de la feuille qui n'entrent pas en cours de match, hors banc déclaré.
        const entree = new Map();
        this.lignesFeuille().forEach(({ joueur, ligne }) => {
            const banc = !this.estTitulaire(ligne);
            if (joueur.value !== '' && premierRole.get(joueur.value) !== 'entre' && (!banc || premierRole.get(joueur.value) === 'sort')) {
                entree.set(joueur.value, 0);
            }
        });

        const intervalles = new Map();
        const fermer = (id, debut, fin) => intervalles.set(id, [...(intervalles.get(id) ?? []), [debut, fin]]);
        for (const { minute, sort, entre } of evenements) {
            if (sort !== '' && entree.has(sort)) {
                fermer(sort, entree.get(sort), minute);
                entree.delete(sort);
            }
            if (entre !== '' && !entree.has(entre)) {
                entree.set(entre, minute);
            }
        }
        entree.forEach((debut, id) => fermer(id, debut, Infinity));
        return intervalles;
    }

    // Le joueur est-il sur le terrain à cette minute (« 34 », « 45+2 ») ? Minute inconnue : pas de contrôle.
    estPresent(presences, joueur, minute) {
        const m = this.ordre(minute);
        return isNaN(m) || (presences.get(joueur) ?? []).some(([debut, fin]) => m >= debut && m <= fin);
    }

    // Minutes jouées par joueur de la feuille : temps passé sur le terrain, dans la durée du match.
    calculerTemps() {
        const duree = this.duree();
        const temps = new Map();
        this.presences().forEach((intervalles, id) => {
            const total = intervalles.reduce((somme, [debut, fin]) => somme + Math.max(0, Math.min(fin, duree) - Math.min(debut, duree)), 0);
            temps.set(id, Math.round(total));
        });

        // Exclusion temporaire (carton « BL ») : 10 minutes hors du terrain, sans remplacement.
        this.lignesFeuille().forEach(({ joueur, ligne }) => {
            if (joueur.value !== '' && ligne.querySelector('[data-feuille-carton]')?.value === 'BL' && temps.has(joueur.value)) {
                temps.set(joueur.value, Math.max(0, temps.get(joueur.value) - 10));
            }
        });
        return temps;
    }

    appliquerTemps() {
        const calcul = this.calculerTemps();
        this.lignesFeuille().forEach(({ joueur, temps }) => {
            if (temps && !this.tempsGardes) {
                temps.value = joueur.value !== '' ? calcul.get(joueur.value) ?? 0 : '';
            }
        });
    }

    filtrerRemplacements(surFeuille) {
        const titulaires = this.titulaires();

        // true : sur le terrain ; false : sur le banc ou sorti ; inconnu si aucun titulaire n'est indiqué.
        const surTerrain = new Map([...titulaires].map((id) => [id, true]));
        const etat = (id) => (surTerrain.has(id) ? surTerrain.get(id) : (titulaires.size > 0 ? false : null));

        for (const { ligne } of this.lignesRemplacement()) {
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

    // Joueurs de la catégorie d'abord, surclassés ensuite avec leur catégorie : « DUPONT Léo (U15) ».
    ranger(select, surclasse) {
        const options = [...select.options];
        options.forEach((option, rang) => {
            if (option.dataset.libelle === undefined) option.dataset.libelle = option.textContent;
            if (option.dataset.rang === undefined) option.dataset.rang = rang;
            option.textContent = option.dataset.libelle + (surclasse(option) && option.dataset.categorieNom ? ` (${option.dataset.categorieNom})` : '');
        });
        const ordre = (option) => [option.value === '' ? 0 : (surclasse(option) ? 2 : 1), Number(option.dataset.rang)];
        const tries = options.slice().sort((a, b) => {
            const [x, y] = [ordre(a), ordre(b)];
            return x[0] - y[0] || x[1] - y[1];
        });
        if (tries.some((option, i) => option !== options[i])) {
            const valeur = select.value;
            select.append(...tries);
            select.value = valeur;
        }
    }

    // Case en rouge avec l'explication au survol ; le serveur refuse aussi l'enregistrement.
    signaler(champ, erreur, message) {
        if (champ.dataset.titre === undefined) champ.dataset.titre = champ.title;
        champ.classList.toggle('invalide', erreur);
        champ.title = erreur ? message : champ.dataset.titre;
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
