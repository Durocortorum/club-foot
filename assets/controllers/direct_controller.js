import { Controller } from '@hotwired/stimulus';
import { confirmer } from '../confirmation.js';
import { preparerBut, dessinerBut as dessinerTerrainBut, ecouterBut, arrondir } from '../terrain_but.js';

/*
 * Match en direct (table de statistiques) : joueur → action, la minute vient du chrono. L'action est enregistrée dès
 * la 2e touche ; la zone est facultative : touchée dans les secondes qui suivent, elle s'ajoute à l'action (sinon
 * l'action est « hors carte » : comptée, absente de la carte). Réglage « Sans zones » : terrain caché.
 * Le joueur reste choisi après son action (2e action du même joueur : une touche) ; relâché après une action adverse.
 *
 * Plusieurs tablettes au même match : chacune choisit ses statistiques (« Mes statistiques », gardé sur l'appareil),
 * le chrono est commun (le dernier changement l'emporte, heures alignées sur le serveur), et chaque tablette reçoit
 * toutes les quelques secondes les actions des autres (score et journal identiques partout). On ne corrige que ses actions.
 *
 * Sans réseau : tout est gardé sur l'appareil (localStorage) et envoyé dès que possible (même uuid : pas de doublon).
 * La page elle-même est gardée par le service worker (sw.js) : elle s'ouvre au stade même sans réseau.
 *
 * Zones enregistrées dans le sens de notre attaque : zx 0 (devant notre but) → 5 (devant le but adverse),
 * zy 0 (gauche) → 2 (droite). L'affichage tourne selon l'écran (horizontal ou vertical) et le côté de chaque période.
 */
const COLONNES = 6;
const COULOIRS = 3;
const CLE_MES_STATS = 'direct-mes-statistiques';
const CLE_ZONES = 'direct-zones';
/** Temps laissé pour toucher la zone après l'action (ensuite l'action reste sans zone). */
const DELAI_ZONE = 8000;
const TIRS_CADRES = ['FC', 'TC', 'AC', 'ATC'];

export default class extends Controller {
    static values = { config: Object };
    static targets = ['joueurs', 'types', 'terrain', 'journal', 'score', 'chrono', 'minute', 'periode', 'boutonChrono', 'etat', 'consigne',
        'meteo', 'sol', 'vent', 'temperature', 'sens', 'tout', 'plein', 'mesStats', 'resumeStats', 'horsLigne', 'boutonNumeros', 'bilan', 'boutonBilan',
        'proposeBut', 'texteProposeBut', 'dialogueBut', 'titreBut', 'terrainBut', 'gestesBut', 'champPasseur', 'passeurBut',
        'dialogueChangement', 'titreChangement', 'sortant', 'entrant', 'motifsChangement', 'minuteChangement', 'erreurChangement', 'aideChangement', 'boutonTitulaires', 'boutonFin',
        'boutonAnnulerDerniere', 'boutonZones', 'annonce', 'passerZone'];

    connect() {
        this.cfg = this.configValue;
        this.cle = `direct-${this.cfg.match}`;
        // Écart entre l'horloge de la tablette et celle du serveur, pour un chrono commun.
        this.decalage = this.cfg.maintenant ? this.cfg.maintenant - Date.now() : 0;
        const sauve = this.lire();
        const vide = { periode: 1, enCours: false, debut: null, cumul: 0, fini: false, sens: 1, maj: 0 };
        this.chrono = { ...vide, ...(sauve.chrono ?? {}) };
        if (sauve.sens && !sauve.chrono?.sens) this.chrono.sens = sauve.sens;
        if (this.cfg.chrono && this.cfg.chrono.maj > (this.chrono.maj ?? 0)) this.chrono = { ...vide, ...this.cfg.chrono };
        this.chronoAEnvoyer = !!sauve.chronoAEnvoyer;
        this.enAttente = sauve.enAttente ?? {};
        this.supprimes = sauve.supprimes ?? [];
        this.infos = sauve.infos ?? {};
        // Numéros de maillot changés sur cette tablette, pas encore envoyés : id du joueur => numéro (null : effacé).
        this.numerosAEnvoyer = sauve.numerosAEnvoyer ?? {};
        // Titulaires choisis sur cette tablette, pas encore envoyés (null : rien à envoyer).
        this.titulairesAEnvoyer = sauve.titulairesAEnvoyer ?? null;
        if (this.titulairesAEnvoyer) this.cfg.titulaires = this.titulairesAEnvoyer;
        this.appliquerNumeros();
        this.mesStats = this.lireMesStats();
        this.fusionner(this.cfg.actions);
        this.choix = this.choixVide();
        // Actions notées sur cette page, la plus récente à la fin (« Annuler la dernière action »).
        this.mesDernieres = [];
        this.avecZones = this.lireZones();
        this.appliquerZones();
        this.vertical = window.matchMedia('(max-width: 1100px)');
        this.surChangementEcran = () => this.dessinerTerrain();
        this.vertical.addEventListener('change', this.surChangementEcran);
        this.surReseau = () => this.envoyer();
        window.addEventListener('online', this.surReseau);
        this.minuteur = setInterval(() => this.tic(), 1000);
        // Toutes les 8 secondes : envoi de ce qui attend, réception des actions des autres tablettes et du chrono.
        this.relance = setInterval(() => this.envoyer(), 8000);
        this.pleinTarget.hidden = !(this.element.requestFullscreen || this.element.webkitRequestFullscreen);
        this.dessinerMesStats();
        this.dessinerJoueurs();
        this.dessinerTypes();
        this.dessinerTerrain();
        this.afficher();
        this.tic();
        this.envoyer();
        this.preparerHorsLigne();
    }

    disconnect() {
        clearInterval(this.minuteur);
        clearInterval(this.relance);
        clearTimeout(this.minuteurZone);
        clearTimeout(this.minuteurAnnonce);
        window.removeEventListener('online', this.surReseau);
        this.vertical.removeEventListener('change', this.surChangementEcran);
        this.verrou?.release?.();
    }

    // ———————— Sauvegarde sur l'appareil ————————

    lire() {
        try {
            return JSON.parse(localStorage.getItem(this.cle) || '{}');
        } catch (e) {
            return {};
        }
    }

    garder() {
        try {
            localStorage.setItem(this.cle, JSON.stringify({ chrono: this.chrono, chronoAEnvoyer: this.chronoAEnvoyer, enAttente: this.enAttente, supprimes: this.supprimes, infos: this.infos, numerosAEnvoyer: this.numerosAEnvoyer, titulairesAEnvoyer: this.titulairesAEnvoyer }));
        } catch (e) { /* stockage plein ou interdit : l'envoi au serveur reste tenté */ }
    }

    /** Actions connues : celles du serveur, plus celles de cette tablette pas encore envoyées, moins les supprimées. */
    fusionner(duServeur) {
        this.actions = new Map(duServeur.map((a) => [a.uuid, a]));
        Object.values(this.enAttente).forEach((a) => this.actions.set(a.uuid, { ...a, auteur: this.cfg.moi }));
        this.supprimes.forEach((u) => this.actions.delete(u));
    }

    /** La page et ses fichiers gardés par le service worker : au stade, elle s'ouvre même sans réseau. */
    async preparerHorsLigne() {
        if (!('serviceWorker' in navigator) || !('caches' in window)) return;
        try {
            await navigator.serviceWorker.register('/sw.js');
            const pret = await navigator.serviceWorker.ready;
            const fichiers = performance.getEntriesByType('resource').map((e) => e.name).filter((u) => new URL(u).pathname.startsWith('/assets/'));
            pret.active?.postMessage({ type: 'garder', urls: [window.location.pathname, ...fichiers] });
            this.horsLigneTarget.hidden = false;
        } catch (e) { /* pas de service worker (navigation privée…) : la page demande le réseau */ }
    }

    // ———————— Chrono (commun aux tablettes) ————————

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

    /** Minute du match : 1re minute = 1 ; la 2e période reprend à la mi-temps (46e pour 90 minutes). */
    minuteCourante() {
        return (this.chrono.periode - 1) * this.mi() + Math.floor(this.secondes() / 60) + 1;
    }

    tic() {
        const s = Math.floor(this.secondes());
        this.chronoTarget.textContent = `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;
        this.minuteTarget.textContent = `${this.minuteCourante()}'`;
        this.periodeTarget.textContent = this.chrono.fini ? 'Match terminé' : (this.chrono.periode === 1 ? '1re période' : '2e période');
        const c = this.chrono;
        this.boutonChronoTarget.textContent = c.fini ? '✓ Match terminé' : (c.enCours ? '⏸ Pause' : (c.cumul > 0 ? '▶ Reprendre' : (c.periode === 1 ? '▶ Coup d\'envoi' : '▶ 2e période')));
        this.boutonChronoTarget.classList.toggle('principal', !c.enCours && !c.fini);
        // Match terminé : plus de chrono à lancer ni de période à finir.
        this.boutonChronoTarget.disabled = !!c.fini;
        if (this.hasBoutonFinTarget) this.boutonFinTarget.disabled = !!c.fini;
        this.element.classList.toggle('chrono-arrete', !c.enCours);
    }

    /** Changement du chrono sur cette tablette : daté (heure du serveur) puis envoyé aux autres. */
    chronoChange() {
        this.chrono.maj = Math.max(this.maintenant(), (this.chrono.maj ?? 0) + 1);
        this.chronoAEnvoyer = true;
        this.garder();
        this.tic();
        this.envoyer();
    }

    basculerChrono() {
        const c = this.chrono;
        if (c.fini) return;
        if (c.enCours) {
            c.cumul = this.secondes();
            c.enCours = false;
            c.debut = null;
            this.verrou?.release?.();
        } else {
            c.enCours = true;
            c.debut = this.maintenant();
            this.garderEcranAllume();
        }
        this.chronoChange();
    }

    async finPeriode() {
        const c = this.chrono;
        if (c.fini) return;
        const score = [this.cfg.nous, this.scoreTarget.textContent, this.cfg.adversaire];
        const m = this.minuteCourante();
        const minute = `${m}${m === 1 ? 're' : 'e'} minute`;
        if (c.periode === 1) {
            if (!await confirmer('Score à la pause', {
                titre: '⏸ Mi-temps ?', bouton: 'Siffler la mi-temps', style: 'principal', classe: 'dialogue-periode', score,
                detail: `Fin de la 1re période à la ${minute}. Le chrono s'arrête ; « ▶ 2e période » le relance, et les équipes changent de côté.`,
            })) return;
            this.chrono = { periode: 2, enCours: false, debut: null, cumul: 0, fini: false, sens: -c.sens, maj: c.maj };
            this.dessinerTerrain();
        } else {
            if (!await confirmer('Score final', {
                titre: '🏁 Fin du match ?', bouton: 'Siffler la fin du match', style: 'principal', classe: 'dialogue-periode', score,
                detail: `Fin du match à la ${minute}. Pensez ensuite à « Reporter sur la feuille de match ».`,
            })) return;
            c.cumul = this.secondes();
            c.enCours = false;
            c.debut = null;
            c.fini = true;
        }
        this.chronoChange();
    }

    ajusterMoins() {
        this.ajuster(-60);
    }

    ajusterPlus() {
        this.ajuster(60);
    }

    ajuster(secondes) {
        const c = this.chrono;
        c.cumul = Math.max(0, this.secondes() + secondes);
        if (c.enCours) c.debut = this.maintenant();
        this.chronoChange();
    }

    /** Plein écran (tablette) : plus de barre d'adresse ni d'onglets ; le même bouton en sort. */
    pleinEcran() {
        const doc = document;
        if (doc.fullscreenElement || doc.webkitFullscreenElement) {
            (doc.exitFullscreen || doc.webkitExitFullscreen).call(doc);
        } else {
            const el = this.element;
            (el.requestFullscreen || el.webkitRequestFullscreen)?.call(el);
        }
    }

    async garderEcranAllume() {
        try {
            this.verrou = await navigator.wakeLock?.request('screen');
        } catch (e) { /* pas de verrou d'écran sur ce navigateur */ }
    }

    // ———————— Mes statistiques (ce que suit cette tablette) ————————

    /**
     * Choix gardé sur la tablette ; les groupes apparus depuis (ex. Têtes, Cartons) sont ajoutés, cochés.
     * Gardé sous la forme { suivis, connus } ; l'ancienne forme (simple liste) connaissait les 8 premiers groupes.
     */
    lireMesStats() {
        const tous = Object.keys(this.cfg.groupes);
        try {
            const sauve = JSON.parse(localStorage.getItem(CLE_MES_STATS) || 'null');
            const anciens = ['Passes', 'Duels', 'Contrôles', 'Frappes', 'Centres', 'Corners', 'Buts', 'Adversaire'];
            const [suivis, connus] = Array.isArray(sauve) ? [sauve, anciens] : [sauve?.suivis, sauve?.connus];
            if (Array.isArray(suivis) && suivis.length) {
                return tous.filter((g) => suivis.includes(g) || !(connus || []).includes(g));
            }
        } catch (e) { /* rien de gardé */ }
        return tous;
    }

    dessinerMesStats() {
        this.mesStatsTarget.innerHTML = Object.keys(this.cfg.groupes).map((g) => `<label class="choix-carte"><input type="checkbox" value="${this.echapper(g)}"
            ${this.mesStats.includes(g) ? 'checked' : ''} data-action="direct#changerMesStats"> ${this.echapper(g)}</label>`).join('');
        const toutes = this.mesStats.length === Object.keys(this.cfg.groupes).length;
        this.resumeStatsTarget.textContent = toutes ? 'toutes' : this.mesStats.join(', ');
    }

    changerMesStats() {
        const coches = [...this.mesStatsTarget.querySelectorAll('input:checked')].map((c) => c.value);
        this.mesStats = coches.length ? coches : Object.keys(this.cfg.groupes);
        try {
            localStorage.setItem(CLE_MES_STATS, JSON.stringify({ suivis: this.mesStats, connus: Object.keys(this.cfg.groupes) }));
        } catch (e) { /* tant pis : le choix vaut pour cette page */ }
        // Cases redessinées seulement si plus rien n'est coché (retour à « toutes ») ; sinon le résumé suffit.
        if (coches.length) {
            const toutes = this.mesStats.length === Object.keys(this.cfg.groupes).length;
            this.resumeStatsTarget.textContent = toutes ? 'toutes' : this.mesStats.join(', ');
        } else {
            this.dessinerMesStats();
        }
        this.dessinerTypes();
        this.dessinerJoueurs();
        if (this.choix.type && !this.typesSuivis().includes(this.choix.type)) this.annulerChoix();
        else this.afficher();
    }

    typesSuivis() {
        return this.mesStats.flatMap((g) => this.cfg.groupes[g] ?? []);
    }

    // ———————— Choix : joueur, action, zone ————————

    // ———————— Numéros de maillot (communs aux tablettes) ————————

    /** Numéros pas encore envoyés : ils priment sur ceux reçus du serveur. */
    appliquerNumeros() {
        Object.entries(this.numerosAEnvoyer).forEach(([id, n]) => {
            const j = this.joueur(Number(id));
            if (j) j.numero = n;
        });
    }

    editerNumeros() {
        this.editionNumeros = !this.editionNumeros;
        if (this.editionNumeros && this.editionTitulaires) {
            this.editionTitulaires = false;
            this.boutonTitulairesTarget.textContent = '⚽ Titulaires';
            this.element.classList.remove('edition-titulaires');
        }
        this.boutonNumerosTarget.textContent = this.editionNumeros ? '✓ Terminé' : '✎ Numéros';
        this.element.classList.toggle('edition-numeros', this.editionNumeros);
        this.dessinerJoueurs();
        this.afficher();
        if (this.editionNumeros) this.joueursTarget.querySelector('input')?.focus();
    }

    changerNumero(event) {
        const id = Number(event.currentTarget.dataset.joueur);
        const brut = event.currentTarget.value.trim();
        const n = brut === '' ? null : Math.max(1, Math.min(99, parseInt(brut, 10) || 0)) || null;
        event.currentTarget.value = n ?? '';
        this.numerosAEnvoyer[id] = n;
        this.appliquerNumeros();
        this.garder();
        this.envoyer();
    }

    // ———————— Titulaires (joueurs sur le terrain au coup d'envoi, communs aux tablettes) ————————

    /** Titulaires : ceux choisis sur une tablette, sinon les numéros 1 à 11 (règle de la feuille de match). */
    titulaires() {
        if (Array.isArray(this.cfg.titulaires)) return this.cfg.titulaires;
        return this.cfg.joueurs.filter((j) => j.numero >= 1 && j.numero <= 11).map((j) => j.id);
    }

    editerTitulaires() {
        this.editionTitulaires = !this.editionTitulaires;
        if (this.editionTitulaires && this.editionNumeros) {
            this.editionNumeros = false;
            this.boutonNumerosTarget.textContent = '✎ Numéros';
            this.element.classList.remove('edition-numeros');
        }
        this.boutonTitulairesTarget.textContent = this.editionTitulaires ? '✓ Terminé' : '⚽ Titulaires';
        this.element.classList.toggle('edition-titulaires', this.editionTitulaires);
        this.dessinerJoueurs();
        this.afficher();
    }

    basculerTitulaire(event) {
        const id = Number(event.currentTarget.dataset.joueurTitulaire);
        const liste = new Set(this.titulaires());
        const max = this.cfg.maxTitulaires || 11;
        if (!liste.has(id) && liste.size >= max) {
            // Terrain complet : il faut d'abord remettre un titulaire sur le banc.
            this.messageTitulaires = `Déjà ${max} titulaires (joueurs sur le terrain) : touchez d'abord un titulaire pour le mettre sur le banc.`;
            this.dessinerJoueurs();
            return;
        }
        this.messageTitulaires = null;
        if (liste.has(id)) liste.delete(id); else liste.add(id);
        this.cfg.titulaires = [...liste];
        this.titulairesAEnvoyer = this.cfg.titulaires;
        this.garder();
        this.dessinerJoueurs();
        this.envoyer();
    }

    dessinerJoueurs() {
        const suivis = this.typesSuivis();
        if (this.editionTitulaires) {
            // Choix des titulaires : toucher un joueur le met sur le terrain ou sur le banc.
            const titulaires = this.titulaires();
            this.joueursTarget.innerHTML = `<p class="consigne-titulaires">Touchez les joueurs sur le terrain au coup d'envoi : <b>${titulaires.length} / ${this.cfg.maxTitulaires || 11}</b> titulaires.${this.messageTitulaires ? `<br><span class="alerte-titulaires">${this.echapper(this.messageTitulaires)}</span>` : ''}</p>`
                + this.cfg.joueurs.map((j) => `<button type="button" class="tuile-joueur${titulaires.includes(j.id) ? ' titulaire' : ' banc'}" data-joueur-titulaire="${j.id}" data-action="direct#basculerTitulaire" aria-pressed="${titulaires.includes(j.id)}">
                <span class="num${j.numero ? '' : ' sans'}">${j.numero ?? '?'}</span><span class="nom">${this.echapper(j.nom)}</span><span class="prenom">${titulaires.includes(j.id) ? '⚽ Terrain' : 'Banc'}</span></button>`).join('');
            return;
        }
        if (this.editionNumeros) {
            // Saisie des numéros : une case par joueur (clavier numérique sur la tablette).
            this.joueursTarget.innerHTML = this.cfg.joueurs.map((j) => `<label class="tuile-joueur edition">
                <input type="number" inputmode="numeric" min="1" max="99" class="num-saisie" value="${j.numero ?? ''}" data-joueur="${j.id}" data-action="change->direct#changerNumero" aria-label="Numéro de ${this.echapper(j.prenom)} ${this.echapper(j.nom)}">
                <span class="nom">${this.echapper(j.nom)}</span><span class="prenom">${this.echapper(j.prenom)}</span></label>`).join('');
            return;
        }
        const avecJoueurs = suivis.some((t) => !this.estAdverse(t) && t !== 'RP');
        const tuiles = avecJoueurs ? this.cfg.joueurs.map((j) => `<button type="button" class="tuile-joueur" data-joueur="${j.id}" data-action="direct#choisirJoueur">
            <span class="num${j.numero ? '' : ' sans'}">${j.numero ?? '?'}</span><span class="nom">${this.echapper(j.nom)}</span><span class="prenom">${this.echapper(j.prenom)}</span></button>`) : [];
        // Actions de l'adversaire, toujours visibles sous les joueurs : l'action puis la zone (2 touches).
        const adverses = Object.entries(this.cfg.adverses).filter(([c]) => suivis.includes(c));
        if (adverses.length) {
            tuiles.push(`<div class="bloc-adverse"><span class="titre-adverse">Adversaire</span>
                <div class="actions-adverses">${adverses.map(([c, l]) => this.boutonAction(c, l, true)).join('')}</div></div>`);
        }
        // Changements : un petit cadre avec son bouton (fenêtre : qui sort, qui entre, minute, motif).
        if (suivis.includes('RP')) {
            tuiles.push(`<div class="bloc-changements"><span class="titre-changements">Changements</span>
                <button type="button" class="bouton petit" data-action="direct#nouveauChangement">⇄ Faire un changement</button>
                <span class="liste-changements-direct" data-changements></span></div>`);
        }
        this.joueursTarget.innerHTML = tuiles.join('');
        this.dessinerChangements();
    }

    /** Changements déjà faits, sous le bouton : « 60' ↓ 9 ↑ 14 ». */
    dessinerChangements() {
        const zone = this.joueursTarget.querySelector('[data-changements]');
        if (!zone) return;
        const nom = (id) => { const j = this.joueur(id); return j ? (j.numero ?? j.nom) : '?'; };
        zone.innerHTML = [...this.actions.values()].filter((a) => a.type === 'RP' || a.type === 'EX')
            .sort((a, b) => (a.periode - b.periode) || (a.minute - b.minute))
            .map((a) => `<span class="changement-fait" title="${this.echapper(this.libelle(a))}"><b>${a.minute}'</b> <span class="sort">↓ ${this.echapper(nom(a.joueur))}</span>${a.details?.entrant ? ` <span class="entre">↑ ${this.echapper(nom(a.details.entrant))}</span>` : ''}${a.type === 'EX' ? ` <span class="discret">carton blanc, retour ${a.minute + 10}'</span>` : ''}</span>`).join('');
    }

    // ———————— Changement : qui sort, qui entre ————————

    nouveauChangement() {
        this.ouvrirChangement({ uuid: this.nouvelUuid(), type: 'RP', joueur: null, periode: this.chrono.periode, minute: this.minuteCourante(), zx: 0, zy: 1, details: {} });
    }

    ouvrirChangement(c) {
        this.changementEnCours = c;
        // Qui est sur le terrain avant ce changement : les titulaires (numéros 1 à 11, comme sur la feuille de match),
        // puis les changements dans l'ordre (un joueur sorti peut revenir, comme chez les jeunes) ; un joueur exclu
        // (carton rouge) ne revient pas. Sans titulaire connu (pas de numéros), seuls les changements renseignent.
        const avantLui = (a) => a.uuid !== c.uuid && (a.periode < c.periode || (a.periode === c.periode && a.minute <= c.minute));
        const avant = [...this.actions.values()].filter((a) => ['RP', 'CR', 'EX'].includes(a.type) && a.joueur && avantLui(a))
            .sort((a, b) => (a.periode - b.periode) || (a.minute - b.minute));
        const titulaires = this.titulaires();
        this.terrainConnu = titulaires.length > 0;
        this.etatTerrain = new Map(this.terrainConnu ? this.cfg.joueurs.map((j) => [j.id, titulaires.includes(j.id)]) : []);
        this.exclus = new Set();
        avant.forEach((a) => {
            if (a.type === 'EX') {
                // Exclusion temporaire : hors du terrain pendant 10 minutes, ni remplacé ni remplaçant ; revient seul.
                if (a.periode === c.periode && c.minute < a.minute + 10) this.exclus.add(a.joueur);
                return;
            }
            this.etatTerrain.set(a.joueur, false);
            if (a.type === 'CR') this.exclus.add(a.joueur);
            else if (a.details?.entrant) this.etatTerrain.set(a.details.entrant, true);
        });
        // Qui sort : un joueur sur le terrain.
        const sortants = this.cfg.joueurs.filter((j) => j.id === c.joueur || (this.etatTerrain.get(j.id) !== false && !this.exclus.has(j.id)));
        this.sortantTarget.innerHTML = '<option value="">— Choisir —</option>' + this.optionsChangement(sortants, c.joueur);
        const motif = c.details?.motif || 'Choix';
        this.motifsChangementTarget.querySelectorAll('input').forEach((r) => { r.checked = r.value === motif; });
        // Changement déjà noté sans entrant : « Personne ».
        this.dessinerEntrants(this.actions.has(c.uuid) ? (c.details?.entrant ?? 'aucun') : null);
        this.minuteChangementTarget.value = c.minute;
        this.erreurChangementTarget.hidden = true;
        this.titreChangementTarget.textContent = this.actions.has(c.uuid) ? 'Corriger le changement' : 'Changement';
        this.dialogueChangementTarget.showModal();
    }

    optionsChangement(joueurs, choisi) {
        return joueurs.map((j) => `<option value="${j.id}" ${choisi === j.id ? 'selected' : ''}>${j.numero ? `${j.numero} ` : ''}${this.echapper(j.nom)} ${this.echapper(j.prenom)}</option>`).join('');
    }

    /**
     * Qui entre : un remplaçant (pas sur le terrain, pas exclu), jamais le joueur qui sort ; ou « Personne » : le joueur
     * sort sans être remplacé (plus de remplaçant, blessure…). Exclusion : personne n'entre.
     */
    dessinerEntrants(choisi) {
        const sortant = Number(this.sortantTarget.value);
        const exclusion = ['Exclusion', 'Temporaire'].includes(this.motifChangement());
        const possibles = exclusion ? [] : this.cfg.joueurs.filter((j) => j.id !== sortant && this.etatTerrain.get(j.id) !== true && !this.exclus.has(j.id));
        const personne = !possibles.length || choisi === 'aucun';
        this.entrantTarget.innerHTML = (possibles.length ? `<option value="" ${choisi ? '' : 'selected'}>— Choisir —</option>` : '')
            + `<option value="aucun" ${personne ? 'selected' : ''}>Personne (sans remplaçant)</option>`
            + this.optionsChangement(possibles, choisi);
        this.entrantTarget.disabled = exclusion;
        const aide = exclusion ? (this.motifChangement() === 'Temporaire'
            ? 'Carton blanc : le joueur sort 10 minutes sans être remplacé, puis revient sans changement.'
            : 'Carton rouge : le joueur est exclu, il n\'est pas remplacé.')
            : (!possibles.length ? 'Aucun remplaçant disponible : le joueur sort sans être remplacé.'
                : (!this.terrainConnu ? 'Astuce : choisissez les titulaires (bouton « ⚽ Titulaires ») : seuls les remplaçants seront alors proposés pour entrer.'
                    : 'Liste fausse ? Corrigez les joueurs sur le terrain au coup d\'envoi avec « ⚽ Titulaires ».'));
        this.aideChangementTarget.textContent = aide;
        this.aideChangementTarget.hidden = !aide;
    }

    motifChangement() {
        return this.motifsChangementTarget.querySelector('input:checked')?.value || 'Choix';
    }

    changerSortant() {
        const v = this.entrantTarget.value;
        this.dessinerEntrants(v === 'aucun' ? 'aucun' : (Number(v) || null));
        this.erreurChangementTarget.hidden = true;
    }

    annulerChangement() {
        this.changementEnCours = null;
        this.dialogueChangementTarget.close();
    }

    enregistrerChangement() {
        const c = this.changementEnCours;
        if (!c) return;
        const sortant = Number(this.sortantTarget.value);
        const motif = this.motifChangement();
        const choix = ['Exclusion', 'Temporaire'].includes(motif) ? 'aucun' : this.entrantTarget.value;
        const entrant = choix === 'aucun' ? null : Number(choix);
        if (!sortant || (choix !== 'aucun' && !entrant) || sortant === entrant) {
            this.erreurChangementTarget.textContent = !sortant ? 'Choisissez le joueur qui sort.'
                : (sortant === entrant ? 'Le joueur qui entre doit être différent de celui qui sort.' : 'Choisissez qui entre (ou « Personne »).');
            this.erreurChangementTarget.hidden = false;
            return;
        }
        const minute = Math.max(0, Math.min(130, parseInt(this.minuteChangementTarget.value, 10) || 0));
        // Exclusion : un carton rouge (au milieu du terrain) ; exclusion temporaire : « EX » ; sinon un changement.
        const type = { Exclusion: 'CR', Temporaire: 'EX' }[motif] ?? 'RP';
        const ancienne = this.actions.get(c.uuid);
        let uuid = c.uuid;
        if (ancienne && ancienne.type !== type) {
            // Correction qui change de nature (changement devenu exclusion…) : l'ancienne action est remplacée.
            this.actions.delete(c.uuid);
            delete this.enAttente[c.uuid];
            this.supprimes.push(c.uuid);
            uuid = this.nouvelUuid();
        }
        const action = type === 'RP'
            ? { uuid, type, joueur: sortant, periode: c.periode, minute, zx: 0, zy: 1, details: entrant ? { entrant, motif } : { motif } }
            : { uuid, type, joueur: sortant, periode: c.periode, minute, zx: type === 'CR' ? 2 : 0, zy: 1 };
        this.noter(action);
        this.changementEnCours = null;
        this.dialogueChangementTarget.close();
        navigator.vibrate?.(25);
        this.afficher();
        this.envoyer();
    }

    /** Minute du changement : −1 / +1 dans la fenêtre. */
    minuteChangementMoins() {
        this.minuteChangementTarget.value = Math.max(0, (parseInt(this.minuteChangementTarget.value, 10) || 0) - 1);
    }

    minuteChangementPlus() {
        this.minuteChangementTarget.value = Math.min(130, (parseInt(this.minuteChangementTarget.value, 10) || 0) + 1);
    }

    /** Bouton d'action ; « adverse » abrégé (place comptée sur la tablette), nom complet dans le journal et le bilan. */
    boutonAction(code, libelle, adverse) {
        return `<button type="button" class="tuile-action action-${code}${adverse ? ' adverse' : ''}" data-type="${code}" data-action="direct#choisirType" title="${this.echapper(libelle)}"><b>${code}</b> ${this.echapper(libelle.replace(/ adverse\b/, ' adv.'))}</button>`;
    }

    dessinerTypes() {
        const suivis = this.typesSuivis();
        this.typesTarget.innerHTML = Object.entries(this.cfg.types).filter(([c]) => suivis.includes(c)).map(([c, l]) => this.boutonAction(c, l, false)).join('');
    }

    choixVide() {
        return { joueur: undefined, type: null, modifie: null, collant: false };
    }

    /** Joueur touché : avec une action déjà choisie (ou à corriger), l'action est enregistrée aussitôt. */
    choisirJoueur(event) {
        const id = Number(event.currentTarget.dataset.joueur) || null;
        if (!id) return;
        this.finirZone();
        // Le joueur gardé de l'action précédente, touché à nouveau : relâché.
        if (this.choix.collant && this.choix.joueur === id && !this.choix.type) {
            this.choix = this.choixVide();
            this.etape('joueur');
            return;
        }
        this.choix.joueur = id;
        this.choix.collant = false;
        if (this.choix.type && !this.estAdverse(this.choix.type)) {
            this.enregistrerChoix();
            return;
        }
        if (!this.choix.modifie) this.choix.type = null;
        this.etape('action');
    }

    /** Action touchée : enregistrée aussitôt si le joueur est connu (action adverse, corner : pas de joueur à choisir). */
    choisirType(event) {
        const type = event.currentTarget.dataset.type;
        this.finirZone();
        this.choix.type = type;
        if (this.estAdverse(type) || type === 'CO' || this.choix.joueur) {
            this.enregistrerChoix();
            return;
        }
        this.etape('joueur');
    }

    enregistrerChoix() {
        const { type, modifie } = this.choix;
        const ancienne = modifie ? this.actions.get(modifie) : null;
        const adverse = this.estAdverse(type);
        // Corner : au joueur touché pour lui, pas à celui gardé de l'action précédente.
        const joueur = adverse || (type === 'CO' && this.choix.collant) ? null : (this.choix.joueur || null);
        // Après l'action : le joueur du club reste choisi, sauf après une action adverse (le ballon a changé de camp).
        const garde = adverse ? undefined : (this.choix.joueur || undefined);
        this.choix = garde ? { joueur: garde, type: null, modifie: null, collant: true } : this.choixVide();
        const base = {
            uuid: ancienne?.uuid ?? this.nouvelUuid(),
            joueur,
            type,
            periode: ancienne?.periode ?? this.chrono.periode,
            minute: ancienne?.minute ?? this.minuteCourante(),
        };
        // But (ou but adverse) : la fenêtre « But ! » pour placer le ballon et la direction, avant d'enregistrer.
        if (type === 'B' || type === 'BA') {
            this.etape(this.etapeSuivante());
            this.ouvrirBut({ ...base, zx: ancienne?.zx ?? (type === 'B' ? 4 : 1), zy: ancienne?.zy ?? 1, details: ancienne?.details });
            return;
        }
        // Correction d'une action déjà placée : elle garde sa zone ; sinon « hors carte » en attendant la zone.
        const placee = ancienne && !ancienne.details?.horsCarte && !['B', 'BA'].includes(ancienne.type);
        const action = placee ? { ...base, zx: ancienne.zx, zy: ancienne.zy } : { ...base, zx: 2, zy: 1, details: { horsCarte: true } };
        this.noter(action);
        navigator.vibrate?.(25);
        // Frappe ou tête cadrée (de nous ou de l'adversaire) : proposer « C'était un but ! ».
        this.dernierTir = TIRS_CADRES.includes(type) ? action : null;
        if (this.avecZones && !placee) this.attendreZone(action.uuid);
        this.annoncer(`✓ ${action.minute}' · ${this.libelle(action)}`);
        this.etape(this.etapeSuivante());
        this.envoyer();
    }

    /** Étape après une action : la zone (téléphone, en attente de zone), sinon l'action pour le joueur gardé. */
    etapeSuivante() {
        const telephone = window.matchMedia('(max-width: 700px)').matches;
        if (telephone && this.aPlacer) return 'zone';
        return this.choix.joueur && !telephone ? 'action' : 'joueur';
    }

    /** Action enregistrée sur cette tablette (envoyée au prochain échange). */
    noter(action) {
        this.actions.set(action.uuid, { ...action, auteur: this.cfg.moi });
        this.enAttente[action.uuid] = action;
        this.mesDernieres = this.mesDernieres.filter((u) => u !== action.uuid).concat(action.uuid);
        this.garder();
    }

    /** Zone facultative : quelques secondes pour la toucher, ensuite l'action reste sans zone. */
    attendreZone(uuid) {
        clearTimeout(this.minuteurZone);
        this.aPlacer = uuid;
        this.minuteurZone = setTimeout(() => {
            this.aPlacer = null;
            if (this.element.dataset.etape === 'zone') this.etape(this.etapeSuivante());
            else this.afficher();
        }, DELAI_ZONE);
    }

    finirZone() {
        clearTimeout(this.minuteurZone);
        this.aPlacer = null;
    }

    passerZone() {
        this.finirZone();
        this.etape(this.etapeSuivante());
    }

    /** Zone touchée : ajoutée à l'action qui vient d'être notée (ou à celle qu'on corrige). */
    choisirZone(event) {
        const { zx, zy } = event.currentTarget.dataset;
        const enAttente = this.aPlacer;
        const a = this.actions.get(enAttente ?? (this.choix.modifie && this.choix.type ? this.choix.modifie : ''));
        if (!a || ['B', 'BA', 'RP', 'EX'].includes(a.type)) {
            this.consigneTarget.textContent = 'Touchez d\'abord le joueur et l\'action ; la zone se touche juste après (facultatif).';
            this.consigneTarget.classList.add('alerte');
            return;
        }
        const action = { uuid: a.uuid, joueur: a.joueur, type: a.type, periode: a.periode, minute: a.minute, zx: Number(zx), zy: Number(zy) };
        this.noter(action);
        if (this.dernierTir?.uuid === action.uuid) this.dernierTir = action;
        navigator.vibrate?.(25);
        const zone = event.currentTarget;
        zone.classList.add('touchee');
        setTimeout(() => zone.classList.remove('touchee'), 350);
        this.finirZone();
        if (!enAttente) this.choix = this.choixVide();
        this.annoncer(`✓ Zone notée · ${this.libelle(action)}`);
        this.etape(this.etapeSuivante());
        this.envoyer();
    }

    // ———————— Saisie rapide : annuler la dernière action, avec ou sans zones ————————

    async annulerDerniere() {
        while (this.mesDernieres.length) {
            const uuid = this.mesDernieres.pop();
            const a = this.actions.get(uuid);
            if (!a || !this.aMoi(a)) continue;
            this.actions.delete(uuid);
            delete this.enAttente[uuid];
            this.supprimes.push(uuid);
            if (this.dernierTir?.uuid === uuid) this.dernierTir = null;
            if (this.aPlacer === uuid) this.finirZone();
            if (this.choix.modifie === uuid) this.choix = this.choixVide();
            this.garder();
            navigator.vibrate?.([20, 40, 20]);
            this.annoncer(`↶ Annulé : ${a.minute}' · ${this.libelle(a)}`);
            this.etape(this.etapeSuivante());
            this.envoyer();
            return;
        }
        this.afficher();
    }

    annoncer(texte) {
        clearTimeout(this.minuteurAnnonce);
        this.annonceTarget.textContent = texte;
        this.minuteurAnnonce = setTimeout(() => { this.annonceTarget.textContent = ''; }, 5000);
    }

    lireZones() {
        try {
            return localStorage.getItem(CLE_ZONES) !== 'sans';
        } catch (e) {
            return true;
        }
    }

    basculerZones() {
        this.avecZones = !this.avecZones;
        try {
            localStorage.setItem(CLE_ZONES, this.avecZones ? 'avec' : 'sans');
        } catch (e) { /* le réglage vaut pour cette page */ }
        this.finirZone();
        this.appliquerZones();
        this.etape(this.element.dataset.etape === 'zone' ? this.etapeSuivante() : (this.element.dataset.etape || 'joueur'));
    }

    appliquerZones() {
        this.element.classList.toggle('sans-zones', !this.avecZones);
        this.boutonZonesTarget.textContent = this.avecZones ? '📍 Avec zones' : '📍 Sans zones';
        this.boutonZonesTarget.setAttribute('aria-pressed', String(this.avecZones));
        this.boutonZonesTarget.title = this.avecZones
            ? 'Zones facultatives : touchées juste après l\'action. Toucher pour cacher le terrain.'
            : 'Terrain caché, gros boutons : les actions sont notées sans zone. Toucher pour revoir le terrain.';
    }

    // ———————— But : ballon, direction dans le but, façon de marquer ————————

    /** « C'était un but ! » après une frappe ou une tête cadrée : même joueur, minute et zone. */
    butDepuisTir() {
        const t = this.dernierTir;
        if (!t) return;
        this.dernierTir = null;
        const adverse = this.estAdverse(t.type);
        this.ouvrirBut({
            uuid: this.nouvelUuid(), type: adverse ? 'BA' : 'B', joueur: adverse ? null : t.joueur,
            periode: t.periode, minute: t.minute,
            // Tir noté sans zone : ballon proposé devant le but.
            zx: t.details?.horsCarte ? (adverse ? 1 : 4) : t.zx, zy: t.details?.horsCarte ? 1 : t.zy, details: { geste: ['TC', 'ATC'].includes(t.type) ? 'tete' : 'tir' },
        });
        this.afficher();
    }

    /** Fenêtre « But ! » : nos buts vers le but adverse (à droite), ceux de l'adversaire vers le nôtre (à gauche). */
    ouvrirBut(but) {
        const droite = but.type === 'B';
        const d = but.details || {};
        // Départ par défaut : le milieu de la zone notée.
        this.butEnCours = { ...but, ...preparerBut(droite, d.depart ?? [(but.zx + 0.5) * 17.5, (but.zy + 0.5) * 68 / 3], d.arrivee) };
        const j = this.joueur(but.joueur);
        this.titreButTarget.textContent = droite ? `But !${j ? ` ${j.numero ? `${j.numero} ` : ''}${j.nom}` : ''} · ${but.minute}'` : `But adverse · ${but.minute}'`;
        const geste = d.geste || 'tir';
        this.gestesButTarget.querySelectorAll('input').forEach((r) => { r.checked = r.value === geste; });
        // Passeur décisif : les joueurs du match sauf le buteur (pas pour un but adverse).
        this.champPasseurTarget.hidden = !droite;
        this.passeurButTarget.innerHTML = '<option value="">Aucun</option>' + this.cfg.joueurs.filter((x) => x.id !== but.joueur)
            .map((x) => `<option value="${x.id}" ${d.passeur === x.id ? 'selected' : ''}>${x.numero ? `${x.numero} ` : ''}${this.echapper(x.nom)} ${this.echapper(x.prenom)}</option>`).join('');
        this.dessinerBut();
        this.dialogueButTarget.showModal();
    }

    annulerBut() {
        this.butEnCours = null;
        this.dialogueButTarget.close();
    }

    enregistrerBut() {
        const b = this.butEnCours;
        if (!b) return;
        const geste = this.gestesButTarget.querySelector('input:checked')?.value || 'tir';
        // Zone du but : celle d'où part le ballon placé dans la fenêtre (en mètres, dans le sens de notre attaque).
        const borne = (v, max) => Math.max(0, Math.min(max - 1, Math.floor(v)));
        const zx = b.depart ? borne(b.depart[0] / 17.5, COLONNES) : b.zx;
        const zy = b.depart ? borne(b.depart[1] / (68 / COULOIRS), COULOIRS) : b.zy;
        const action = {
            uuid: b.uuid, type: b.type, periode: b.periode, minute: b.minute, zx, zy,
            // Contre son camp d'un adversaire : pas de buteur chez nous.
            joueur: b.type === 'B' && geste !== 'csc' ? b.joueur : null,
            details: { depart: arrondir(b.depart), arrivee: arrondir(b.arrivee), geste },
        };
        const passeur = Number(this.passeurButTarget.value);
        if (b.type === 'B' && passeur) action.details.passeur = passeur;
        this.noter(action);
        this.butEnCours = null;
        this.dialogueButTarget.close();
        navigator.vibrate?.([30, 60, 30]);
        this.annoncer(`⚽ ${action.minute}' · ${this.libelle(action)}`);
        this.afficher();
        this.envoyer();
    }

    /** Demi-terrain vu de dessus avec le but, le ballon et la flèche à tirer (terrain_but.js). */
    dessinerBut() {
        if (!this.ecouteBut) {
            this.ecouteBut = true;
            ecouterBut(this.terrainButTarget, () => this.butEnCours);
        }
        dessinerTerrainBut(this.terrainButTarget, this.butEnCours);
    }

    annulerChoix() {
        this.finirZone();
        this.choix = this.choixVide();
        this.etape('joueur');
    }

    etape(nom) {
        this.element.dataset.etape = nom;
        this.afficher();
        // Téléphone : une étape à la fois ; on la remonte juste sous l'en-tête (chrono, score) collé en haut.
        if (window.matchMedia('(max-width: 700px)').matches) {
            const entete = this.element.querySelector('.direct-entete');
            const haut = this.consigneTarget.getBoundingClientRect().top + window.scrollY - (entete?.offsetHeight ?? 0) - 6;
            window.scrollTo({ top: Math.max(0, haut), behavior: 'smooth' });
        }
    }

    /** Fil « joueur ▸ action ▸ zone » : revenir à une étape (sur téléphone, une étape à la fois). */
    allerEtape(event) {
        if (event.currentTarget.dataset.etape !== 'zone') this.finirZone();
        this.etape(event.currentTarget.dataset.etape);
    }

    // ———————— Journal : corriger, supprimer (ses propres actions) ————————

    aMoi(a) {
        return !a.auteur || a.auteur === this.cfg.moi;
    }

    modifier(event) {
        const a = this.actions.get(event.currentTarget.dataset.uuid);
        if (!a || !this.aMoi(a)) return;
        // Un but se corrige dans sa fenêtre (ballon, direction, façon de marquer).
        if (a.type === 'B' || a.type === 'BA') {
            this.ouvrirBut({ ...a });
            return;
        }
        if (a.type === 'RP' || a.type === 'EX') {
            this.ouvrirChangement(a.type === 'EX' ? { ...a, details: { motif: 'Temporaire' } } : { ...a });
            return;
        }
        // Correction : toucher une autre action, un autre joueur ou une zone l'enregistre aussitôt.
        this.finirZone();
        this.choix = { joueur: this.estAdverse(a.type) ? null : a.joueur, type: a.type, modifie: a.uuid, collant: false };
        this.etape('action');
        this.element.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    async supprimer(event) {
        const uuid = event.currentTarget.dataset.uuid;
        const a = this.actions.get(uuid);
        if (!a || !this.aMoi(a) || !await confirmer(`${a.minute}' · ${this.libelle(a)}`, { titre: 'Supprimer cette action ?', bouton: 'Supprimer' })) return;
        this.actions.delete(uuid);
        delete this.enAttente[uuid];
        this.supprimes.push(uuid);
        if (this.choix.modifie === uuid) this.choix = this.choixVide();
        if (this.aPlacer === uuid) this.finirZone();
        this.garder();
        this.afficher();
        this.envoyer();
    }

    /** Bilan du match sous la saisie (cadre Turbo), rechargé à chaque ouverture pour compter les dernières actions. */
    /**
     * Bilan ouvert : rechargé (même filtre) quand les actions du serveur ont changé — notées ici, sur une autre
     * tablette, ou venues de la feuille de match.
     */
    rafraichirBilan(actions) {
        const empreinte = JSON.stringify(actions.map((a) => [a.uuid, a.type, a.joueur, a.minute, a.zx, a.zy, a.details]));
        const change = this.empreinteBilan !== undefined && empreinte !== this.empreinteBilan;
        this.empreinteBilan = empreinte;
        if (!change || this.bilanTarget.hidden) return;
        const cadre = this.bilanTarget.querySelector('turbo-frame');
        if (typeof cadre?.reload === 'function' && cadre.src) cadre.reload();
    }

    basculerBilan() {
        const zone = this.bilanTarget;
        if (!zone.hidden) {
            zone.hidden = true;
            this.boutonBilanTarget.textContent = 'Voir le bilan du match';
            return;
        }
        zone.hidden = false;
        this.boutonBilanTarget.textContent = 'Masquer le bilan';
        zone.querySelector('turbo-frame').src = `${zone.dataset.url}?vu=${Date.now()}`;
        zone.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    basculerJournal() {
        this.journalComplet = !this.journalComplet;
        this.afficher();
    }

    changerCote() {
        this.chrono.sens = -this.chrono.sens;
        this.dessinerTerrain();
        this.chronoChange();
    }

    changerInfos() {
        this.infos = { meteo: this.meteoTarget.value, terrain: this.solTarget.value, vent: this.ventTarget.value, temperature: this.temperatureTarget.value };
        this.infosAEnvoyer = true;
        this.garder();
        this.envoyer();
    }

    // ———————— Affichage ————————

    dessinerTerrain() {
        const vertical = this.vertical.matches;
        const sens = this.chrono.sens > 0 ? 1 : -1;
        this.terrainTarget.classList.toggle('vertical', vertical);
        this.terrainTarget.classList.toggle('horizontal', !vertical);
        const zones = [];
        for (let zx = 0; zx < COLONNES; zx++) {
            for (let zy = 0; zy < COULOIRS; zy++) {
                let ligne;
                let colonne;
                if (vertical) {
                    // Attaque vers le haut (sens 1) ou vers le bas.
                    ligne = sens > 0 ? COLONNES - zx : zx + 1;
                    colonne = sens > 0 ? zy + 1 : COULOIRS - zy;
                } else {
                    // Attaque vers la droite (sens 1) ou vers la gauche ; zy 0 (gauche de l'attaquant) en haut.
                    colonne = sens > 0 ? zx + 1 : COLONNES - zx;
                    ligne = sens > 0 ? zy + 1 : COULOIRS - zy;
                }
                zones.push(`<button type="button" class="zone" style="grid-row:${ligne};grid-column:${colonne}" data-zx="${zx}" data-zy="${zy}" data-action="direct#choisirZone" aria-label="Zone ${zx + 1}-${zy + 1}"></button>`);
            }
        }
        this.terrainTarget.querySelector('.zones').innerHTML = zones.join('');
        const fleche = vertical ? (sens > 0 ? '↑' : '↓') : (sens > 0 ? '→' : '←');
        this.sensTarget.textContent = `Nous attaquons ${fleche}`;
        this.terrainTarget.dataset.sens = sens > 0 ? 'avant' : 'arriere';
        this.sensDessine = sens;
    }

    afficher() {
        const etape = this.element.dataset.etape || 'joueur';
        this.joueursTarget.querySelectorAll('[data-joueur]').forEach((b) => {
            const id = Number(b.dataset.joueur);
            b.classList.toggle('choisi', this.choix.joueur !== undefined && (this.choix.joueur ?? 0) === id);
        });
        // Action choisie mise en avant (les nôtres dans « 2. Action », celles de l'adversaire sous les joueurs).
        this.element.querySelectorAll('.direct-saisie [data-type]').forEach((b) => {
            b.classList.toggle('choisi', b.dataset.type === this.choix.type);
        });
        const j = this.joueur(this.choix.joueur);
        const morceaux = [this.choix.joueur === null ? 'Adversaire' : (j ? `${j.numero ? `${j.numero} ` : ''}${j.nom}` : '…joueur'), this.choix.type ? this.cfg.types[this.choix.type] ?? this.cfg.adverses[this.choix.type] : '…action'];
        if (this.avecZones) morceaux.push('zone (facultatif)');
        this.consigneTarget.classList.remove('alerte');
        const aPlacer = this.aPlacer ? this.actions.get(this.aPlacer) : null;
        this.terrainTarget.classList.toggle('a-placer', !!aPlacer || !!this.choix.modifie);
        this.passerZoneTarget.hidden = !aPlacer;
        if (aPlacer) {
            // Action déjà enregistrée : la zone ne fait que la compléter.
            this.consigneTarget.innerHTML = `✓ <b>${this.echapper(this.libelle(aPlacer))}</b> noté · touchez la zone si vous voulez (facultatif)`;
        } else {
            this.consigneTarget.innerHTML = (this.choix.modifie ? '<b>Correction</b> : touchez une autre action, un autre joueur ou la zone · ' : '') + morceaux.map((m, i) => {
                const nom = ['joueur', 'action', 'zone'][i];
                return `<button type="button" class="lien-bouton ${nom === etape ? 'en-cours' : ''}" data-etape="${nom}" data-action="direct#allerEtape">${this.echapper(m)}</button>`;
            }).join(' ▸ ')
                + (this.choix.collant && !this.choix.type ? ' <span class="discret">· joueur gardé : touchez son action, ou un autre joueur</span>' : '')
                + (this.choix.type || this.choix.joueur !== undefined ? ' <button type="button" class="lien-bouton annuler" data-action="direct#annulerChoix">Annuler</button>' : '');
        }
        this.boutonAnnulerDerniereTarget.disabled = !this.mesDernieres.some((u) => this.actions.has(u));
        // « C'était un but ! » proposé après la dernière frappe ou tête cadrée.
        this.proposeButTarget.hidden = !this.dernierTir;
        if (this.dernierTir) this.texteProposeButTarget.textContent = `${this.libelle(this.dernierTir)} à la ${this.dernierTir.minute}e minute.`;
        // Score : buts notés par toutes les tablettes.
        const liste = [...this.actions.values()];
        this.scoreTarget.textContent = `${liste.filter((a) => a.type === 'B').length} – ${liste.filter((a) => a.type === 'BA').length}`;
        // Journal : dernières actions d'abord ; celles des autres tablettes avec leur auteur, sans ✎ ni ✕.
        const triees = liste.sort((a, b) => (b.periode - a.periode) || (b.minute - a.minute));
        const visibles = this.journalComplet ? triees : triees.slice(0, 6);
        this.journalTarget.innerHTML = visibles.map((a) => {
            const moi = this.aMoi(a);
            return `<li class="${this.enAttente[a.uuid] ? 'en-attente' : ''}${a.uuid === this.choix.modifie ? ' modifiee' : ''}${moi ? '' : ' autre'}">
            <span class="min">${a.minute}'</span><span class="quoi"><b class="pastille-action action-${a.type}">${a.type}</b> ${this.echapper(this.libelle(a))}${moi ? '' : ` <span class="par">· ${this.echapper(a.par ?? 'autre tablette')}</span>`}</span>
            ${moi ? `<button type="button" class="lien-bouton" data-uuid="${a.uuid}" data-action="direct#modifier" title="Corriger">✎</button>
            <button type="button" class="lien-bouton danger" data-uuid="${a.uuid}" data-action="direct#supprimer" title="Supprimer">✕</button>` : ''}</li>`;
        }).join('') || '<li class="discret">Aucune action notée.</li>';
        this.toutTarget.hidden = triees.length <= 6;
        this.toutTarget.textContent = this.journalComplet ? 'Moins' : `Tout le journal (${triees.length})`;
        this.dessinerChangements();
        this.etatEnvoi();
        if (this.infos.meteo) this.meteoTarget.value = this.infos.meteo;
        if (this.infos.terrain) this.solTarget.value = this.infos.terrain;
        if (this.infos.vent) this.ventTarget.value = this.infos.vent;
        if (this.infos.temperature) this.temperatureTarget.value = this.infos.temperature;
    }

    etatEnvoi(erreur) {
        const n = Object.keys(this.enAttente).length + this.supprimes.length;
        this.etatTarget.textContent = erreur || (n ? `${n} à envoyer…` : '✓ Tout est enregistré');
        this.etatTarget.classList.toggle('alerte', !!erreur || n > 0);
    }

    // ———————— Échange avec le serveur ————————

    async envoyer() {
        if (this.envoiEnCours) return;
        const actions = Object.values(this.enAttente);
        const supprimes = [...this.supprimes];
        const chrono = this.chronoAEnvoyer ? { ...this.chrono } : null;
        const numeros = { ...this.numerosAEnvoyer };
        const titulaires = this.titulairesAEnvoyer;
        this.envoiEnCours = true;
        try {
            const avant = Date.now();
            const reponse = await fetch(this.cfg.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ jeton: this.cfg.jeton, actions, supprimes, chrono, numeros, ...(titulaires ? { titulaires } : {}), infos: this.infosAEnvoyer ? this.infos : {} }),
            });
            if (reponse.status === 404) {
                // Match supprimé (sur un autre appareil) : plus rien ne peut être enregistré, on arrête d'essayer.
                clearInterval(this.relance);
                this.element.classList.add('match-supprime');
                this.etatTarget.textContent = 'Ce match a été supprimé : les actions ne sont plus enregistrées.';
                this.etatTarget.classList.add('alerte');
                this.consigneTarget.innerHTML = '<b>Ce match a été supprimé</b> (sur un autre appareil ?). Revenez à la liste des <a href="/matchs">matchs</a>.';
                return;
            }
            if (!reponse.ok) {
                const corps = await reponse.json().catch(() => ({}));
                throw new Error(corps.erreur || `Erreur ${reponse.status}`);
            }
            const donnees = await reponse.json();
            // Heure du serveur : milieu de l'aller-retour.
            this.decalage = donnees.maintenant - (avant + Date.now()) / 2;
            // Seules les actions envoyées sortent de l'attente (une action ajoutée pendant l'envoi y reste).
            actions.forEach((a) => { if (this.enAttente[a.uuid] === a) delete this.enAttente[a.uuid]; });
            this.supprimes = this.supprimes.filter((u) => !supprimes.includes(u));
            if (chrono && this.chrono.maj === chrono.maj) this.chronoAEnvoyer = false;
            this.infosAEnvoyer = false;
            // Chrono commun : celui du serveur s'il est plus récent (changé sur une autre tablette).
            if (donnees.chrono && donnees.chrono.maj > (this.chrono.maj ?? 0) && !this.chronoAEnvoyer) {
                this.chrono = { ...this.chrono, ...donnees.chrono };
                if (this.chrono.enCours) this.garderEcranAllume();
                if (this.sensDessine !== (this.chrono.sens > 0 ? 1 : -1)) this.dessinerTerrain();
                this.tic();
            }
            // Numéros envoyés : sortis de l'attente s'ils n'ont pas changé entre-temps ; liste des joueurs du serveur
            // (numéros donnés sur les autres tablettes), redessinée sauf pendant la saisie des numéros.
            Object.entries(numeros).forEach(([id, n]) => { if (this.numerosAEnvoyer[id] === n) delete this.numerosAEnvoyer[id]; });
            if (Array.isArray(donnees.joueurs)) {
                const avant = JSON.stringify(this.cfg.joueurs);
                this.cfg.joueurs = donnees.joueurs;
                this.appliquerNumeros();
                if (!this.editionNumeros && JSON.stringify(this.cfg.joueurs) !== avant) this.dessinerJoueurs();
            }
            // Titulaires : envoyés, puis ceux du serveur (choisis sur une autre tablette) s'il n'y a rien en attente ici.
            if (titulaires && this.titulairesAEnvoyer === titulaires) this.titulairesAEnvoyer = null;
            if (!this.titulairesAEnvoyer && JSON.stringify(donnees.titulaires ?? null) !== JSON.stringify(this.cfg.titulaires ?? null)) {
                this.cfg.titulaires = donnees.titulaires ?? null;
                if (this.editionTitulaires) this.dessinerJoueurs();
            }
            this.fusionner(donnees.actions ?? []);
            this.rafraichirBilan(donnees.actions ?? []);
            this.garder();
            this.afficher();
        } catch (e) {
            this.afficher();
            // TypeError : pas de réponse du serveur (pas de réseau au stade, serveur injoignable).
            this.etatEnvoi(navigator.onLine === false || e instanceof TypeError ? 'Pas de réseau : gardé sur la tablette' : `Non envoyé (${e.message}) : nouvel essai bientôt`);
        } finally {
            this.envoiEnCours = false;
        }
    }

    // ———————— Outils ————————

    estAdverse(type) {
        return type in this.cfg.adverses;
    }

    joueur(id) {
        return this.cfg.joueurs.find((j) => j.id === id);
    }

    libelle(a) {
        const j = this.joueur(a.joueur);
        if (a.type === 'EX') return `${j ? `${j.numero ? `${j.numero} ` : ''}${j.nom} · ` : ''}Carton blanc (10 min, retour à la ${a.minute + 10}e)`;
        if (a.type === 'RP') {
            const nom = (x) => (x ? `${x.numero ? `${x.numero} ` : ''}${x.nom}` : '?');
            return `Changement : ${nom(j)} sort,${a.details?.entrant ? `${nom(this.joueur(a.details.entrant))} entre` : 'sans remplaçant'}${a.details?.motif === 'Blessure' ? ' (blessure)' : ''}`;
        }
        const geste = { tete: ' (tête)', csc: ' (contre son camp)' }[a.details?.geste] || '';
        const passeur = this.joueur(a.details?.passeur);
        const quoi = (this.cfg.types[a.type] ?? this.cfg.adverses[a.type] ?? a.type) + geste + (passeur ? `, passe de ${passeur.nom}` : '');
        return j ? `${j.numero ? `${j.numero} ` : ''}${j.nom} · ${quoi}` : quoi;
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
