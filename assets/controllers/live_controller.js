import { Controller } from '@hotwired/stimulus';
import { confirmer } from '../confirmation.js';
import { preparerBut, dessinerBut, ecouterBut, arrondir } from '../terrain_but.js';

/*
 * Match Live : version simple du direct, pour le téléphone. Gros score et chrono, cinq boutons (But, Carton,
 * Remplacement, Mi-temps, Fin), événements du match, statistiques, composition.
 *
 * Mêmes données que la saisie détaillée (direct_controller.js) : même sauvegarde sur l'appareil (clé « direct-<match> »),
 * même adresse d'enregistrement, même chrono commun. Les deux pages peuvent servir en même temps sur deux appareils.
 * Ce qui est noté ici n'a pas de zone du terrain : marqué « hors carte » (compté, pas dessiné sur la carte du bilan).
 *
 * Onglet « Actions » : le joueur puis l'action (passe, duel, contrôle, frappe…), enregistrée aussitôt ; le joueur reste
 * choisi pour sa prochaine action (relâché après une action adverse) ; « Annuler la dernière » d'un geste.
 *
 * Un but noté ici ajoute aussi la frappe (ou la tête) cadrée qui va avec, comme sur la tablette, pour que les tirs
 * cadrés le comptent ; elle porte un identifiant dérivé de celui du but, et part avec lui s'il est supprimé.
 */
const EVENEMENTS = ['B', 'BA', 'CJ', 'CR', 'JA', 'RA', 'RP', 'EX'];
const HORS_CARTE = { horsCarte: true };
/** Notés par les gros boutons (But, Carton, Rempl.) : pas dans l'onglet « Actions ». */
const HORS_ACTIONS = ['B', 'CJ', 'CR', 'RP', 'EX'];
/** Réglage « avec ou sans zones », commun avec la saisie détaillée (gardé sur l'appareil). */
const CLE_ZONES = 'direct-zones';
/** Temps laissé pour toucher la zone après l'action (ensuite l'action reste sans zone). */
const DELAI_ZONE = 8000;

export default class extends Controller {
    static values = { config: Object };
    static targets = ['score', 'minute', 'periode', 'chrono', 'pause', 'etat', 'boutonPeriode', 'boutonFin', 'analyse','evenements', 'stats',
        'compo', 'boutonTitulaires', 'dialogue',
        'boutonAnnulerAction', 'consigneAction', 'joueursAction', 'typesAction', 'dernieresActions',
        'boutonZones', 'zoneAction', 'sensAction', 'terrainAction', 'choixActions'];

    connect() {
        this.cfg = this.configValue;
        this.cle = `direct-${this.cfg.match}`;
        this.decalage = this.cfg.maintenant ? this.cfg.maintenant - Date.now() : 0;
        const sauve = this.lire();
        const vide = { periode: 1, enCours: false, debut: null, cumul: 0, fini: false, sens: 1, maj: 0 };
        this.chrono = { ...vide, ...(sauve.chrono ?? {}) };
        if (this.cfg.chrono && this.cfg.chrono.maj > (this.chrono.maj ?? 0)) this.chrono = { ...vide, ...this.cfg.chrono };
        this.chronoAEnvoyer = !!sauve.chronoAEnvoyer;
        this.enAttente = sauve.enAttente ?? {};
        this.supprimes = sauve.supprimes ?? [];
        this.titulairesAEnvoyer = sauve.titulairesAEnvoyer ?? null;
        if (this.titulairesAEnvoyer) this.cfg.titulaires = this.titulairesAEnvoyer;
        this.fusionner(this.cfg.actions);
        // Onglet « Actions » : joueur choisi, actions notées ici (la plus récente à la fin, pour « Annuler la dernière »).
        this.joueurAction = null;
        this.mesActions = [];
        try {
            this.avecZones = localStorage.getItem(CLE_ZONES) !== 'sans';
        } catch (e) {
            this.avecZones = true;
        }
        this.surReseau = () => this.envoyer();
        window.addEventListener('online', this.surReseau);
        this.minuteur = setInterval(() => this.tic(), 1000);
        this.relance = setInterval(() => this.envoyer(), 8000);
        this.afficher();
        this.tic();
        this.envoyer();
        this.preparerHorsLigne();
    }

    disconnect() {
        clearInterval(this.minuteur);
        clearInterval(this.relance);
        clearTimeout(this.finMessageAction);
        clearTimeout(this.minuteurZone);
        window.removeEventListener('online', this.surReseau);
        this.verrou?.release?.();
    }

    // ———————— Sauvegarde sur l'appareil (partagée avec la saisie détaillée) ————————

    lire() {
        try {
            return JSON.parse(localStorage.getItem(this.cle) || '{}');
        } catch (e) {
            return {};
        }
    }

    /** Garde ce que gère cette page, sans effacer le reste (météo, numéros… notés sur la saisie détaillée). */
    garder() {
        try {
            localStorage.setItem(this.cle, JSON.stringify({ ...this.lire(), chrono: this.chrono, chronoAEnvoyer: this.chronoAEnvoyer, enAttente: this.enAttente, supprimes: this.supprimes, titulairesAEnvoyer: this.titulairesAEnvoyer }));
        } catch (e) { /* stockage plein ou interdit : l'envoi au serveur reste tenté */ }
    }

    fusionner(duServeur) {
        this.actions = new Map(duServeur.map((a) => [a.uuid, a]));
        Object.values(this.enAttente).forEach((a) => this.actions.set(a.uuid, { ...a, auteur: this.cfg.moi }));
        this.supprimes.forEach((u) => this.actions.delete(u));
    }

    async preparerHorsLigne() {
        if (!('serviceWorker' in navigator) || !('caches' in window)) return;
        try {
            await navigator.serviceWorker.register('/sw.js');
            const pret = await navigator.serviceWorker.ready;
            const fichiers = performance.getEntriesByType('resource').map((e) => e.name).filter((u) => new URL(u).pathname.startsWith('/assets/'));
            pret.active?.postMessage({ type: 'garder', urls: [window.location.pathname, ...fichiers] });
        } catch (e) { /* pas de service worker : la page demande le réseau */ }
    }

    // ———————— Chrono (commun à tous les appareils) ————————

    maintenant() {
        return Date.now() + this.decalage;
    }

    secondes() {
        const c = this.chrono;
        return Math.max(0, c.cumul + (c.enCours && c.debut ? (this.maintenant() - c.debut) / 1000 : 0));
    }

    mi() {
        return Math.floor(this.cfg.duree / 2);
    }

    minuteCourante() {
        return (this.chrono.periode - 1) * this.mi() + Math.floor(this.secondes() / 60) + 1;
    }

    /** Période commencée : chrono lancé au moins une fois. */
    commencee() {
        return this.chrono.enCours || this.chrono.cumul > 0;
    }

    tic() {
        const c = this.chrono;
        const s = Math.floor(this.secondes());
        this.chronoTarget.textContent = `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;
        const avant = c.periode === 1 && !this.commencee();
        const pause = c.periode === 2 && !this.commencee();
        this.minuteTarget.textContent = c.fini ? 'Terminé' : (avant ? 'À venir' : (pause ? 'Mi-temps' : `${this.minuteCourante()}'`));
        this.minuteTarget.classList.toggle('arrete', !c.enCours);
        this.periodeTarget.textContent = c.fini ? 'Match terminé' : (avant ? 'Avant le match' : (pause ? 'Mi-temps' : (c.periode === 1 ? '1re période' : '2e période')));
        this.pauseTarget.textContent = c.enCours ? '⏸' : '▶';
        this.pauseTarget.disabled = !!c.fini || avant || pause;
        // Bouton de période : coup d'envoi → mi-temps → 2e période ; « Fin » pendant la 2e période.
        const [icone, texte, actif] = c.fini ? ['✓', 'Terminé', false]
            : (avant ? ['▶', 'Coup d\'envoi', true] : (c.periode === 1 ? ['⏸', 'Mi-temps', true] : (pause ? ['▶', '2e période', true] : ['✓', 'Mi-temps', false])));
        this.boutonPeriodeTarget.innerHTML = `<span class="icone" aria-hidden="true">${icone}</span>${texte}`;
        this.boutonPeriodeTarget.disabled = !actif;
        this.boutonPeriodeTarget.classList.toggle('a-faire', actif && (avant || pause));
        this.boutonFinTarget.disabled = !!c.fini || c.periode !== 2 || pause;
        if (this.hasAnalyseTarget) this.analyseTarget.hidden = !c.fini;
    }

    chronoChange() {
        this.chrono.maj = Math.max(this.maintenant(), (this.chrono.maj ?? 0) + 1);
        this.chronoAEnvoyer = true;
        this.garder();
        this.tic();
        this.afficher();
        this.envoyer();
    }

    demarrer() {
        this.chrono.enCours = true;
        this.chrono.debut = this.maintenant();
        this.garderEcranAllume();
    }

    arreter() {
        this.chrono.cumul = this.secondes();
        this.chrono.enCours = false;
        this.chrono.debut = null;
        this.verrou?.release?.();
    }

    pause() {
        if (this.chrono.fini) return;
        if (this.chrono.enCours) this.arreter(); else this.demarrer();
        this.chronoChange();
    }

    moins() {
        this.ajuster(-60);
    }

    plus() {
        this.ajuster(60);
    }

    ajuster(secondes) {
        this.chrono.cumul = Math.max(0, this.secondes() + secondes);
        if (this.chrono.enCours) this.chrono.debut = this.maintenant();
        this.chronoChange();
    }

    /** Coup d'envoi, mi-temps (après confirmation) ou reprise de la 2e période. */
    async periode() {
        const c = this.chrono;
        if (c.fini) return;
        if (!this.commencee()) {
            this.demarrer();
            this.chronoChange();
            return;
        }
        if (c.periode !== 1) return;
        const m = this.minuteCourante();
        if (!await confirmer('Score à la pause', {
            titre: '⏸ Mi-temps ?', bouton: 'Siffler la mi-temps', style: 'principal', classe: 'dialogue-periode', score: this.scoreTableau(),
            detail: `Fin de la 1re période à la ${m}${m === 1 ? 're' : 'e'} minute. « ▶ 2e période » relancera le chrono.`,
        })) return;
        this.chrono = { periode: 2, enCours: false, debut: null, cumul: 0, fini: false, sens: -c.sens, maj: c.maj };
        this.chronoChange();
    }

    async fin() {
        const c = this.chrono;
        if (c.fini || c.periode !== 2) return;
        const m = this.minuteCourante();
        if (!await confirmer('Score final', {
            titre: '🏁 Fin du match ?', bouton: 'Siffler la fin du match', style: 'principal', classe: 'dialogue-periode', score: this.scoreTableau(),
            detail: `Fin du match à la ${m}e minute. La feuille de match est mise à jour (buts, cartons, changements).`,
        })) return;
        this.arreter();
        c.fini = true;
        this.chronoChange();
    }

    async garderEcranAllume() {
        try {
            this.verrou = await navigator.wakeLock?.request('screen');
        } catch (e) { /* pas de verrou d'écran sur ce navigateur */ }
    }

    // ———————— Équipes, joueurs, score ————————

    /** Score [nous, eux] après les buts de la liste (tous par défaut). */
    compter(liste = [...this.actions.values()]) {
        return [liste.filter((a) => a.type === 'B').length, liste.filter((a) => a.type === 'BA').length];
    }

    /** Dans l'ordre du match : l'équipe qui reçoit d'abord. */
    ordre(nous, eux) {
        return this.cfg.domicile ? [nous, eux] : [eux, nous];
    }

    texteScore(score = this.compter()) {
        return this.ordre(...score).join(' – ');
    }

    /** [équipe, score, équipe] pour la fenêtre de fin de période. */
    scoreTableau() {
        const [gauche, droite] = this.ordre(this.cfg.nous, this.cfg.adversaire);
        return [gauche, this.texteScore(), droite];
    }

    joueur(id) {
        return this.cfg.joueurs.find((j) => j.id === id);
    }

    /** « Prénom NOM », ou « Prénom N. » sauf si un coéquipier s'écrit pareil (deux « Robin D. »). */
    nom(j, complet = true) {
        if (!j) return '?';
        const court = (x) => `${x.prenom} ${(x.nom || '').slice(0, 1)}.`;
        const ambigu = this.cfg.joueurs.some((x) => x.id !== j.id && court(x) === court(j));
        return complet || ambigu ? `${j.prenom} ${j.nom}` : court(j);
    }

    /** Titulaires : ceux choisis sur un appareil, sinon les numéros 1 à 11 (règle de la feuille de match). */
    titulaires() {
        if (Array.isArray(this.cfg.titulaires)) return this.cfg.titulaires;
        return this.cfg.joueurs.filter((j) => j.numero >= 1 && j.numero <= 11).map((j) => j.id);
    }

    /**
     * Qui est sur le terrain maintenant : les titulaires, puis les changements dans l'ordre ; un joueur exclu (rouge)
     * ne revient pas, un carton blanc sort 10 minutes. Sans titulaires connus, rien n'est sûr (connu : false).
     */
    terrain(sauf = null) {
        const titulaires = this.titulaires();
        const etat = new Map(this.cfg.joueurs.map((j) => [j.id, titulaires.includes(j.id)]));
        const exclus = new Set();
        const maintenant = { periode: this.chrono.periode, minute: this.minuteCourante() };
        [...this.actions.values()].filter((a) => ['RP', 'CR', 'EX'].includes(a.type) && a.joueur && a.uuid !== sauf)
            .sort((a, b) => (a.periode - b.periode) || (a.minute - b.minute))
            .forEach((a) => {
                if (a.type === 'EX') {
                    if (a.periode === maintenant.periode && maintenant.minute < a.minute + 10) exclus.add(a.joueur);
                    return;
                }
                etat.set(a.joueur, false);
                if (a.type === 'CR') exclus.add(a.joueur);
                else if (a.details?.entrant) etat.set(a.details.entrant, true);
            });
        return { etat, exclus, connu: titulaires.length > 0 };
    }

    // ———————— Saisie : fenêtre commune (but, carton, tir, remplacement) ————————

    but() {
        this.ouvrir('but');
    }

    carton() {
        this.ouvrir('carton');
    }

    tir(event) {
        this.ouvrir('tir', event.currentTarget.dataset.camp);
    }

    remplacement() {
        this.ouvrir('rempl', 'nous');
    }

    /** Corner : noté d'un coup, sans fenêtre. */
    corner(event) {
        const nous = event.currentTarget.dataset.camp === 'nous';
        this.noter([{ uuid: this.nouvelUuid(), type: nous ? 'CO' : 'CA', joueur: null, details: HORS_CARTE }]);
        this.annoncer(`Corner ${nous ? this.cfg.nous : this.cfg.adversaire} noté (${this.minuteCourante()}').`);
    }

    ouvrir(type, camp = null) {
        this.saisie = { type, camp };
        const titres = { but: '⚽ But', carton: 'Carton', tir: 'Tir', rempl: '⇄ Remplacement' };
        const camps = camp ? '' : `<div class="live-camps" data-action="change->live#changerCamp">
            ${[['nous', this.cfg.nous], ['eux', this.cfg.adversaire]].map(([v, n]) => `<label class="choix-carte"><input type="radio" name="camp" value="${v}" ${v === 'nous' ? 'checked' : ''}> ${this.echapper(n)}</label>`).join('')}
        </div>`;
        this.dialogueTarget.innerHTML = `<form method="dialog" class="live-form">
            <h2 class="dialogue-titre">${titres[type]}</h2>
            ${camps}
            <div data-zone="details"></div>
            <div class="live-minute-saisie">
                <span>Minute</span>
                <button type="button" class="bouton petit" data-action="live#minuteMoins" aria-label="Une minute de moins">−1</button>
                <input type="number" name="minute" min="0" max="130" inputmode="numeric" value="${this.minuteCourante()}" aria-label="Minute">
                <button type="button" class="bouton petit" data-action="live#minutePlus" aria-label="Une minute de plus">+1</button>
            </div>
            <p class="flash flash-erreur petit" data-erreur hidden></p>
            <div class="dialogue-actions">
                <button value="annuler" class="bouton" formnovalidate>Annuler</button>
                <button type="button" class="bouton principal" data-action="live#valider">Enregistrer</button>
            </div>
        </form>`;
        this.dessinerDetails();
        this.dialogueTarget.showModal();
    }

    changerCamp() {
        this.dessinerDetails();
    }

    camp() {
        return this.saisie.camp ?? this.dialogueTarget.querySelector('input[name=camp]:checked')?.value ?? 'nous';
    }

    /** Choix d'un groupe de cases (radio) : [valeur, libellé]. */
    choix(nom, options) {
        return `<div class="live-options">${options.map(([v, l], i) => `<label class="choix-carte"><input type="radio" name="${nom}" value="${v}" ${i === 0 ? 'checked' : ''}> ${l}</label>`).join('')}</div>`;
    }

    /** Joueurs à toucher (sur le terrain d'abord quand on le sait). */
    grilleJoueurs(nom, ids, titre) {
        const tuile = (j) => `<label class="live-joueur"><input type="radio" name="${nom}" value="${j.id}"><span class="num">${j.numero ?? ''}</span><span class="qui">${this.echapper(j.prenom)} <b>${this.echapper(j.nom)}</b></span></label>`;
        const liste = ids.map((id) => this.joueur(id)).filter(Boolean);
        return `<p class="live-libelle">${titre}</p><div class="live-joueurs">${liste.map(tuile).join('') || '<p class="discret petit">Aucun joueur.</p>'}</div>`;
    }

    /** Tous les joueurs du match : ceux sur le terrain d'abord, puis les autres. */
    joueursTries() {
        const { etat, connu } = this.terrain();
        const ids = this.cfg.joueurs.map((j) => j.id);
        return connu ? [...ids.filter((id) => etat.get(id)), ...ids.filter((id) => !etat.get(id))] : ids;
    }

    dessinerDetails() {
        const { type } = this.saisie;
        const nous = this.camp() === 'nous';
        let html = '';
        if (type === 'but' && nous) {
            html = this.choix('geste', [['tir', 'Tir'], ['tete', 'Tête'], ['csc', 'Contre son camp (adversaire)']])
                + `<div class="si-joueur">${this.grilleJoueurs('joueur', this.joueursTries(), 'Buteur')}
                    <label class="live-libelle">Passeur décisif <select name="passeur"><option value="">Aucun</option>${this.joueursTries().map((id) => this.joueur(id)).map((j) => `<option value="${j.id}">${j.numero ? `${j.numero} · ` : ''}${this.echapper(this.nom(j))}</option>`).join('')}</select></label></div>`;
        } else if (type === 'but') {
            html = `<p class="discret">But de ${this.echapper(this.cfg.adversaire)}.</p>` + this.choix('geste', [['tir', 'Tir'], ['tete', 'Tête']]);
        }
        if (type === 'but') {
            // Facultatif : ballon au départ du tir et flèche jusqu'à l'entrée dans le but (comme sur la saisie détaillée).
            html += `<p class="live-libelle">Où ? <small class="discret">(facultatif) déplacez le ballon là où part le tir, puis la pointe de la flèche là où il entre dans le but</small></p>
                <svg class="terrain-but" data-but-terrain role="img" aria-label="Demi-terrain avec le but"></svg>`;
        } else if (type === 'carton') {
            html = this.choix('couleur', nous ? [['J', '🟨 Jaune'], ['R', '🟥 Rouge'], ['B', '⬜ Blanc (10 min)']] : [['J', '🟨 Jaune'], ['R', '🟥 Rouge']])
                + (nous ? this.grilleJoueurs('joueur', this.joueursTries(), 'Joueur') : '');
        } else if (type === 'tir') {
            html = this.choix('cadre', [['oui', 'Cadré'], ['non', 'Non cadré']]) + this.choix('geste', [['tir', 'Frappe'], ['tete', 'Tête']])
                + (nous ? this.grilleJoueurs('joueur', this.joueursTries(), 'Tireur') : `<p class="discret petit">Tir de ${this.echapper(this.cfg.adversaire)}.</p>`);
        } else if (type === 'rempl') {
            const { etat, exclus, connu } = this.terrain();
            const ids = this.cfg.joueurs.map((j) => j.id).filter((id) => !exclus.has(id));
            const sur = connu ? ids.filter((id) => etat.get(id)) : ids;
            const banc = connu ? ids.filter((id) => !etat.get(id)) : ids;
            // « Personne » : le joueur sort sans être remplacé (plus de remplaçant, blessure…).
            html = this.grilleJoueurs('sortant', sur, '↓ Sort')
                + this.grilleJoueurs('entrant', banc, '↑ Entre').replace(/(<p class="discret petit">Aucun joueur\.<\/p>)?<\/div>$/, '<label class="live-joueur personne"><input type="radio" name="entrant" value="0"><span class="num">–</span><span class="qui">Personne (sans remplaçant)</span></label></div>')
                + this.choix('motif', [['Choix', 'Choix de l\'entraîneur'], ['Blessure', 'Blessure']])
                + (connu ? '' : '<p class="discret petit">Astuce : choisissez les titulaires (onglet « Compo ») pour ne voir que les joueurs sur le terrain.</p>');
        }
        this.dialogueTarget.querySelector('[data-zone=details]').innerHTML = html;
        const svg = this.dialogueTarget.querySelector('[data-but-terrain]');
        this.trace = null;
        if (svg) {
            // Nos buts vers le but adverse (à droite), ceux de l'adversaire vers le nôtre (à gauche).
            this.trace = preparerBut(nous);
            this.traceTouchee = false;
            // Ballon ou flèche déplacé : le but est placé ; sinon il reste « hors carte » (pas de place inventée).
            svg.addEventListener('pointerdown', (e) => { if (e.target.closest('[data-poignee]')) this.traceTouchee = true; });
            ecouterBut(svg, () => this.trace);
            dessinerBut(svg, this.trace);
        }
    }

    minuteMoins() {
        this.decalerMinute(-1);
    }

    minutePlus() {
        this.decalerMinute(1);
    }

    decalerMinute(n) {
        const champ = this.dialogueTarget.querySelector('input[name=minute]');
        champ.value = Math.max(0, Math.min(130, (parseInt(champ.value, 10) || 0) + n));
    }

    valeur(nom) {
        return this.dialogueTarget.querySelector(`[name=${nom}]:checked, select[name=${nom}]`)?.value ?? '';
    }

    erreur(texte) {
        const p = this.dialogueTarget.querySelector('[data-erreur]');
        p.textContent = texte;
        p.hidden = false;
    }

    valider() {
        const { type } = this.saisie;
        const nous = this.camp() === 'nous';
        const minute = Math.max(0, Math.min(130, parseInt(this.dialogueTarget.querySelector('input[name=minute]').value, 10) || 0));
        const joueur = Number(this.valeur('joueur')) || null;
        const uuid = this.nouvelUuid();
        let actions = [];
        if (type === 'but') {
            const geste = this.valeur('geste') || 'tir';
            if (nous && geste !== 'csc' && !joueur) return this.erreur('Touchez le buteur.');
            const passeur = Number(this.valeur('passeur'));
            // Placé sur le terrain : départ et arrivée du ballon, zone du direct d'après le départ (en mètres).
            const t = this.trace && this.traceTouchee ? this.trace : null;
            const place = t
                ? { zx: Math.max(0, Math.min(5, Math.floor(t.depart[0] / 17.5))), zy: Math.max(0, Math.min(2, Math.floor(t.depart[1] / (68 / 3)))) }
                : {};
            const ou = t ? { depart: arrondir(t.depart), arrivee: arrondir(t.arrivee) } : HORS_CARTE;
            const details = { geste, ...ou, ...(nous && passeur && passeur !== joueur && geste !== 'csc' ? { passeur } : {}) };
            actions = [{ uuid, type: nous ? 'B' : 'BA', joueur: nous && geste !== 'csc' ? joueur : null, details, ...place }];
            // La frappe cadrée qui va avec (pas pour un contre son camp), à la même place que le but si elle est connue.
            const tir = t ? { ...place } : { details: HORS_CARTE };
            if (nous && geste !== 'csc') actions.push({ uuid: this.uuidTir(uuid), type: geste === 'tete' ? 'TC' : 'FC', joueur, ...tir });
            if (!nous) actions.push({ uuid: this.uuidTir(uuid), type: geste === 'tete' ? 'ATC' : 'AC', joueur: null, ...tir });
        } else if (type === 'carton') {
            const couleur = this.valeur('couleur');
            if (nous && !joueur) return this.erreur('Touchez le joueur.');
            const code = nous ? { J: 'CJ', R: 'CR', B: 'EX' }[couleur] : { J: 'JA', R: 'RA' }[couleur];
            actions = [{ uuid, type: code, joueur: nous ? joueur : null, details: code === 'EX' ? null : HORS_CARTE }];
        } else if (type === 'tir') {
            if (nous && !joueur) return this.erreur('Touchez le tireur.');
            const cadre = this.valeur('cadre') === 'oui';
            const tete = this.valeur('geste') === 'tete';
            const code = nous ? (tete ? (cadre ? 'TC' : 'TH') : (cadre ? 'FC' : 'FH')) : (tete ? (cadre ? 'ATC' : 'ATH') : (cadre ? 'AC' : 'AH'));
            actions = [{ uuid, type: code, joueur: nous ? joueur : null, details: HORS_CARTE }];
        } else if (type === 'rempl') {
            const sortant = Number(this.valeur('sortant'));
            const entrant = Number(this.valeur('entrant'));
            if (!sortant) return this.erreur('Touchez le joueur qui sort.');
            if (this.valeur('entrant') === '') return this.erreur('Touchez le joueur qui entre (ou « Personne »).');
            if (sortant === entrant) return this.erreur('Le joueur qui entre doit être différent de celui qui sort.');
            actions = [{ uuid, type: 'RP', joueur: sortant, details: { ...(entrant ? { entrant } : {}), motif: this.valeur('motif') || 'Choix' } }];
        }
        this.noter(actions, minute);
        this.dialogueTarget.close();
        navigator.vibrate?.(type === 'but' ? [30, 60, 30] : 25);
        if (type === 'but' && nous) this.annoncer('But enregistré !');
    }

    /** Enregistre des actions (période et minute du chrono, sauf minute donnée), puis envoi. */
    noter(actions, minute = this.minuteCourante()) {
        actions.forEach((a) => {
            const action = { zx: a.type === 'RP' || a.type === 'EX' ? 0 : 2, zy: 1, periode: this.chrono.periode, minute, ...a };
            this.actions.set(action.uuid, { ...action, auteur: this.cfg.moi });
            this.enAttente[action.uuid] = action;
        });
        this.garder();
        this.afficher();
        this.envoyer();
    }

    /** Identifiant de la frappe cadrée qui accompagne un but noté ici. */
    uuidTir(uuid) {
        return `ffffffff${uuid.slice(8)}`;
    }

    aMoi(a) {
        return !a.auteur || a.auteur === this.cfg.moi;
    }

    async supprimer(event) {
        const a = this.actions.get(event.currentTarget.dataset.uuid);
        if (!a || !this.aMoi(a) || !await confirmer(`${a.minute}' · ${this.libelle(a)}`, { titre: 'Supprimer cet événement ?', bouton: 'Supprimer' })) return;
        [a.uuid, ...(['B', 'BA'].includes(a.type) ? [this.uuidTir(a.uuid)] : [])].forEach((u) => {
            const x = this.actions.get(u);
            if (!x || !this.aMoi(x)) return;
            this.actions.delete(u);
            delete this.enAttente[u];
            this.supprimes.push(u);
        });
        this.garder();
        this.afficher();
        this.envoyer();
    }

    annoncer(texte) {
        this.annonce = texte;
        clearTimeout(this.finAnnonce);
        this.finAnnonce = setTimeout(() => { this.annonce = null; this.etatEnvoi(); }, 4000);
        this.etatEnvoi();
    }

    // ———————— Onglets ————————

    onglet(event) {
        const nom = event.currentTarget.dataset.onglet;
        this.element.dataset.onglet = nom;
        this.element.querySelectorAll('.live-onglets button').forEach((b) => b.classList.toggle('actif', b.dataset.onglet === nom));
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // ———————— Affichage ————————

    libelle(a) {
        const j = this.joueur(a.joueur);
        const adv = this.cfg.adversaire;
        switch (a.type) {
        case 'B': return `But – ${a.details?.geste === 'csc' ? `contre son camp (${adv})` : this.nom(j)}${a.details?.geste === 'tete' ? ' (tête)' : ''}`;
        case 'BA': return `But – ${adv}`;
        case 'CJ': return `Carton jaune – ${this.nom(j)}`;
        case 'CR': return `Carton rouge – ${this.nom(j)}`;
        case 'JA': return `Carton jaune – ${adv}`;
        case 'RA': return `Carton rouge – ${adv}`;
        case 'EX': return `Carton blanc – ${this.nom(j)} (10 min)`;
        case 'RP': return `Remplacement – ↓ ${this.nom(j)}${a.details?.entrant ? ` ↑ ${this.nom(this.joueur(a.details.entrant))}` : ' (sans remplaçant)'}${a.details?.motif === 'Blessure' ? ' (blessure)' : ''}`;
        default: {
            const quoi = this.cfg.types[a.type] ?? this.cfg.adverses[a.type] ?? a.type;
            return j ? `${this.nom(j)} – ${quoi}` : quoi;
        }
        }
    }

    // ———————— Onglet « Actions » : joueur puis action, enregistrée aussitôt ————————

    choisirJoueurAction(event) {
        const id = Number(event.currentTarget.dataset.joueur);
        // Le joueur déjà choisi, touché à nouveau : relâché.
        this.joueurAction = this.joueurAction === id ? null : id;
        this.messageAction = null;
        // Joueur suivant touché sans zone : l'action précédente reste sans zone.
        this.finirZone();
        this.dessinerActions();
    }

    voirTousJoueurs() {
        this.tousJoueurs = !this.tousJoueurs;
        this.dessinerActions();
    }

    choisirAction(event) {
        const type = event.currentTarget.dataset.type;
        const adverse = type in this.cfg.adverses;
        // Corner : pas de joueur à choisir (comme le « + Corner » des statistiques).
        if (!adverse && type !== 'CO' && !this.joueurAction) {
            this.direAction('Touchez d\'abord le joueur, puis son action.', true);
            return;
        }
        const action = { uuid: this.nouvelUuid(), type, joueur: adverse || type === 'CO' ? null : this.joueurAction, details: HORS_CARTE };
        this.mesActions.push(action.uuid);
        // Action adverse : le ballon a changé de camp, le joueur est relâché.
        if (adverse) this.joueurAction = null;
        const bouton = event.currentTarget;
        bouton.classList.add('touchee');
        setTimeout(() => bouton.classList.remove('touchee'), 300);
        navigator.vibrate?.(25);
        this.direAction(`✓ ${this.minuteCourante()}' · ${this.libelle(action)}${adverse ? ` (${this.cfg.adversaire})` : ''}${this.avecZones ? ' · touchez la zone (facultatif)' : ''}`);
        if (this.avecZones) this.attendreZone(action.uuid);
        this.noter([action]);
    }

    // ———————— Zone du terrain, facultative, juste après l'action ————————

    attendreZone(uuid) {
        clearTimeout(this.minuteurZone);
        this.aPlacer = uuid;
        this.minuteurZone = setTimeout(() => this.passerZone(), DELAI_ZONE);
    }

    finirZone() {
        clearTimeout(this.minuteurZone);
        this.aPlacer = null;
    }

    passerZone() {
        this.finirZone();
        this.messageAction = null;
        this.dessinerActions();
    }

    choisirZoneAction(event) {
        const a = this.actions.get(this.aPlacer);
        this.finirZone();
        if (!a) return;
        const zone = event.currentTarget;
        zone.classList.add('touchee');
        navigator.vibrate?.(25);
        // Placée : plus « hors carte », dessinée sur la carte du bilan.
        const action = { uuid: a.uuid, type: a.type, joueur: a.joueur, periode: a.periode, minute: a.minute, zx: Number(zone.dataset.zx), zy: Number(zone.dataset.zy) };
        this.direAction(`✓ Zone notée · ${this.libelle(action)}`);
        this.noter([action], a.minute);
    }

    /** Côté du terrain : commun à tous les appareils (comme sur la saisie détaillée), inversé à la mi-temps. */
    changerCote() {
        this.chrono.sens = -(this.chrono.sens || 1);
        if (this.aPlacer) this.attendreZone(this.aPlacer);
        this.chronoChange();
    }

    basculerZones() {
        this.avecZones = !this.avecZones;
        try {
            localStorage.setItem(CLE_ZONES, this.avecZones ? 'avec' : 'sans');
        } catch (e) { /* le réglage vaut pour cette page */ }
        this.finirZone();
        this.dessinerActions();
    }

    /** Terrain horizontal, 6 bandes × 3 couloirs, zones enregistrées dans le sens de notre attaque. */
    dessinerTerrainAction() {
        const sens = (this.chrono.sens ?? 1) > 0 ? 1 : -1;
        const zones = [];
        for (let zx = 0; zx < 6; zx++) {
            for (let zy = 0; zy < 3; zy++) {
                const colonne = sens > 0 ? zx + 1 : 6 - zx;
                const ligne = sens > 0 ? zy + 1 : 3 - zy;
                zones.push(`<button type="button" class="zone" style="grid-row:${ligne};grid-column:${colonne}" data-zx="${zx}" data-zy="${zy}" data-action="live#choisirZoneAction" aria-label="Zone ${zx + 1}-${zy + 1}"></button>`);
            }
        }
        this.terrainActionTarget.querySelector('.zones').innerHTML = zones.join('');
        this.terrainActionTarget.dataset.sens = sens > 0 ? 'avant' : 'arriere';
        this.sensActionTarget.textContent = `Nous attaquons ${sens > 0 ? '→' : '←'}`;
    }

    annulerAction() {
        while (this.mesActions.length) {
            const uuid = this.mesActions.pop();
            const a = this.actions.get(uuid);
            if (!a || !this.aMoi(a)) continue;
            this.actions.delete(uuid);
            delete this.enAttente[uuid];
            this.supprimes.push(uuid);
            if (this.aPlacer === uuid) this.finirZone();
            this.garder();
            navigator.vibrate?.([20, 40, 20]);
            this.direAction(`↶ Annulé : ${a.minute}' · ${this.libelle(a)}`);
            this.afficher();
            this.envoyer();
            return;
        }
        this.dessinerActions();
    }

    direAction(texte, alerte = false) {
        this.messageAction = { texte, alerte };
        clearTimeout(this.finMessageAction);
        this.finMessageAction = setTimeout(() => { this.messageAction = null; this.dessinerActions(); }, 4000);
        this.dessinerActions();
    }

    dessinerActions(liste = [...this.actions.values()]) {
        if (!this.hasJoueursActionTarget) return;
        // Joueurs sur le terrain (quand on le sait), les autres sur demande.
        const { etat, exclus, connu } = this.terrain();
        const surLeTerrain = (j) => !connu || (etat.get(j.id) && !exclus.has(j.id));
        const visibles = this.cfg.joueurs.filter((j) => this.tousJoueurs || surLeTerrain(j) || j.id === this.joueurAction);
        const compte = (id) => liste.filter((a) => a.joueur === id && !EVENEMENTS.includes(a.type)).length;
        this.joueursActionTarget.innerHTML = visibles.map((j) => `<button type="button" class="live-tuile-joueur${j.id === this.joueurAction ? ' choisi' : ''}${surLeTerrain(j) ? '' : ' banc'}" data-joueur="${j.id}" data-action="live#choisirJoueurAction" aria-pressed="${j.id === this.joueurAction}">
            <span class="num">${j.numero ?? ''}</span><span class="qui"><b>${this.echapper(j.nom)}</b><small>${this.echapper(j.prenom)}</small></span>${j.poste ? `<span class="tuile-poste">${this.echapper(j.poste)}</span>` : ''}${compte(j.id) ? `<span class="nb-actions" title="Actions notées">${compte(j.id)}</span>` : ''}</button>`).join('')
            + (connu && visibles.length < this.cfg.joueurs.length || this.tousJoueurs
                ? `<button type="button" class="lien-bouton petit live-tous" data-action="live#voirTousJoueurs">${this.tousJoueurs ? 'Seulement ceux sur le terrain' : 'Voir aussi les remplaçants'}</button>` : '')
            || '<p class="discret">Aucun joueur : remplissez la feuille de match ou la convocation.</p>';
        if (!this.typesActionTarget.children.length) {
            this.typesActionTarget.innerHTML = Object.entries(this.cfg.types).filter(([c]) => !HORS_ACTIONS.includes(c))
                .map(([c, l]) => `<button type="button" class="tuile-action action-${c}" data-type="${c}" data-action="live#choisirAction">${this.echapper(l)}</button>`).join('');
        }
        // En attente de zone : le terrain à la place des boutons d'action.
        const enAttenteZone = !!this.aPlacer && this.actions.has(this.aPlacer);
        if (enAttenteZone) this.dessinerTerrainAction();
        this.zoneActionTarget.hidden = !enAttenteZone;
        this.choixActionsTarget.hidden = enAttenteZone;
        this.boutonZonesTarget.textContent = this.avecZones ? '📍 Zones : oui' : '📍 Zones : non';
        this.boutonZonesTarget.title = this.avecZones ? 'Après chaque action, le terrain s\'affiche pour toucher la zone (facultatif). Toucher pour ne plus le proposer.' : 'Toucher pour proposer la zone du terrain après chaque action.';
        const j = this.joueur(this.joueurAction);
        const m = this.messageAction;
        this.consigneActionTarget.className = `live-consigne${m ? (m.alerte ? ' alerte' : ' succes') : ''}`;
        this.consigneActionTarget.innerHTML = m ? this.echapper(m.texte)
            : (j ? `<b>${this.echapper(this.nom(j))}</b> : touchez son action (il reste choisi pour la suivante).` : 'Touchez le joueur, puis son action.');
        this.boutonAnnulerActionTarget.disabled = !this.mesActions.some((u) => this.actions.has(u));
        // Dernières actions de jeu (tous les appareils), la plus récente en haut.
        const dernieres = liste.filter((a) => !EVENEMENTS.includes(a.type) && a.type !== 'RP')
            .sort((a, b) => (b.periode - a.periode) || (b.minute - a.minute)).slice(0, 6);
        this.dernieresActionsTarget.innerHTML = dernieres.map((a) => `<li class="${this.enAttente[a.uuid] ? 'en-attente' : ''}"><span class="min">${a.minute}'</span>
            <span class="pastille-action action-${a.type}">${a.type}</span> <span>${this.echapper(this.libelle(a))}</span></li>`).join('')
            || '<li class="discret">Aucune action notée.</li>';
    }

    icone(type) {
        return {
            B: '⚽', BA: '⚽', RP: '⇄',
            CJ: '<i class="carte-jeu jaune"></i>', JA: '<i class="carte-jeu jaune"></i>', CR: '<i class="carte-jeu rouge"></i>', RA: '<i class="carte-jeu rouge"></i>', EX: '<i class="carte-jeu blanc"></i>',
        }[type] ?? '';
    }

    afficher() {
        const liste = [...this.actions.values()];
        this.scoreTarget.textContent = this.texteScore();
        this.dessinerEvenements(liste);
        this.dessinerStats(liste);
        this.dessinerCompo(liste);
        this.dessinerActions(liste);
        this.etatEnvoi();
    }

    /** Événements, du plus récent au plus ancien, avec le score après chaque but et les repères de période. */
    dessinerEvenements(liste) {
        const tries = liste.filter((a) => EVENEMENTS.includes(a.type)).sort((a, b) => (a.periode - b.periode) || (a.minute - b.minute));
        const score = [0, 0];
        const lignes = tries.map((a) => {
            if (a.type === 'B') score[0]++;
            if (a.type === 'BA') score[1]++;
            const sc = ['B', 'BA'].includes(a.type) ? ` <span class="sc">(${this.texteScore(score)})</span>` : '';
            const passeur = a.type === 'B' && a.details?.passeur ? ` <span class="discret petit">passe de ${this.echapper(this.nom(this.joueur(a.details.passeur)))}</span>` : '';
            const html = `<li class="${a.type === 'B' ? 'but-nous' : ''}${this.enAttente[a.uuid] ? ' en-attente' : ''}">
                <span class="min">${a.minute}'</span><span class="ico">${this.icone(a.type)}</span>
                <span class="quoi">${this.echapper(this.libelle(a))}${sc}${passeur}</span>
                ${this.aMoi(a) ? `<button type="button" class="lien-bouton danger" data-uuid="${a.uuid}" data-action="live#supprimer" title="Supprimer" aria-label="Supprimer">✕</button>` : ''}</li>`;
            return { a, html };
        });
        const c = this.chrono;
        const repere = (texte) => `<li class="repere">${texte}</li>`;
        const miTemps = repere(`Mi-temps · ${this.texteScore(this.compter(liste.filter((x) => x.periode === 1)))}`);
        // Dans l'ordre du match, puis retourné (le plus récent en haut).
        const html = [];
        if (this.commencee() || c.periode === 2) html.push(repere('Coup d\'envoi'));
        let miTempsPlacee = c.periode !== 2;
        lignes.forEach(({ a, html: h }) => {
            if (!miTempsPlacee && a.periode === 2) {
                html.push(miTemps);
                miTempsPlacee = true;
            }
            html.push(h);
        });
        if (!miTempsPlacee) html.push(miTemps);
        if (c.fini) html.push(repere(`🏁 Fin du match · ${this.texteScore()}`));
        this.evenementsTarget.innerHTML = html.reverse().join('') || '<li class="vide discret">Aucun événement pour l\'instant. Touchez « Coup d\'envoi » au début du match.</li>';
    }

    /** Barres « nous / eux » dans l'ordre du match (l'équipe qui reçoit à gauche). */
    dessinerStats(liste) {
        const n = (...types) => liste.filter((a) => types.includes(a.type)).length;
        const lignes = [
            ['Tirs', n('FC', 'FH', 'TC', 'TH'), n('AC', 'AH', 'ATC', 'ATH')],
            ['Tirs cadrés', n('FC', 'TC'), n('AC', 'ATC')],
            ['Corners', n('CO'), n('CA')],
            ['Cartons jaunes', n('CJ'), n('JA')],
            ['Cartons rouges', n('CR'), n('RA')],
        ].filter(([, a, b], i) => i < 3 || a + b > 0);
        this.statsTarget.innerHTML = lignes.map(([titre, nous, eux]) => {
            const [g, d] = this.ordre(nous, eux);
            const total = g + d;
            const part = total ? Math.round(100 * g / total) : 50;
            const nousAGauche = this.cfg.domicile;
            return `<div class="live-stat${total ? '' : ' vide'}"><span class="titre">${titre}</span>
                <span class="val">${g}</span>
                <span class="barre ${nousAGauche ? 'nous-gauche' : 'nous-droite'}" role="img" aria-label="${titre} : ${this.echapper(this.cfg.nous)} ${nous}, ${this.echapper(this.cfg.adversaire)} ${eux}"><i style="width:${part}%"></i></span>
                <span class="val">${d}</span></div>`;
        }).join('');
    }

    dessinerCompo(liste) {
        const { etat, exclus, connu } = this.terrain();
        const marques = (id) => {
            const buts = liste.filter((a) => a.type === 'B' && a.joueur === id).length;
            const j = liste.filter((a) => a.type === 'CJ' && a.joueur === id).length;
            const r = liste.some((a) => a.type === 'CR' && a.joueur === id);
            return `${'⚽'.repeat(buts)}${j ? '<i class="carte-jeu jaune"></i>'.repeat(j) : ''}${r ? '<i class="carte-jeu rouge"></i>' : ''}`;
        };
        const ligne = (j) => `<li${this.editionTitulaires ? ` class="modifiable${this.titulaires().includes(j.id) ? ' titulaire' : ''}" data-joueur="${j.id}" data-action="click->live#basculerTitulaire" role="button" tabindex="0"` : ''}>
            <span class="num">${j.numero ?? ''}</span><span class="qui">${this.echapper(j.prenom)} <b>${this.echapper(j.nom)}</b></span>
            <span class="marques">${marques(j.id)}${exclus.has(j.id) ? ' <span class="discret petit">exclu</span>' : ''}</span></li>`;
        if (this.editionTitulaires) {
            const t = this.titulaires();
            this.compoTarget.innerHTML = `<p class="discret petit">Touchez les joueurs sur le terrain au coup d'envoi : <b>${t.length} / ${this.cfg.maxTitulaires || 11}</b>.${this.messageTitulaires ? ` <span class="alerte-titulaires">${this.echapper(this.messageTitulaires)}</span>` : ''}</p>
                <ul class="live-compo">${this.cfg.joueurs.map(ligne).join('')}</ul>`;
            return;
        }
        if (!this.cfg.joueurs.length) {
            this.compoTarget.innerHTML = '<p class="discret">Aucun joueur : remplissez la feuille de match ou la convocation.</p>';
            return;
        }
        if (!connu) {
            this.compoTarget.innerHTML = `<p class="discret petit">Titulaires pas encore choisis : touchez « Choisir les titulaires ».</p><ul class="live-compo">${this.cfg.joueurs.map(ligne).join('')}</ul>`;
            return;
        }
        const sur = this.cfg.joueurs.filter((j) => etat.get(j.id) && !exclus.has(j.id));
        const banc = this.cfg.joueurs.filter((j) => !etat.get(j.id) || exclus.has(j.id));
        this.compoTarget.innerHTML = `<h3>Sur le terrain <span class="compteur">${sur.length}</span></h3>${this.terrainCompo(sur, liste, marques)}
            <h3>Remplaçants et sortis <span class="compteur">${banc.length}</span></h3><ul class="live-compo banc">${banc.map(ligne).join('') || '<li class="discret">Personne.</li>'}</ul>`;
    }

    /**
     * Joueurs sur le terrain dessinés à leur place, comme la composition de l'éducateur (feuille de match) :
     * le remplaçant entré prend la place du joueur sorti ; les autres vont aux places libres du système de jeu.
     */
    terrainCompo(sur, liste, marques) {
        const places = new Map(Object.entries(this.cfg.places ?? {}).map(([id, p]) => [Number(id), p]));
        liste.filter((a) => a.type === 'RP' && a.details?.entrant).sort((a, b) => (a.periode - b.periode) || (a.minute - b.minute))
            .forEach((a) => {
                const p = places.get(a.joueur);
                if (p) places.set(Number(a.details.entrant), p);
            });
        const prises = [];
        const pos = new Map();
        sur.forEach((j) => {
            const p = places.get(j.id);
            if (p && !prises.some((q) => Math.abs(q[0] - p[0]) < 1 && Math.abs(q[1] - p[1]) < 1)) {
                pos.set(j.id, p);
                prises.push(p);
            }
        });
        const libres = (this.cfg.placesSysteme ?? []).filter((p) => !prises.some((q) => Math.abs(q[0] - p[0]) < 8 && Math.abs(q[1] - p[1]) < 8));
        sur.filter((j) => !pos.has(j.id)).forEach((j, i) => pos.set(j.id, libres[i] ?? [10 + (i % 5) * 20, 50]));
        const pions = sur.map((j) => {
            const [x, y] = pos.get(j.id);
            return `<div class="pion" style="left: ${x}%; top: ${100 - y}%" title="${this.echapper(`${j.prenom} ${j.nom}`)}">
                <span class="pion-tete"><span class="pion-numero">${j.numero ?? ''}</span><span class="pion-buts">${marques(j.id)}</span></span>
                <span class="pion-nom">${this.echapper(j.nom)}</span></div>`;
        }).join('');

        return `<div class="terrain-compo live-terrain-compo">
            <svg class="terrain-lignes" viewBox="0 0 68 105" preserveAspectRatio="none" aria-hidden="true">
                <g fill="none" stroke="rgba(255,255,255,.85)" stroke-width=".35">
                    <rect x="1" y="1" width="66" height="103"/><line x1="1" y1="52.5" x2="67" y2="52.5"/><circle cx="34" cy="52.5" r="9.15"/>
                    <rect x="13.84" y="1" width="40.32" height="16.5"/><rect x="24.84" y="1" width="18.32" height="5.5"/>
                    <rect x="13.84" y="87.5" width="40.32" height="16.5"/><rect x="24.84" y="98.5" width="18.32" height="5.5"/>
                    <path d="M26.7,17.5 A9.15,9.15 0 0 0 41.3,17.5"/><path d="M26.7,87.5 A9.15,9.15 0 0 1 41.3,87.5"/>
                </g>
            </svg>${pions}</div>`;
    }

    editerTitulaires() {
        this.editionTitulaires = !this.editionTitulaires;
        this.messageTitulaires = null;
        this.boutonTitulairesTarget.textContent = this.editionTitulaires ? '✓ Terminé' : 'Choisir les titulaires';
        this.dessinerCompo([...this.actions.values()]);
    }

    basculerTitulaire(event) {
        const id = Number(event.currentTarget.dataset.joueur);
        const liste = new Set(this.titulaires());
        const max = this.cfg.maxTitulaires || 11;
        if (!liste.has(id) && liste.size >= max) {
            this.messageTitulaires = `Déjà ${max} titulaires : touchez d'abord un titulaire pour le mettre sur le banc.`;
        } else {
            this.messageTitulaires = null;
            if (liste.has(id)) liste.delete(id); else liste.add(id);
            this.cfg.titulaires = [...liste];
            this.titulairesAEnvoyer = this.cfg.titulaires;
            this.garder();
            this.envoyer();
        }
        this.dessinerCompo([...this.actions.values()]);
    }

    etatEnvoi(erreur) {
        const n = Object.keys(this.enAttente).length + this.supprimes.length;
        this.etatTarget.textContent = erreur || this.annonce || (n ? `${n} à envoyer…` : '✓ Tout est enregistré');
        this.etatTarget.classList.toggle('alerte', !!erreur || (n > 0 && !this.annonce));
        this.etatTarget.classList.toggle('succes', !!this.annonce && !erreur);
    }

    // ———————— Échange avec le serveur (le même que la saisie détaillée) ————————

    async envoyer() {
        if (this.envoiEnCours) return;
        const actions = Object.values(this.enAttente);
        const supprimes = [...this.supprimes];
        const chrono = this.chronoAEnvoyer ? { ...this.chrono } : null;
        const titulaires = this.titulairesAEnvoyer;
        this.envoiEnCours = true;
        try {
            const avant = Date.now();
            const reponse = await fetch(this.cfg.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ jeton: this.cfg.jeton, actions, supprimes, chrono, numeros: {}, ...(titulaires ? { titulaires } : {}), infos: {} }),
            });
            if (reponse.status === 404) {
                clearInterval(this.relance);
                this.etatEnvoi('Ce match a été supprimé : plus rien n\'est enregistré.');
                return;
            }
            if (!reponse.ok) {
                const corps = await reponse.json().catch(() => ({}));
                throw new Error(corps.erreur || `Erreur ${reponse.status}`);
            }
            const donnees = await reponse.json();
            this.decalage = donnees.maintenant - (avant + Date.now()) / 2;
            actions.forEach((a) => { if (this.enAttente[a.uuid] === a) delete this.enAttente[a.uuid]; });
            this.supprimes = this.supprimes.filter((u) => !supprimes.includes(u));
            if (chrono && this.chrono.maj === chrono.maj) this.chronoAEnvoyer = false;
            if (donnees.chrono && donnees.chrono.maj > (this.chrono.maj ?? 0) && !this.chronoAEnvoyer) {
                this.chrono = { ...this.chrono, ...donnees.chrono };
                if (this.chrono.enCours) this.garderEcranAllume();
                this.tic();
            }
            if (Array.isArray(donnees.joueurs)) this.cfg.joueurs = donnees.joueurs;
            if (titulaires && this.titulairesAEnvoyer === titulaires) this.titulairesAEnvoyer = null;
            if (!this.titulairesAEnvoyer) this.cfg.titulaires = donnees.titulaires ?? null;
            this.fusionner(donnees.actions ?? []);
            this.garder();
            this.afficher();
        } catch (e) {
            this.afficher();
            this.etatEnvoi(navigator.onLine === false || e instanceof TypeError ? 'Pas de réseau : gardé sur le téléphone' : `Non envoyé (${e.message}) : nouvel essai bientôt`);
        } finally {
            this.envoiEnCours = false;
        }
    }

    nouvelUuid() {
        if (window.crypto?.randomUUID) return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
            const r = (Math.random() * 16) | 0;
            return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
        });
    }

    echapper(texte) {
        return String(texte ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }
}
