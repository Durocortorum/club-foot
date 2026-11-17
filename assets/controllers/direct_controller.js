import { Controller } from '@hotwired/stimulus';

/*
 * Match en direct (table de statistiques) : joueur → action → zone, la minute vient du chrono.
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

export default class extends Controller {
    static values = { config: Object };
    static targets = ['joueurs', 'types', 'terrain', 'journal', 'score', 'chrono', 'minute', 'periode', 'boutonChrono', 'etat', 'consigne',
        'meteo', 'sol', 'vent', 'temperature', 'sens', 'tout', 'plein', 'mesStats', 'resumeStats', 'horsLigne', 'boutonNumeros', 'bilan', 'boutonBilan',
        'proposeBut', 'texteProposeBut', 'dialogueBut', 'titreBut', 'terrainBut', 'gestesBut', 'champPasseur', 'passeurBut',
        'dialogueChangement', 'titreChangement', 'sortant', 'entrant', 'motifsChangement', 'minuteChangement', 'erreurChangement'];

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
        this.appliquerNumeros();
        this.mesStats = this.lireMesStats();
        this.fusionner(this.cfg.actions);
        this.choix = { joueur: undefined, type: null, modifie: null };
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
            localStorage.setItem(this.cle, JSON.stringify({ chrono: this.chrono, chronoAEnvoyer: this.chronoAEnvoyer, enAttente: this.enAttente, supprimes: this.supprimes, infos: this.infos, numerosAEnvoyer: this.numerosAEnvoyer }));
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
        this.boutonChronoTarget.textContent = c.enCours ? '⏸ Pause' : (c.cumul > 0 ? '▶ Reprendre' : (c.periode === 1 ? '▶ Coup d\'envoi' : '▶ 2e période'));
        this.boutonChronoTarget.classList.toggle('principal', !c.enCours);
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

    finPeriode() {
        const c = this.chrono;
        if (c.periode === 1) {
            if (!confirm('Fin de la 1re période ? Le chrono repartira à la mi-temps et les équipes changent de côté.')) return;
            this.chrono = { periode: 2, enCours: false, debut: null, cumul: 0, fini: false, sens: -c.sens, maj: c.maj };
            this.dessinerTerrain();
        } else {
            if (!confirm('Fin du match ?')) return;
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

    dessinerJoueurs() {
        const suivis = this.typesSuivis();
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
        zone.innerHTML = [...this.actions.values()].filter((a) => a.type === 'RP')
            .sort((a, b) => (a.periode - b.periode) || (a.minute - b.minute))
            .map((a) => `<span class="changement-fait" title="${this.echapper(this.libelle(a))}"><b>${a.minute}'</b> <span class="sort">↓ ${this.echapper(nom(a.joueur))}</span> <span class="entre">↑ ${this.echapper(nom(a.details?.entrant))}</span></span>`).join('');
    }

    // ———————— Changement : qui sort, qui entre ————————

    nouveauChangement() {
        this.ouvrirChangement({ uuid: this.nouvelUuid(), type: 'RP', joueur: null, periode: this.chrono.periode, minute: this.minuteCourante(), zx: 0, zy: 1, details: {} });
    }

    ouvrirChangement(c) {
        this.changementEnCours = c;
        // Autres changements du match, dans l'ordre : un joueur sorti ne revient pas, un joueur entré est sur le terrain.
        const autres = [...this.actions.values()].filter((a) => a.type === 'RP' && a.uuid !== c.uuid)
            .sort((a, b) => (a.periode - b.periode) || (a.minute - b.minute));
        this.sortisChangement = new Set(autres.map((a) => a.joueur));
        this.entresChangement = new Set(autres.map((a) => a.details?.entrant).filter((id) => !this.sortisChangement.has(id)));
        // Qui sort : pas un joueur déjà sorti.
        this.sortantTarget.innerHTML = this.optionsChangement(this.cfg.joueurs.filter((j) => !this.sortisChangement.has(j.id) || j.id === c.joueur), c.joueur, (j) => (this.entresChangement.has(j.id) ? ' (entré)' : ''));
        this.dessinerEntrants(c.details?.entrant);
        this.minuteChangementTarget.value = c.minute;
        const motif = c.details?.motif || 'Choix';
        this.motifsChangementTarget.querySelectorAll('input').forEach((r) => { r.checked = r.value === motif; });
        this.erreurChangementTarget.hidden = true;
        this.titreChangementTarget.textContent = this.actions.has(c.uuid) ? 'Corriger le changement' : 'Changement';
        this.dialogueChangementTarget.showModal();
    }

    optionsChangement(joueurs, choisi, note = () => '') {
        return '<option value="">— Choisir —</option>' + joueurs.map((j) => `<option value="${j.id}" ${choisi === j.id ? 'selected' : ''}>${j.numero ? `${j.numero} ` : ''}${this.echapper(j.nom)} ${this.echapper(j.prenom)}${note(j)}</option>`).join('');
    }

    /** Qui entre : ni le joueur qui sort, ni un joueur déjà sorti, ni un joueur déjà entré (il est sur le terrain). */
    dessinerEntrants(choisi) {
        const sortant = Number(this.sortantTarget.value);
        const possibles = this.cfg.joueurs.filter((j) => j.id !== sortant && !this.sortisChangement.has(j.id) && !this.entresChangement.has(j.id));
        this.entrantTarget.innerHTML = this.optionsChangement(possibles, possibles.some((j) => j.id === choisi) ? choisi : null);
    }

    changerSortant() {
        this.dessinerEntrants(Number(this.entrantTarget.value));
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
        const entrant = Number(this.entrantTarget.value);
        if (!sortant || !entrant || sortant === entrant) {
            this.erreurChangementTarget.textContent = !sortant || !entrant ? 'Choisissez le joueur qui sort et celui qui entre.' : 'Le joueur qui entre doit être différent de celui qui sort.';
            this.erreurChangementTarget.hidden = false;
            return;
        }
        const minute = Math.max(0, Math.min(130, parseInt(this.minuteChangementTarget.value, 10) || 0));
        const motif = this.motifsChangementTarget.querySelector('input:checked')?.value === 'Blessure' ? 'Blessure' : 'Choix';
        const action = { uuid: c.uuid, type: 'RP', joueur: sortant, periode: c.periode, minute, zx: 0, zy: 1, details: { entrant, motif } };
        this.actions.set(action.uuid, { ...action, auteur: this.cfg.moi });
        this.enAttente[action.uuid] = action;
        this.garder();
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

    choisirJoueur(event) {
        const id = Number(event.currentTarget.dataset.joueur);
        this.choix.joueur = id || null;
        // Adversaire : seulement les actions adverses ; joueur du club : seulement les siennes.
        if (this.choix.type && this.estAdverse(this.choix.type) !== (id === 0)) this.choix.type = null;
        this.etape(this.choix.type ? 'zone' : 'action');
    }

    choisirType(event) {
        const type = event.currentTarget.dataset.type;
        if (this.estAdverse(type)) this.choix.joueur = null;
        else if (this.choix.joueur === null) this.choix.joueur = undefined;
        this.choix.type = type;
        this.etape(this.choix.joueur === undefined && type !== 'CO' ? 'joueur' : 'zone');
    }

    choisirZone(event) {
        const { zx, zy } = event.currentTarget.dataset;
        const type = this.choix.type;
        if (!type || (!this.estAdverse(type) && type !== 'CO' && !this.choix.joueur)) {
            this.consigneTarget.textContent = 'Choisissez d\'abord le joueur et l\'action.';
            this.consigneTarget.classList.add('alerte');
            return;
        }
        const ancienne = this.choix.modifie ? this.actions.get(this.choix.modifie) : null;
        const action = {
            uuid: ancienne?.uuid ?? this.nouvelUuid(),
            joueur: this.estAdverse(type) ? null : (this.choix.joueur || null),
            type,
            periode: ancienne?.periode ?? this.chrono.periode,
            minute: ancienne?.minute ?? this.minuteCourante(),
            zx: Number(zx),
            zy: Number(zy),
        };
        // But (ou but adverse) : la fenêtre « But ! » pour placer le ballon et la direction, avant d'enregistrer.
        if (type === 'B' || type === 'BA') {
            this.choix = { joueur: undefined, type: null, modifie: null };
            this.etape('joueur');
            this.ouvrirBut({ ...action, details: ancienne?.details });
            return;
        }
        this.actions.set(action.uuid, { ...action, auteur: this.cfg.moi });
        this.enAttente[action.uuid] = action;
        this.garder();
        navigator.vibrate?.(25);
        event.currentTarget.classList.add('touchee');
        const zone = event.currentTarget;
        setTimeout(() => zone.classList.remove('touchee'), 350);
        this.choix = { joueur: undefined, type: null, modifie: null };
        // Frappe ou tête cadrée (de nous ou de l'adversaire) : proposer « C'était un but ! ».
        this.dernierTir = ['FC', 'TC', 'AC', 'ATC'].includes(type) ? action : null;
        this.etape('joueur');
        this.envoyer();
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
            periode: t.periode, minute: t.minute, zx: t.zx, zy: t.zy, details: { geste: ['TC', 'ATC'].includes(t.type) ? 'tete' : 'tir' },
        });
        this.afficher();
    }

    /** Fenêtre « But ! » : nos buts vers le but adverse (à droite), ceux de l'adversaire vers le nôtre (à gauche). */
    ouvrirBut(but) {
        const droite = but.type === 'B';
        const d = but.details || {};
        this.butEnCours = {
            ...but,
            depart: d.depart ? [...d.depart] : [(but.zx + 0.5) * 17.5, (but.zy + 0.5) * 68 / 3],
            arrivee: d.arrivee ? [...d.arrivee] : [droite ? 105 : 0, 34],
        };
        // Vue resserrée devant le but (le but paraît plus grand), élargie juste assez pour montrer le départ du tir.
        const [dx, dy] = this.butEnCours.depart;
        const y0 = Math.max(-2, Math.min(10, dy - 6));
        const y1 = Math.min(70, Math.max(58, dy + 6));
        this.butEnCours.vue = droite
            ? [Math.max(50.5, Math.min(78, dx - 6)), y0, 0, y1]
            : [-3.5, y0, Math.min(54.5, Math.max(27, dx + 6)), y1];
        this.butEnCours.vue[droite ? 2 : 0] = droite ? 108.5 : -3.5;
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
        const action = {
            uuid: b.uuid, type: b.type, periode: b.periode, minute: b.minute, zx: b.zx, zy: b.zy,
            // Contre son camp d'un adversaire : pas de buteur chez nous.
            joueur: b.type === 'B' && geste !== 'csc' ? b.joueur : null,
            details: { depart: b.depart.map((v) => Math.round(v * 10) / 10), arrivee: b.arrivee.map((v) => Math.round(v * 10) / 10), geste },
        };
        const passeur = Number(this.passeurButTarget.value);
        if (b.type === 'B' && passeur) action.details.passeur = passeur;
        this.actions.set(action.uuid, { ...action, auteur: this.cfg.moi });
        this.enAttente[action.uuid] = action;
        this.garder();
        this.butEnCours = null;
        this.dialogueButTarget.close();
        navigator.vibrate?.([30, 60, 30]);
        this.afficher();
        this.envoyer();
    }

    /** Demi-terrain vu de dessus (mètres) avec le but, ses poteaux, le ballon et la flèche à tirer. */
    dessinerBut() {
        const b = this.butEnCours;
        const droite = b.type === 'B';
        const bx = droite ? 105 : 0;
        const s = droite ? -1 : 1;
        const couleur = droite ? '#d62839' : '#1f5fd1';
        const svg = this.terrainButTarget;
        const [vx0, vy0, vx1, vy1] = b.vue;
        svg.setAttribute('viewBox', `${vx0} ${vy0} ${vx1 - vx0} ${vy1 - vy0}`);
        const ligne = 'fill="none" stroke="rgba(255,255,255,.85)" stroke-width="0.3"';
        svg.innerHTML = `
            <defs><marker id="pointe-saisie-but" viewBox="0 0 4 4" refX="3.2" refY="2" markerWidth="3" markerHeight="3" orient="auto"><path d="M0,0 L4,2 L0,4 z" fill="${couleur}"/></marker>
            <pattern id="filet-saisie" width="0.8" height="0.8" patternUnits="userSpaceOnUse"><rect width="0.8" height="0.8" fill="#e6e9ee"/><path d="M0,0 L0.8,0.8 M0.8,0 L0,0.8" stroke="#8d939c" stroke-width="0.08"/></pattern></defs>
            <rect x="${droite ? 52.5 : 0}" y="0" width="52.5" height="68" fill="#46a653"/>
            <rect x="${droite ? 52.5 : 0}" y="0" width="52.5" height="68" ${ligne}/>
            <path d="M${bx},13.84 H${bx + s * 16.5} V54.16 H${bx}" ${ligne}/>
            <path d="M${bx},24.84 H${bx + s * 5.5} V43.16 H${bx}" ${ligne}/>
            <circle cx="${bx + s * 11}" cy="34" r="0.35" fill="#fff"/>
            <path d="M${bx + s * 16.5},26.69 A9.15,9.15 0 0 ${droite ? 0 : 1} ${bx + s * 16.5},41.31" ${ligne}/>
            <path d="M52.5,24.85 A9.15,9.15 0 0 ${droite ? 1 : 0} 52.5,43.15" ${ligne}/>
            <rect x="${droite ? 105 : -2.4}" y="30.34" width="2.4" height="7.32" fill="url(#filet-saisie)" stroke="#6b6e75" stroke-width="0.25"/>
            <circle cx="${bx}" cy="30.34" r="0.5" fill="#fff" stroke="#1d1d1f" stroke-width="0.2"/>
            <circle cx="${bx}" cy="37.66" r="0.5" fill="#fff" stroke="#1d1d1f" stroke-width="0.2"/>
            <line x1="${b.depart[0]}" y1="${b.depart[1]}" x2="${b.arrivee[0]}" y2="${b.arrivee[1]}" stroke="${couleur}" stroke-width="0.5" marker-end="url(#pointe-saisie-but)"/>
            <circle class="poignee-but" data-poignee="arrivee" cx="${b.arrivee[0]}" cy="${b.arrivee[1]}" r="1.6" fill="rgba(255,255,255,.35)" stroke="${couleur}" stroke-width="0.25"/>
            <g class="poignee-but" data-poignee="depart">
                <circle cx="${b.depart[0]}" cy="${b.depart[1]}" r="2.2" fill="transparent"/>
                <circle cx="${b.depart[0]}" cy="${b.depart[1]}" r="1.1" fill="#fff" stroke="#1d1d1f" stroke-width="0.2"/>
                <path d="M${b.depart[0]},${b.depart[1] - 0.45} l0.43,0.31 l-0.16,0.5 h-0.54 l-0.16,-0.5 z" fill="#1d1d1f"/>
            </g>`;
        if (!this.ecouteBut) {
            // Ballon et pointe de la flèche se déplacent au doigt ou à la souris.
            this.ecouteBut = true;
            svg.addEventListener('pointerdown', (e) => {
                const p = e.target.closest('[data-poignee]');
                if (!p || !this.butEnCours) return;
                e.preventDefault();
                this.priseBut = p.dataset.poignee;
                svg.setPointerCapture?.(e.pointerId);
            });
            svg.addEventListener('pointermove', (e) => {
                if (!this.priseBut || !this.butEnCours) return;
                const q = new DOMPoint(e.clientX, e.clientY).matrixTransform(svg.getScreenCTM().inverse());
                this.butEnCours[this.priseBut] = [Math.min(108, Math.max(-3, q.x)), Math.min(68, Math.max(0, q.y))];
                this.dessinerBut();
            });
            const lacher = () => { this.priseBut = null; };
            svg.addEventListener('pointerup', lacher);
            svg.addEventListener('pointercancel', lacher);
        }
    }

    annulerChoix() {
        this.choix = { joueur: undefined, type: null, modifie: null };
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
        if (a.type === 'RP') {
            this.ouvrirChangement({ ...a });
            return;
        }
        this.choix = { joueur: this.estAdverse(a.type) ? null : a.joueur, type: a.type, modifie: a.uuid };
        this.etape('zone');
        this.element.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    supprimer(event) {
        const uuid = event.currentTarget.dataset.uuid;
        const a = this.actions.get(uuid);
        if (!a || !this.aMoi(a) || !confirm(`Supprimer « ${this.libelle(a)} » ?`)) return;
        this.actions.delete(uuid);
        delete this.enAttente[uuid];
        this.supprimes.push(uuid);
        if (this.choix.modifie === uuid) this.choix = { joueur: undefined, type: null, modifie: null };
        this.garder();
        this.afficher();
        this.envoyer();
    }

    /** Bilan du match sous la saisie (cadre Turbo), rechargé à chaque ouverture pour compter les dernières actions. */
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
        const morceaux = [this.choix.joueur === null ? 'Adversaire' : (j ? `${j.numero ? `${j.numero} ` : ''}${j.nom}` : '…joueur'), this.choix.type ? this.cfg.types[this.choix.type] ?? this.cfg.adverses[this.choix.type] : '…action', '…zone'];
        this.consigneTarget.classList.remove('alerte');
        this.consigneTarget.innerHTML = (this.choix.modifie ? '<b>Correction</b> · ' : '') + morceaux.map((m, i) => {
            const nom = ['joueur', 'action', 'zone'][i];
            return `<button type="button" class="lien-bouton ${nom === etape ? 'en-cours' : ''}" data-etape="${nom}" data-action="direct#allerEtape">${this.echapper(m)}</button>`;
        }).join(' ▸ ') + (this.choix.type || this.choix.joueur !== undefined ? ' <button type="button" class="lien-bouton annuler" data-action="direct#annulerChoix">Annuler</button>' : '');
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
        this.envoiEnCours = true;
        try {
            const avant = Date.now();
            const reponse = await fetch(this.cfg.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ jeton: this.cfg.jeton, actions, supprimes, chrono, numeros, infos: this.infosAEnvoyer ? this.infos : {} }),
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
            this.fusionner(donnees.actions ?? []);
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
        if (a.type === 'RP') {
            const nom = (x) => (x ? `${x.numero ? `${x.numero} ` : ''}${x.nom}` : '?');
            return `Changement : ${nom(j)} sort, ${nom(this.joueur(a.details?.entrant))} entre${a.details?.motif === 'Blessure' ? ' (blessure)' : ''}`;
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
