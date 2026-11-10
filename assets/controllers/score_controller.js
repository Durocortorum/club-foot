import { Controller } from '@hotwired/stimulus';

/*
 * Formulaire de match : côté du club et score.
 * - Club et adversaire sont saisis une fois ; « Le club joue à domicile / à l'extérieur » décide
 *   qui reçoit (le serveur les range ensuite, voir RencontreType).
 * - Les minutes proposées pour les buts suivent la durée de match de la catégorie (page Catégories) :
 *   U13 en 60 min : 1 à 30, 30+1…, 31 à 60, 60+1… (même liste que ButType::minutes()).
 * - Le score n'est pas saisi : il vient des buts du rapport de match (minute et camp de chaque but),
 *   mi-temps = moitié de la durée de match de la catégorie (à défaut : du plus long temps de jeu
 *   de la feuille, sinon 90 min). Il est affiché en tableau et recopié dans les champs cachés envoyés.
 * - Match existant dont le score enregistré ne correspond pas aux buts (anciens matchs aux buts
 *   incomplets) : le score enregistré est gardé tant que les buts ne sont pas modifiés.
 */
export default class extends Controller {
    static targets = ['categorie', 'cote', 'club', 'adversaire', 'mt1Dom', 'mt1Ext', 'finDom', 'finExt',
        'butMinute', 'butCamp', 'temps', 'nomDom', 'nomExt', 'affFinDom', 'affFinExt', 'affMt1', 'affMt2', 'butsDom', 'butsExt',
        'minuteCarton', 'rapide', 'rapideDom', 'rapideExt', 'nomRapideDom', 'nomRapideExt'];

    connect() {
        [...this.butMinuteTargets, ...this.minuteCartonTargets].forEach((select) => this.remplirMinutes(select));
        this.minuteCartonTargets.forEach((select) => this.basculerCarton(select.closest('tr')));
        this.butCampTargets.forEach((camp) => this.basculerCamp(camp));
        // Score enregistré différent des buts saisis : on le garde jusqu'à la première modification des buts.
        const enregistre = this.finDomTarget.value !== '' || this.finExtTarget.value !== '';
        this.garderEnregistre = enregistre && !this.concorde(this.calculer());
        this.element.addEventListener('input', (event) => this.saisie(event));
        this.element.addEventListener('change', (event) => this.saisie(event));
        this.pret = true;
        this.actualiser();
    }

    // Buts ajoutés ou retirés par le contrôleur « collection ».
    butCampTargetConnected(camp) {
        this.basculerCamp(camp);
        this.butsChanges();
    }
    butCampTargetDisconnected() { this.butsChanges(); }
    butMinuteTargetConnected(select) { if (this.pret) this.remplirMinutes(select); }
    minuteCartonTargetConnected(select) {
        if (this.pret) this.remplirMinutes(select);
        this.basculerCarton(select.closest('tr'));
    }
    minuteCartonTargetDisconnected() { if (this.pret) this.afficherButeurs(); }

    // Minute du carton : seulement quand un carton est choisi.
    basculerCarton(ligne) {
        const minute = ligne?.querySelector('[data-score-target="minuteCarton"]');
        const carton = ligne?.querySelector('[data-feuille-carton]');
        if (!minute || !carton) return;
        minute.hidden = carton.value === '';
        if (minute.hidden) minute.value = '';
    }

    saisie(event) {
        const cible = event.target;
        if ((this.hasRapideDomTarget && cible === this.rapideDomTarget) || (this.hasRapideExtTarget && cible === this.rapideExtTarget)) {
            this.scoreRapide();
        } else if (this.butCampTargets.includes(cible)) {
            this.basculerCamp(cible);
            this.butsChanges();
        } else if (this.butMinuteTargets.includes(cible) || this.tempsTargets.includes(cible)) {
            this.butsChanges();
        } else if (cible === this.clubTarget || cible === this.adversaireTarget) {
            this.afficherNoms();
        } else if (cible.matches('[data-feuille-carton]')) {
            this.basculerCarton(cible.closest('tr'));
            this.afficherButeurs();
        } else if (cible.matches('select[name$="[buteur]"], select[name$="[passeur]"], [data-score-numero], [data-score-target="minuteCarton"], [data-feuille-target="convoque"]')) {
            this.afficherButeurs();
        }
    }

    // But adverse : seulement le numéro du buteur ; but du club : buteur et passeur parmi la feuille de match.
    basculerCamp(camp) {
        const ligne = camp.closest('tr');
        const adverse = camp.selectedOptions[0]?.dataset.adverse === '1';
        ligne.querySelectorAll('select[name$="[buteur]"], select[name$="[passeur]"]').forEach((select) => {
            select.hidden = adverse;
            if (adverse) select.value = '';
        });
        const numero = ligne.querySelector('[data-score-numero]');
        if (numero) {
            numero.hidden = !adverse;
            if (!adverse) numero.value = '';
        }
    }

    // Score tapé directement : le match est joué ; le score enregistré auparavant n'est plus gardé.
    scoreRapide() {
        this.garderEnregistre = false;
        const joue = this.element.querySelector('input[type=checkbox][name$="[joue]"]');
        if (joue && (this.rapideDomTarget.value !== '' || this.rapideExtTarget.value !== '')) {
            joue.checked = true;
        }
        this.actualiser();
    }

    butsChanges() {
        if (!this.pret) return;
        this.garderEnregistre = false;
        this.actualiser();
    }

    changerCategorie() {
        [...this.butMinuteTargets, ...this.minuteCartonTargets].forEach((select) => this.remplirMinutes(select));
        this.butsChanges();
    }

    // Les buts du club changent de colonne : le score et les noms suivent.
    changerCote() {
        this.butsChanges();
    }

    clubADomicile() {
        return (this.coteTargets.find((radio) => radio.checked)?.value ?? 'domicile') === 'domicile';
    }

    // Recopie le score des buts dans les champs envoyés (sauf score ancien gardé) et met l'affichage à jour.
    actualiser() {
        const detailles = this.butCampTargets.length > 0;
        if (this.hasRapideTarget) {
            this.rapideTarget.hidden = detailles;
        }
        const rapide = !detailles && this.hasRapideDomTarget && (this.rapideDomTarget.value !== '' || this.rapideExtTarget.value !== '');
        if (rapide) {
            // « Score seulement » : pas de buts détaillés, donc pas de score à la mi-temps.
            this.finDomTarget.value = this.nombre(this.rapideDomTarget) ?? 0;
            this.finExtTarget.value = this.nombre(this.rapideExtTarget) ?? 0;
            this.mt1DomTarget.value = '';
            this.mt1ExtTarget.value = '';
        } else if (!this.garderEnregistre) {
            const calcul = this.calculer();
            const ecrire = (champ, valeur) => { champ.value = valeur ?? ''; };
            ecrire(this.mt1DomTarget, calcul.dom.mt1); ecrire(this.finDomTarget, calcul.dom.fin);
            ecrire(this.mt1ExtTarget, calcul.ext.mt1); ecrire(this.finExtTarget, calcul.ext.fin);
        }

        const [fd, fe, md, me] = [this.finDomTarget, this.finExtTarget, this.mt1DomTarget, this.mt1ExtTarget].map((c) => this.nombre(c));
        this.affFinDomTarget.textContent = fd ?? 0;
        this.affFinExtTarget.textContent = fe ?? 0;
        const mi = md !== null && me !== null;
        this.affMt1Target.textContent = mi ? `${md} – ${me}` : '–';
        this.affMt2Target.textContent = mi && fd !== null && fe !== null ? `${fd - md} – ${fe - me}` : '–';
        this.afficherNoms();
        this.afficherButeurs();
    }

    // Buteurs sous le score, dans l'ordre des minutes : « ABSOUS Nathan 12' (passe AGGOUN Tarek) ».
    afficherButeurs() {
        const clubADomicile = this.clubADomicile();
        const buts = this.butCampTargets.map((camp) => {
            const ligne = camp.closest('tr');
            const minute = ligne.querySelector('[data-score-target="butMinute"]')?.value ?? '';
            const [base, arret] = minute.split('+').map((n) => parseInt(n, 10));
            const nom = (champ) => {
                const select = ligne.querySelector(`select[name$="[${champ}]"]`);
                return select && select.value !== '' ? select.selectedOptions[0].dataset.libelle ?? select.selectedOptions[0].textContent.trim() : '';
            };
            const adverse = camp.selectedOptions[0]?.dataset.adverse === '1';
            const numero = ligne.querySelector('[data-score-numero]')?.value ?? '';
            return {
                cote: adverse !== clubADomicile ? 'dom' : 'ext',
                ordre: isNaN(base) ? Infinity : base + (arret || 0) / 100,
                minute: minute !== '' ? `${minute}'` : '',
                buteur: adverse ? (numero !== '' ? `N° ${numero}` : 'But adverse') : nom('buteur') || 'Buteur non indiqué',
                passeur: adverse ? '' : nom('passeur'),
            };
        }).sort((a, b) => a.ordre - b.ordre);

        const span = (className, textContent) => Object.assign(document.createElement('span'), { className, textContent });
        const liste = (cote) => buts.filter((b) => b.cote === cote).map((b) => {
            const element = document.createElement('li');
            element.append('⚽ ', b.buteur);
            if (b.minute) element.append(span('minute', b.minute));
            if (b.passeur) element.append(' ', span('passeur', `(passe ${b.passeur})`));
            return element;
        });

        // Cartons des joueurs du club (feuille de match), sous ses buteurs : « 🟨 DUPONT Léo 34' ».
        const icones = { jaune: '🟨', rouge: '🟥', blanc: '⬜' };
        const cartons = this.minuteCartonTargets.map((minute) => {
            const ligne = minute.closest('tr');
            const carton = ligne.querySelector('[data-feuille-carton]');
            const joueur = ligne.querySelector('[data-feuille-target="convoque"]');
            if (!carton || carton.value === '' || !joueur || joueur.value === '') return null;
            const option = joueur.selectedOptions[0];
            const [base, arret] = minute.value.split('+').map((n) => parseInt(n, 10));
            return {
                icone: carton.value === 'J+R' ? '🟨🟥' : icones[carton.selectedOptions[0]?.dataset.couleur] ?? '',
                nom: option.dataset.libelle ?? option.textContent.trim(),
                minute: minute.value !== '' ? `${minute.value}'` : '',
                ordre: isNaN(base) ? Infinity : base + (arret || 0) / 100,
            };
        }).filter(Boolean).sort((a, b) => a.ordre - b.ordre).map((c) => {
            const element = document.createElement('li');
            // Pas « carton » : classe déjà prise par les petits cartons de couleur de la fiche du match.
            element.className = 'ligne-carton';
            element.append(`${c.icone} `, c.nom);
            if (c.minute) element.append(span('minute', c.minute));
            return element;
        });

        this.butsDomTarget.replaceChildren(...liste('dom'), ...(clubADomicile ? cartons : []));
        this.butsExtTarget.replaceChildren(...liste('ext'), ...(clubADomicile ? [] : cartons));
    }

    afficherNoms() {
        const club = this.clubTarget.value.trim() || 'Club';
        const adversaire = this.adversaireTarget.value.trim() || 'Adversaire';
        [this.nomDomTarget.textContent, this.nomExtTarget.textContent] = this.clubADomicile() ? [club, adversaire] : [adversaire, club];
        if (this.hasNomRapideDomTarget) {
            [this.nomRapideDomTarget.textContent, this.nomRapideExtTarget.textContent] = [this.nomDomTarget.textContent, this.nomExtTarget.textContent];
        }
    }

    // Durée d'un match : celle de la catégorie, sinon le plus long temps de jeu de la feuille, sinon 90.
    duree() {
        const categorie = this.hasCategorieTarget ? parseInt(this.categorieTarget.selectedOptions[0]?.dataset.duree, 10) : NaN;
        if (categorie > 0) return categorie;
        const feuille = Math.max(0, ...this.tempsTargets.map((champ) => this.nombre(champ) ?? 0));
        return feuille > 0 ? feuille : 90;
    }

    // Liste des minutes d'un but pour la durée du match, en gardant la minute déjà choisie.
    remplirMinutes(select) {
        const duree = this.duree();
        const mi = Math.floor(duree / 2);
        const prolongation = duree + Math.floor(duree / 3);
        const plage = (de, a) => Array.from({ length: a - de + 1 }, (_, i) => String(de + i));
        const arrets = (fin) => Array.from({ length: 15 }, (_, i) => `${fin}+${i + 1}`);
        const groupes = [
            ['1re mi-temps', plage(1, mi)],
            ['Arrêts de jeu 1re mi-temps', arrets(mi)],
            ['2e mi-temps', plage(mi + 1, duree)],
            ['Arrêts de jeu 2e mi-temps', arrets(duree)],
            ['Prolongations', [...plage(duree + 1, prolongation), ...arrets(prolongation)]],
        ];
        const valeur = select.value;
        if (valeur !== '' && !groupes.some(([, minutes]) => minutes.includes(valeur))) {
            groupes.push(['Autre', [valeur]]);
        }

        select.replaceChildren(new Option('—', ''), ...groupes.map(([libelle, minutes]) => {
            const groupe = document.createElement('optgroup');
            groupe.label = libelle;
            groupe.append(...minutes.map((m) => new Option(`${m}'`, m)));
            return groupe;
        }));
        select.value = valeur;
    }

    // Score tiré des buts. Mi-temps à null si un but n'a pas de minute.
    calculer() {
        // « 30+2 » : parseInt donne 30, compté dans la 1re mi-temps d'un match de 60 min.
        const miTemps = Math.floor(this.duree() / 2);
        const clubADomicile = this.clubADomicile();
        const score = { dom: [0, 0], ext: [0, 0] };
        let minutesConnues = true;

        for (const camp of this.butCampTargets) {
            const adverse = camp.selectedOptions[0]?.dataset.adverse === '1';
            const cote = adverse !== clubADomicile ? 'dom' : 'ext';
            const minute = this.nombre(camp.closest('tr').querySelector('[data-score-target="butMinute"]'));
            if (minute === null) minutesConnues = false;
            score[cote][minute !== null && minute > miTemps ? 1 : 0]++;
        }

        const resume = (s) => ({ mt1: minutesConnues ? s[0] : null, fin: s[0] + s[1] });
        return { dom: resume(score.dom), ext: resume(score.ext) };
    }

    concorde(calcul) {
        return this.nombre(this.finDomTarget) === calcul.dom.fin && this.nombre(this.finExtTarget) === calcul.ext.fin;
    }

    nombre(champ) {
        const valeur = parseInt(champ?.value, 10);
        return isNaN(valeur) ? null : valeur;
    }
}
