import { Controller } from '@hotwired/stimulus';
import { confirmer } from '../confirmation.js';
import { preparerBut, dessinerBut, ecouterBut, arrondir } from '../terrain_but.js';
import { pointTouche, zoneDuPoint, rondsHtml, placeEcran } from '../terrain_point.js';

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
// Actions de jeu de l'adversaire (le reste est à nous).
const ADVERSES = ['AC', 'AH', 'ATC', 'ATH', 'CA', 'BA'];
const BUTS = ['B', 'BA'];
// Lignes de l'équipe, d'après l'abréviation du poste (G ; DEF, DC, LD… ; MIL, MDC, MOG… ; AT).
const LIGNES = [['gardien', 'Gardiens'], ['defense', 'Défenseurs'], ['milieu', 'Milieux'], ['attaque', 'Attaquants'], ['autre', 'Sans poste']];
function ligneDuPoste(poste) {
    if (!poste) return 'autre';
    if (poste === 'G') return 'gardien';
    if (poste === 'AT') return 'attaque';
    if (poste.startsWith('D') || poste.startsWith('L')) return 'defense';
    if (poste.startsWith('M')) return 'milieu';
    return 'autre';
}
// Minutes sans action, après le temps réglementaire, avant d'arrêter la période toute seule.
const ATTENTE_FIN = 10;
/** Notés par les gros boutons (But, Carton, Rempl.) : pas dans l'onglet « Actions ». */
const HORS_ACTIONS = ['B', 'CJ', 'CR', 'RP', 'EX'];
/** Réglage « avec ou sans zones », commun avec la saisie détaillée (gardé sur l'appareil). */
const CLE_ZONES = 'direct-zones';
/** Temps laissé pour toucher la zone après l'action (ensuite l'action reste sans zone). */
const DELAI_ZONE = 8000;

export default class extends Controller {
    static values = { config: Object };
    static targets = ['score', 'minute', 'periode', 'chrono', 'pause', 'etat', 'boutonPeriode', 'boutonFin', 'analyse','evenements', 'stats', 'statsJoueurs', 'filtresTerrain', 'avis',
        'compo', 'boutonTitulaires', 'dialogue',
        'boutonAnnulerAction', 'consigneAction', 'joueursAction', 'typesAction', 'dernieresActions',
        'boutonZones', 'zoneAction', 'sensAction', 'terrainAction', 'choixActions',
        'boutonTerrain', 'boutonSansZone', 'boutonFermerTerrain', 'legendeTerrain'];

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

    /** Minute à afficher : temps additionnel comme à la télévision (« 45+2' », « 90+3' »). */
    texteMinute(periode, minute) {
        const fin = (periode === 1 ? 1 : 2) * this.mi();
        return minute > fin ? `${fin}+${minute - fin}'` : `${minute}'`;
    }

    /**
     * Période oubliée : une fois le temps réglementaire passé, si aucune action n'est notée pendant ATTENTE_FIN
     * minutes, la période s'arrête toute seule (mi-temps, ou fin du match), à la minute de la dernière action
     * (ou à la fin du temps réglementaire).
     */
    verifierFinAuto() {
        const c = this.chrono;
        if (!c.enCours || c.fini) return;
        const mi = this.mi();
        const reglementaire = c.periode * mi;
        const minute = this.minuteCourante();
        if (minute - reglementaire < ATTENTE_FIN) return;
        const derniere = Math.max(reglementaire, ...[...this.actions.values()]
            .filter((a) => a.periode === c.periode && !a.uuid.startsWith('feuille')).map((a) => a.minute));
        if (minute - derniere < ATTENTE_FIN) return;
        const texte = this.texteMinute(c.periode, derniere);
        if (c.periode === 1) {
            this.chrono = { periode: 2, enCours: false, debut: null, cumul: 0, fini: false, sens: -c.sens, maj: c.maj };
            this.avis = `⏸ Mi-temps sifflée automatiquement (${texte}) : aucune action depuis ${ATTENTE_FIN} minutes après le temps réglementaire. Touchez « ▶ 2e période » à la reprise.`;
        } else {
            c.enCours = false;
            c.debut = null;
            c.cumul = (derniere - mi) * 60;
            c.fini = true;
            this.avis = `🏁 Fin du match sifflée automatiquement (${texte}) : aucune action depuis ${ATTENTE_FIN} minutes après le temps réglementaire.`;
        }
        this.verrou?.release?.();
        this.chronoChange();
    }

    /** Période commencée : chrono lancé au moins une fois. */
    commencee() {
        return this.chrono.enCours || this.chrono.cumul > 0;
    }

    tic() {
        this.verifierFinAuto();
        const c = this.chrono;
        const s = Math.floor(this.secondes());
        this.chronoTarget.textContent = `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;
        const avant = c.periode === 1 && !this.commencee();
        const pause = c.periode === 2 && !this.commencee();
        // Temps réglementaire de la période passé : minute et chrono en orange, qui clignotent.
        const depasse = !c.fini && !avant && !pause && s >= this.mi() * 60;
        this.minuteTarget.textContent = c.fini ? 'Terminé' : (avant ? 'À venir' : (pause ? 'Mi-temps' : this.texteMinute(c.periode, this.minuteCourante())));
        this.minuteTarget.classList.toggle('arrete', !c.enCours);
        this.minuteTarget.classList.toggle('depasse', depasse);
        this.chronoTarget.classList.toggle('depasse', depasse);
        this.periodeTarget.textContent = c.fini ? 'Match terminé' : (avant ? 'Avant le match' : (pause ? 'Mi-temps' : `${c.periode === 1 ? '1re période' : '2e période'}${depasse ? ' · temps additionnel' : ''}`));
        if (this.hasAvisTarget) {
            this.avisTarget.textContent = this.avis ?? '';
            this.avisTarget.hidden = !this.avis;
        }
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
        this.avis = null;
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
            this.avis = null;
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
        this.demanderCoteCorner(event.currentTarget.dataset.camp === 'nous' ? 'CO' : 'CA');
    }

    /**
     * Corner : petit terrain avec les deux drapeaux du but concerné (le but adverse pour nos corners, le nôtre pour
     * ceux de l'adversaire), dans le sens où l'on attaque ; toucher le drapeau d'où le corner est tiré.
     */
    demanderCoteCorner(type) {
        const sens = (this.chrono.sens ?? 1) > 0 ? 1 : -1;
        const x = type === 'CO' ? 105 : 0;
        const drapeau = (y) => {
            const { gauche, haut } = placeEcran([x, y], false, sens);
            return `<button type="button" class="drapeau-corner" style="left:${gauche}%;top:${haut}%" data-type="${type}" data-x="${x}" data-y="${y}" data-action="live#choisirCoteCorner" aria-label="Corner de ce côté">🚩</button>`;
        };
        this.dialogueTarget.innerHTML = `<form method="dialog" class="live-form">
            <h2 class="dialogue-titre">Corner ${this.echapper(type === 'CO' ? this.cfg.nous : this.cfg.adversaire)}</h2>
            <p class="discret petit">Touchez le drapeau d'où le corner est tiré (${type === 'CO' ? 'nous attaquons' : 'l\'adversaire attaque'} ${(type === 'CO') === (sens > 0) ? '→' : '←'}).</p>
            <div class="terrain-direct horizontal mini-corner">
                <span class="marque mediane"></span><span class="marque rond"></span>
                <span class="marque surface nous"></span><span class="marque surface eux"></span>
                ${drapeau(0)}${drapeau(68)}
            </div>
            <div class="dialogue-actions">
                <button type="button" class="bouton" data-type="${type}" data-action="live#choisirCoteCorner">Sans côté</button>
                <button value="annuler" class="bouton" formnovalidate>Annuler</button>
            </div>
        </form>`;
        this.dialogueTarget.showModal();
    }

    choisirCoteCorner(event) {
        const { type, x, y } = event.currentTarget.dataset;
        this.dialogueTarget.close();
        const point = x !== undefined ? [Number(x), Number(y)] : null;
        const action = { uuid: this.nouvelUuid(), type, joueur: null, ...(point ? { ...zoneDuPoint(point), details: { point } } : { details: HORS_CARTE }) };
        this.mesActions.push(action.uuid);
        navigator.vibrate?.(25);
        this.noter([action]);
        this.annoncer(`Corner ${type === 'CO' ? this.cfg.nous : this.cfg.adversaire} noté (${this.texteMinute(this.chrono.periode, this.minuteCourante())})${point ? '' : ', sans côté'}.`);
        this.direAction(`✓ ${this.texteMinute(this.chrono.periode, this.minuteCourante())} · ${this.libelle(action)}`);
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
        const tuile = (j) => `<label class="live-joueur ligne-${ligneDuPoste(j.poste)}"><input type="radio" name="${nom}" value="${j.id}"><span class="num">${j.numero ?? ''}</span><span class="qui">${this.echapper(j.prenom)} <b>${this.echapper(j.nom)}</b></span></label>`;
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

    voirToutesActions() {
        this.toutesActions = !this.toutesActions;
        this.dessinerActions();
    }

    /** Corriger une action de jeu notée sur cet appareil : joueur, action, minute (l'endroit sur le terrain est gardé). */
    modifierAction(event) {
        const a = this.actions.get(event.currentTarget.dataset.uuid);
        if (!a || !this.aMoi(a)) return;
        this.enCorrection = a.uuid;
        const adverse = a.type in this.cfg.adverses;
        const types = Object.entries(adverse ? this.cfg.adverses : this.cfg.types).filter(([c]) => !HORS_ACTIONS.includes(c) && !EVENEMENTS.includes(c));
        // Action de l'adversaire, ou de l'équipe sans joueur (corner) : pas de choix du joueur.
        this.dialogueTarget.innerHTML = `<form method="dialog" class="live-form">
            <h2 class="dialogue-titre">Modifier l'action</h2>
            ${adverse || !a.joueur ? `<p class="discret">${this.echapper(adverse ? this.cfg.adversaire : this.cfg.nous)}</p>` : `<label class="champ">Joueur
                <select name="joueur">${this.cfg.joueurs.map((j) => `<option value="${j.id}" ${j.id === a.joueur ? 'selected' : ''}>${j.numero ? `${j.numero} · ` : ''}${this.echapper(this.nom(j))}</option>`).join('')}</select></label>`}
            <label class="champ">Action
                <select name="type">${types.map(([c, l]) => `<option value="${c}" ${c === a.type ? 'selected' : ''}>${this.echapper(l)}</option>`).join('')}</select></label>
            <div class="live-minute-saisie">
                <span>Minute</span>
                <button type="button" class="bouton petit" data-action="live#minuteMoins" aria-label="Une minute de moins">−1</button>
                <input type="number" name="minute" min="0" max="130" inputmode="numeric" value="${a.minute}" aria-label="Minute">
                <button type="button" class="bouton petit" data-action="live#minutePlus" aria-label="Une minute de plus">+1</button>
            </div>
            <div class="dialogue-actions">
                <button type="button" class="bouton danger" data-uuid="${a.uuid}" data-action="live#supprimerDepuisFenetre">Supprimer</button>
                <button value="annuler" class="bouton" formnovalidate>Annuler</button>
                <button type="button" class="bouton principal" data-action="live#enregistrerModification">Enregistrer</button>
            </div>
        </form>`;
        this.dialogueTarget.showModal();
    }

    enregistrerModification() {
        const a = this.actions.get(this.enCorrection);
        const f = this.dialogueTarget.querySelector('form');
        if (!a || !f) return;
        const minute = Math.max(0, Math.min(130, Number(f.minute.value) || 0));
        const joueur = f.joueur ? Number(f.joueur.value) : a.joueur;
        const corrigee = { uuid: a.uuid, type: f.type.value, joueur, periode: a.periode, minute, zx: a.zx, zy: a.zy, ...(a.details ? { details: a.details } : {}) };
        this.dialogueTarget.close();
        this.noter([corrigee], minute);
        this.direAction(`✓ Modifiée : ${minute}' · ${this.libelle(corrigee)}`);
    }

    async supprimerDepuisFenetre(event) {
        this.dialogueTarget.close();
        await this.supprimer(event);
    }

    async supprimer(event) {
        const a = this.actions.get(event.currentTarget.dataset.uuid);
        if (!a || !this.aMoi(a) || !await confirmer(`${this.texteMinute(a.periode, a.minute)} · ${this.libelle(a)}`, { titre: 'Supprimer cet événement ?', bouton: 'Supprimer' })) return;
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
        this.allerOnglet(event.currentTarget.dataset.onglet);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    allerOnglet(nom) {
        this.element.dataset.onglet = nom;
        this.element.querySelectorAll('.live-onglets button').forEach((b) => b.classList.toggle('actif', b.dataset.onglet === nom));
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
        // Corner (pour nous ou l'adversaire) : on choisit seulement le drapeau d'où il est tiré.
        if (type === 'CO' || type === 'CA') {
            this.demanderCoteCorner(type);
            return;
        }
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
        this.direAction(`✓ ${this.texteMinute(this.chrono.periode, this.minuteCourante())} · ${this.libelle(action)}${adverse ? ` (${this.cfg.adversaire})` : ''}${this.avecZones ? ' · touchez la zone (facultatif)' : ''}`);
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

    /** Endroit touché sur le terrain : point précis (rond de couleur) et zone déduite, pour l'action qui vient d'être notée. */
    choisirZoneAction(event) {
        const a = this.actions.get(this.aPlacer);
        this.finirZone();
        if (!a) return;
        const sens = (this.chrono.sens ?? 1) > 0 ? 1 : -1;
        const point = pointTouche(event, this.terrainActionTarget, false, sens);
        navigator.vibrate?.(25);
        // Placée : plus « hors carte », dessinée sur la carte du bilan à cet endroit.
        const action = { uuid: a.uuid, type: a.type, joueur: a.joueur, periode: a.periode, minute: a.minute, ...zoneDuPoint(point), details: { point } };
        this.direAction(`✓ Endroit noté · ${this.libelle(action)}`);
        this.noter([action], a.minute);
    }

    /** Côté du terrain : commun à tous les appareils (comme sur la saisie détaillée), inversé à la mi-temps. */
    changerCote() {
        this.chrono.sens = -(this.chrono.sens || 1);
        if (this.aPlacer) this.attendreZone(this.aPlacer);
        this.chronoChange();
    }

    /** Ouvre ou ferme le terrain pour regarder toutes les actions placées du match (sans en noter). */
    basculerTerrain() {
        this.voirTerrain = !this.voirTerrain;
        if (this.voirTerrain) this.filtreTerrain = { qui: 'tous', type: null };
        this.dessinerActions();
    }

    basculerZones() {
        this.avecZones = !this.avecZones;
        try {
            localStorage.setItem(CLE_ZONES, this.avecZones ? 'avec' : 'sans');
        } catch (e) { /* le réglage vaut pour cette page */ }
        this.finirZone();
        this.dessinerActions();
    }

    /**
     * Terrain horizontal à toucher n'importe où (endroit précis de l'action, dans le sens de notre attaque) ;
     * les dernières actions placées y apparaissent en petits ronds de couleur.
     */
    dessinerTerrainAction(consultation = false) {
        const sens = (this.chrono.sens ?? 1) > 0 ? 1 : -1;
        // Pour placer une action : les 12 dernières ; pour regarder (« 🗺️ Terrain ») : toutes celles du match.
        // Les buts placés (départ du tir) y sont aussi, en petit ballon ; la frappe cadrée ajoutée avec le but
        // (« ffffffff… ») n'est pas dessinée en double.
        const toutes = [...this.actions.values()]
            .map((a) => (BUTS.includes(a.type) && a.details?.depart
                ? { ...a, details: { ...a.details, point: [Math.max(0, Math.min(105, a.details.depart[0])), a.details.depart[1]] } } : a))
            .filter((a) => a.details?.point && (BUTS.includes(a.type) || !EVENEMENTS.includes(a.type)) && !a.uuid.startsWith('ffffffff'))
            .sort((a, b) => (a.periode - b.periode) || (a.minute - b.minute))
            .map((a) => ({ ...a, libelle: `${this.libelle(a)}${a.joueur ? ` · ${this.nom(this.joueur(a.joueur))}` : ''}` }));
        // Pour regarder : filtre par équipe ou par joueur, puis par type d'action (légende).
        const f = this.filtreTerrain ?? { qui: 'tous', type: null };
        const duFiltre = (a) => f.qui === 'tous' || (f.qui === 'nous' && !ADVERSES.includes(a.type))
            || (f.qui === 'eux' && ADVERSES.includes(a.type)) || (typeof f.qui === 'number' && Number(a.joueur) === f.qui);
        const choisies = consultation ? toutes.filter(duFiltre) : toutes;
        const placees = consultation ? choisies.filter((a) => !f.type || a.type === f.type) : toutes.slice(-12);
        this.terrainActionTarget.querySelector('.zones').innerHTML = (consultation ? '' : `<button type="button" class="zone-libre" data-action="live#choisirZoneAction" aria-label="Toucher l'endroit de l'action"></button>`)
            // Tout le match (pour regarder) : une couleur par équipe ; une équipe ou un joueur : une couleur par action.
            + rondsHtml(placees, false, sens, (t) => this.echapper(t), consultation && f.qui === 'tous' ? (a) => (ADVERSES.includes(a.type) ? 'eux' : 'nous') : null);
        this.terrainActionTarget.classList.toggle('consultation', consultation);
        if (consultation) {
            this.dessinerFiltresTerrain(toutes, f);
            // Légende : chaque type d'action placée, sa couleur et son nombre ; la toucher ne montre que ce type.
            const parType = new Map();
            choisies.forEach((a) => parType.set(a.type, (parType.get(a.type) ?? 0) + 1));
            const nonPlacees = [...this.actions.values()].filter((a) => !a.details?.point && !a.details?.depart
                && (BUTS.includes(a.type) || !EVENEMENTS.includes(a.type))
                && !a.uuid.startsWith('ffffffff') && duFiltre(a));
            const butsSansEndroit = nonPlacees.filter((a) => BUTS.includes(a.type)).length;
            const sansEndroit = nonPlacees.length - butsSansEndroit;
            const notes = (sansEndroit ? `<span class="discret">+ ${sansEndroit} action${sansEndroit > 1 ? 's' : ''} sans endroit</span>` : '')
                + (butsSansEndroit ? `<span class="discret">+ ${butsSansEndroit} but${butsSansEndroit > 1 ? 's' : ''} sans endroit (pour le voir ici : en notant le but, déplacer le ballon sur le petit terrain)</span>` : '');
            if (f.qui === 'tous' && choisies.length) {
                // Tout le match : la légende, ce sont les deux équipes (les toucher montre leurs actions, couleur par action).
                const equipe = (camp, nom) => `<button type="button" class="legende-rond" data-qui="${camp}" data-action="live#filtrerQuiTerrain" title="Voir le détail de ses actions"><span class="rond-action camp-${camp}"></span>${this.echapper(nom)} <b>${choisies.filter((a) => ADVERSES.includes(a.type) === (camp === 'eux')).length}</b></button>`;
                this.legendeTerrainTarget.innerHTML = equipe('nous', this.cfg.nous) + equipe('eux', this.cfg.adversaire) + notes;
            } else this.legendeTerrainTarget.innerHTML = (parType.size
                ? [...parType].sort((a, b) => b[1] - a[1]).map(([type, n]) => `<button type="button" class="legende-rond${f.type === type ? ' actif' : ''}${f.type && f.type !== type ? ' estompe' : ''}" data-type="${type}" data-action="live#filtrerTypeTerrain" aria-pressed="${f.type === type}" title="${f.type === type ? 'Revoir toutes les actions' : 'Ne montrer que cette action'}">${BUTS.includes(type) ? `<span class="but-terrain legende ${type === 'B' ? 'nous' : 'eux'}">⚽</span>` : `<span class="rond-action action-${type}"></span>`}${this.echapper(this.cfg.types[type] ?? this.libelle({ type }))} <b>${n}</b></button>`).join('')
                : (toutes.length ? 'Aucune action placée sur le terrain pour ce choix.' : 'Aucune action placée sur le terrain pour l\'instant : après chaque action, touchez l\'endroit où elle a eu lieu.'))
                + notes;
        }
        this.filtresTerrainTarget.hidden = !consultation;
        this.terrainActionTarget.dataset.sens = sens > 0 ? 'avant' : 'arriere';
        this.sensActionTarget.textContent = `Nous attaquons ${sens > 0 ? '→' : '←'}`;
    }

    /** Boutons au-dessus du terrain : tout le match, notre équipe, l'adversaire, puis chaque joueur qui a des actions placées. */
    dessinerFiltresTerrain(toutes, f) {
        const nb = (test) => toutes.filter(test).length;
        const bouton = (qui, texte, n, classe = '') => `<button type="button" class="filtre-terrain${classe}${f.qui === qui ? ' actif' : ''}" data-qui="${qui}" data-action="live#filtrerQuiTerrain" aria-pressed="${f.qui === qui}">${texte} <b>${n}</b></button>`;
        const parJoueur = new Map();
        toutes.filter((a) => a.joueur && !ADVERSES.includes(a.type)).forEach((a) => parJoueur.set(Number(a.joueur), (parJoueur.get(Number(a.joueur)) ?? 0) + 1));
        const joueurs = [...parJoueur].map(([id, n]) => ({ j: this.joueur(id), id, n }))
            .sort((x, y) => (x.j?.numero ?? 99) - (y.j?.numero ?? 99));
        this.filtresTerrainTarget.innerHTML = `<div class="filtres-equipes">${bouton('tous', 'Tout le match', toutes.length)}${bouton('nous', this.echapper(this.cfg.nous), nb((a) => !ADVERSES.includes(a.type)), ' nous')}${bouton('eux', this.echapper(this.cfg.adversaire), nb((a) => ADVERSES.includes(a.type)), ' eux')}</div>`
            + (joueurs.length ? `<div class="filtres-joueurs">${joueurs.map(({ j, id, n }) => bouton(id, j ? `<span class="num">${j.numero ?? ''}</span>${this.echapper(this.nom(j))}` : 'Joueur', n)).join('')}</div>` : '');
    }

    filtrerQuiTerrain(event) {
        const q = event.currentTarget.dataset.qui;
        this.filtreTerrain = { qui: ['tous', 'nous', 'eux'].includes(q) ? q : Number(q), type: null };
        this.dessinerActions();
    }

    filtrerTypeTerrain(event) {
        const type = event.currentTarget.dataset.type;
        const f = this.filtreTerrain ?? { qui: 'tous', type: null };
        this.filtreTerrain = { ...f, type: f.type === type ? null : type };
        this.dessinerActions();
    }

    /** Depuis le tableau « Actions par joueur » : le terrain avec les seules actions de ce joueur (ou de l'équipe). */
    voirTerrainDe(event) {
        const q = event.currentTarget.dataset.qui;
        this.filtreTerrain = { qui: ['tous', 'nous', 'eux'].includes(q) ? q : Number(q), type: null };
        this.voirTerrain = true;
        this.allerOnglet('actions');
        this.dessinerActions();
        setTimeout(() => this.zoneActionTarget.scrollIntoView({ behavior: 'smooth', block: 'start' }), 50);
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
            this.direAction(`↶ Annulé : ${this.texteMinute(a.periode, a.minute)} · ${this.libelle(a)}`);
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
        const tuile = (j) => `<button type="button" class="live-tuile-joueur ligne-${ligneDuPoste(j.poste)}${j.id === this.joueurAction ? ' choisi' : ''}${surLeTerrain(j) ? '' : ' banc'}" data-joueur="${j.id}" data-action="live#choisirJoueurAction" aria-pressed="${j.id === this.joueurAction}">
            <span class="num">${j.numero ?? ''}</span><span class="qui"><b>${this.echapper(j.nom)}</b><small>${this.echapper(j.prenom)}</small></span>${j.poste ? `<span class="tuile-poste">${this.echapper(j.poste)}</span>` : ''}${compte(j.id) ? `<span class="nb-actions" title="Actions notées">${compte(j.id)}</span>` : ''}</button>`;
        // Rangés par ligne (gardiens, défenseurs, milieux, attaquants), chaque ligne avec sa couleur : on trouve
        // un joueur sans le connaître. Dans une ligne : par numéro, puis par nom.
        const groupes = LIGNES.map(([code, titre]) => [code, titre, visibles.filter((j) => ligneDuPoste(j.poste) === code)
            .sort((a, b) => (a.numero ?? 999) - (b.numero ?? 999) || a.nom.localeCompare(b.nom))]).filter(([, , js]) => js.length);
        const avecTitres = groupes.length > 1 || (groupes.length === 1 && groupes[0][0] !== 'autre');
        // Titulaires inconnus : tout le monde est affiché ; un bouton pour les choisir (onglet Compo).
        const aChoisir = !connu && this.cfg.joueurs.length > (this.cfg.maxTitulaires || 11)
            ? `<p class="live-sans-titulaires">Titulaires pas encore choisis : tous les joueurs sont affichés. <button type="button" class="bouton petit" data-action="live#choisirTitulaires">Choisir les titulaires</button></p>` : '';
        this.joueursActionTarget.innerHTML = aChoisir + (avecTitres
            ? groupes.map(([code, titre, js]) => `<p class="live-ligne-titre ligne-${code}">${titre}</p>${js.map(tuile).join('')}`).join('')
            : visibles.map(tuile).join(''))
            + (connu && visibles.length < this.cfg.joueurs.length || this.tousJoueurs
                ? `<button type="button" class="lien-bouton petit live-tous" data-action="live#voirTousJoueurs">${this.tousJoueurs ? 'Seulement ceux sur le terrain' : 'Voir aussi les remplaçants'}</button>` : '')
            || '<p class="discret">Aucun joueur : remplissez la feuille de match ou la convocation.</p>';
        if (!this.typesActionTarget.children.length) {
            this.typesActionTarget.innerHTML = Object.entries(this.cfg.types).filter(([c]) => !HORS_ACTIONS.includes(c))
                .map(([c, l]) => `<button type="button" class="tuile-action action-${c}" data-type="${c}" data-action="live#choisirAction">${this.echapper(l)}</button>`).join('');
        }
        // En attente de zone, ou terrain ouvert pour le regarder (« 🗺️ Terrain ») : le terrain à la place des actions.
        const enAttenteZone = !!this.aPlacer && this.actions.has(this.aPlacer);
        const consultation = !enAttenteZone && !!this.voirTerrain;
        if (enAttenteZone || consultation) this.dessinerTerrainAction(consultation);
        this.zoneActionTarget.hidden = !enAttenteZone && !consultation;
        this.choixActionsTarget.hidden = enAttenteZone || consultation;
        this.boutonSansZoneTarget.hidden = consultation;
        this.boutonFermerTerrainTarget.hidden = !consultation;
        this.legendeTerrainTarget.hidden = !consultation;
        this.boutonTerrainTarget.classList.toggle('actif', consultation);
        this.boutonZonesTarget.textContent = this.avecZones ? '📍 Zones : oui' : '📍 Zones : non';
        this.boutonZonesTarget.title = this.avecZones ? 'Après chaque action, le terrain s\'affiche pour toucher la zone (facultatif). Toucher pour ne plus le proposer.' : 'Toucher pour proposer la zone du terrain après chaque action.';
        const j = this.joueur(this.joueurAction);
        const m = this.messageAction;
        this.consigneActionTarget.className = `live-consigne${m ? (m.alerte ? ' alerte' : ' succes') : ''}`;
        this.consigneActionTarget.innerHTML = m ? this.echapper(m.texte)
            : (j ? `<b>${this.echapper(this.nom(j))}</b> : touchez son action (il reste choisi pour la suivante).` : 'Touchez le joueur, puis son action.');
        this.boutonAnnulerActionTarget.disabled = !this.mesActions.some((u) => this.actions.has(u));
        // Dernières actions de jeu (tous les appareils), la plus récente en haut.
        const jeu = liste.filter((a) => !EVENEMENTS.includes(a.type) && a.type !== 'RP' && !a.uuid.startsWith('ffffffff'))
            .sort((a, b) => (b.periode - a.periode) || (b.minute - a.minute));
        const dernieres = this.toutesActions ? jeu : jeu.slice(0, 6);
        // Ses propres actions se corrigent (joueur, action, minute) ou se suppriment.
        this.dernieresActionsTarget.innerHTML = dernieres.map((a) => `<li class="${this.enAttente[a.uuid] ? 'en-attente' : ''}"><span class="min">${this.texteMinute(a.periode, a.minute)}</span>
            <span class="pastille-action action-${a.type}">${a.type}</span> <span class="quoi">${this.echapper(this.libelle(a))}</span>
            ${this.aMoi(a) ? `<span class="live-corriger"><button type="button" class="lien-bouton petit" data-uuid="${a.uuid}" data-action="live#modifierAction">Modifier</button>
                <button type="button" class="lien-bouton petit danger" data-uuid="${a.uuid}" data-action="live#supprimer" title="Supprimer" aria-label="Supprimer">✕</button></span>` : ''}</li>`).join('')
            + (jeu.length > 6 ? `<li class="live-toutes"><button type="button" class="lien-bouton petit" data-action="live#voirToutesActions">${this.toutesActions ? 'Seulement les 6 dernières' : `Voir les ${jeu.length} actions du match`}</button></li>` : '')
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
                <span class="min">${this.texteMinute(a.periode, a.minute)}</span><span class="ico">${this.icone(a.type)}</span>
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
        // Actions de jeu (tout sauf buts, cartons et remplacements), de chaque équipe.
        const jeu = liste.filter((a) => !EVENEMENTS.includes(a.type));
        const adverses = jeu.filter((a) => ADVERSES.includes(a.type));
        const notres = jeu.filter((a) => !ADVERSES.includes(a.type));
        const lignes = [
            ['Actions', notres.length, adverses.length],
            ['Tirs',n('FC', 'FH', 'TC', 'TH'), n('AC', 'AH', 'ATC', 'ATH')],
            ['Tirs cadrés', n('FC', 'TC'), n('AC', 'ATC')],
            ['Corners', n('CO'), n('CA')],
            ['Cartons jaunes', n('CJ'), n('JA')],
            ['Cartons rouges', n('CR'), n('RA')],
        ].filter(([, a, b], i) => i < 4 || a + b > 0);
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
        this.dessinerStatsJoueurs(notres, adverses);
    }

    /**
     * Actions par joueur (réussies / tentées pour passes, duels, contrôles ; tirs cadrés / tirs), puis le total de
     * l'équipe (y compris les actions notées sans joueur) et celui de l'adversaire.
     */
    dessinerStatsJoueurs(notres, adverses) {
        if (!this.hasStatsJoueursTarget) return;
        const colonnes = [
            ['Passes', ['BP'], ['MP']],
            ['Duels', ['DG'], ['DP']],
            ['Contr.', ['BC'], ['MC']],
            ['Tirs', ['FC', 'TC'], ['FH', 'TH']],
        ];
        const cellules = (actions) => colonnes.map(([, bons, rates]) => {
            const b = actions.filter((a) => bons.includes(a.type)).length;
            const total = b + actions.filter((a) => rates.includes(a.type)).length;
            return `<td>${total ? `${b}/${total}` : '<span class="discret">–</span>'}</td>`;
        }).join('');
        // Toucher un nom : ses actions sur le terrain.
        const voir = (qui, texte) => `<button type="button" class="lien-terrain" data-qui="${qui}" data-action="live#voirTerrainDe" title="Voir ses actions sur le terrain">${texte} <span aria-hidden="true">🗺️</span></button>`;
        const parJoueur = new Map();
        notres.filter((a) => a.joueur).forEach((a) => parJoueur.set(a.joueur, [...(parJoueur.get(a.joueur) ?? []), a]));
        const lignes = [...parJoueur].sort((x, y) => y[1].length - x[1].length).map(([id, actions]) => {
            const j = this.joueur(id);
            return `<tr><th scope="row">${voir(id, j ? `<span class="num">${j.numero ?? ''}</span> ${this.echapper(this.nom(j))}` : 'Joueur')}</th>${cellules(actions)}<td class="total">${actions.length}</td></tr>`;
        }).join('');
        const tirsAdverses = adverses.filter((a) => ['AC', 'AH', 'ATC', 'ATH'].includes(a.type)).length;
        const cadresAdverses = adverses.filter((a) => ['AC', 'ATC'].includes(a.type)).length;
        if (!notres.length && !adverses.length) {
            this.statsJoueursTarget.innerHTML = '<p class="discret petit">Aucune action notée pour l\'instant (onglet « Actions »).</p>';
            return;
        }
        this.statsJoueursTarget.innerHTML = `<table class="live-table-actions">
            <thead><tr><th scope="col">Joueur</th>${colonnes.map(([t]) => `<th scope="col">${t}</th>`).join('')}<th scope="col">Total</th></tr></thead>
            <tbody>${lignes}</tbody>
            <tfoot>
                <tr><th scope="row">${voir('nous', this.echapper(this.cfg.nous))}</th>${cellules(notres)}<td class="total">${notres.length}</td></tr>
                <tr class="eux"><th scope="row">${voir('eux', this.echapper(this.cfg.adversaire))}</th><td colspan="3"></td>${tirsAdverses ? `<td>${cadresAdverses}/${tirsAdverses}</td>` : '<td><span class="discret">–</span></td>'}<td class="total">${adverses.length}</td></tr>
            </tfoot></table>`;
    }

    dessinerCompo(liste) {
        const { etat, exclus, connu } = this.terrain();
        const marques = (id) => {
            const buts = liste.filter((a) => a.type === 'B' && a.joueur === id).length;
            const j = liste.filter((a) => a.type === 'CJ' && a.joueur === id).length;
            const r = liste.some((a) => a.type === 'CR' && a.joueur === id);
            return `${'⚽'.repeat(buts)}${j ? '<i class="carte-jeu jaune"></i>'.repeat(j) : ''}${r ? '<i class="carte-jeu rouge"></i>' : ''}`;
        };
        const ligne = (j) => `<li class="ligne-${ligneDuPoste(j.poste)}${this.editionTitulaires ? ` modifiable${this.titulaires().includes(j.id) ? ' titulaire' : ''}" data-joueur="${j.id}" data-action="click->live#basculerTitulaire" role="button" tabindex="0"` : '"'}>
            <span class="num">${j.numero ?? ''}</span><span class="qui">${this.echapper(j.prenom)} <b>${this.echapper(j.nom)}</b></span>
            <span class="marques">${marques(j.id)}${exclus.has(j.id) ? ' <span class="discret petit">exclu</span>' : ''}</span>${j.poste ? `<span class="poste-compo" title="Poste">${this.echapper(j.poste)}</span>` : ''}</li>`;
        // Rangés par ligne, comme dans l'onglet Actions (gardiens, défenseurs, milieux, attaquants).
        const parLigne = (joueurs) => {
            const groupes = LIGNES.map(([code, titre]) => [code, titre, joueurs.filter((j) => ligneDuPoste(j.poste) === code)
                .sort((a, b) => (a.numero ?? 999) - (b.numero ?? 999) || a.nom.localeCompare(b.nom))]).filter(([, , js]) => js.length);
            if (groupes.length === 1 && groupes[0][0] === 'autre') return joueurs.map(ligne).join('');
            return groupes.map(([code, titre, js]) => `<li class="live-compo-titre ligne-${code}">${titre}</li>${js.map(ligne).join('')}`).join('');
        };
        if (this.editionTitulaires) {
            const t = this.titulaires();
            this.compoTarget.innerHTML = `<p class="discret petit">Touchez les joueurs sur le terrain au coup d'envoi : <b>${t.length} / ${this.cfg.maxTitulaires || 11}</b>.${this.messageTitulaires ? ` <span class="alerte-titulaires">${this.echapper(this.messageTitulaires)}</span>` : ''}</p>
                <ul class="live-compo">${parLigne(this.cfg.joueurs)}</ul>`;
            return;
        }
        if (!this.cfg.joueurs.length) {
            this.compoTarget.innerHTML = '<p class="discret">Aucun joueur : remplissez la feuille de match ou la convocation.</p>';
            return;
        }
        if (!connu) {
            this.compoTarget.innerHTML = `<p class="discret petit">Titulaires pas encore choisis : touchez « Choisir les titulaires ».</p><ul class="live-compo">${parLigne(this.cfg.joueurs)}</ul>`;
            return;
        }
        const sur = this.cfg.joueurs.filter((j) => etat.get(j.id) && !exclus.has(j.id));
        const banc = this.cfg.joueurs.filter((j) => !etat.get(j.id) || exclus.has(j.id));
        this.compoTarget.innerHTML = `<h3>Sur le terrain <span class="compteur">${sur.length}</span></h3>${this.terrainCompo(sur, liste, marques)}
            <h3>Remplaçants et sortis <span class="compteur">${banc.length}</span></h3><ul class="live-compo banc">${parLigne(banc) || '<li class="discret">Personne.</li>'}</ul>`;
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
        this.dessinerActions();
        // Choix terminé après être venu de l'onglet Actions : on y revient.
        if (!this.editionTitulaires && this.retourActions) {
            this.retourActions = false;
            this.allerOnglet('actions');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    }

    /** Depuis l'onglet Actions : onglet Compo, choix des titulaires ouvert. */
    choisirTitulaires() {
        this.allerOnglet('compo');
        if (!this.editionTitulaires) this.editerTitulaires();
        this.retourActions = true;
        window.scrollTo({ top: 0, behavior: 'smooth' });
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
