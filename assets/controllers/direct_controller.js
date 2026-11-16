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
        'meteo', 'sol', 'sens', 'tout', 'plein', 'mesStats', 'resumeStats', 'horsLigne', 'boutonNumeros'];

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

    lireMesStats() {
        try {
            const l = JSON.parse(localStorage.getItem(CLE_MES_STATS) || 'null');
            if (Array.isArray(l) && l.length) return l.filter((g) => g in this.cfg.groupes);
        } catch (e) { /* rien de gardé */ }
        return Object.keys(this.cfg.groupes);
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
            localStorage.setItem(CLE_MES_STATS, JSON.stringify(this.mesStats));
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
        const avecJoueurs = suivis.some((t) => !this.estAdverse(t));
        const tuiles = avecJoueurs ? this.cfg.joueurs.map((j) => `<button type="button" class="tuile-joueur" data-joueur="${j.id}" data-action="direct#choisirJoueur">
            <span class="num${j.numero ? '' : ' sans'}">${j.numero ?? '?'}</span><span class="nom">${this.echapper(j.nom)}</span><span class="prenom">${this.echapper(j.prenom)}</span></button>`) : [];
        if (suivis.some((t) => this.estAdverse(t))) {
            tuiles.push('<button type="button" class="tuile-joueur adverse" data-joueur="0" data-action="direct#choisirJoueur"><span class="nom">Adversaire</span><span class="prenom">frappe, corner, but</span></button>');
        }
        this.joueursTarget.innerHTML = tuiles.join('');
    }

    dessinerTypes() {
        const suivis = this.typesSuivis();
        const bouton = (code, libelle, adverse) => `<button type="button" class="tuile-action action-${code}${adverse ? ' adverse' : ''}" data-type="${code}" data-action="direct#choisirType"><b>${code}</b> ${this.echapper(libelle)}</button>`;
        this.typesTarget.innerHTML = Object.entries(this.cfg.types).filter(([c]) => suivis.includes(c)).map(([c, l]) => bouton(c, l, false)).join('')
            + Object.entries(this.cfg.adverses).filter(([c]) => suivis.includes(c)).map(([c, l]) => bouton(c, l, true)).join('');
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
        this.actions.set(action.uuid, { ...action, auteur: this.cfg.moi });
        this.enAttente[action.uuid] = action;
        this.garder();
        navigator.vibrate?.(25);
        event.currentTarget.classList.add('touchee');
        const zone = event.currentTarget;
        setTimeout(() => zone.classList.remove('touchee'), 350);
        this.choix = { joueur: undefined, type: null, modifie: null };
        this.etape('joueur');
        this.envoyer();
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
        this.infos = { meteo: this.meteoTarget.value, terrain: this.solTarget.value };
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
        const adverse = this.choix.joueur === null;
        this.typesTarget.querySelectorAll('[data-type]').forEach((b) => {
            b.classList.toggle('choisi', b.dataset.type === this.choix.type);
            const estAdv = this.estAdverse(b.dataset.type);
            b.disabled = (adverse && !estAdv) || (this.choix.joueur > 0 && estAdv);
        });
        const j = this.joueur(this.choix.joueur);
        const morceaux = [this.choix.joueur === null ? 'Adversaire' : (j ? `${j.numero ? `${j.numero} ` : ''}${j.nom}` : '…joueur'), this.choix.type ? this.cfg.types[this.choix.type] ?? this.cfg.adverses[this.choix.type] : '…action', '…zone'];
        this.consigneTarget.classList.remove('alerte');
        this.consigneTarget.innerHTML = (this.choix.modifie ? '<b>Correction</b> · ' : '') + morceaux.map((m, i) => {
            const nom = ['joueur', 'action', 'zone'][i];
            return `<button type="button" class="lien-bouton ${nom === etape ? 'en-cours' : ''}" data-etape="${nom}" data-action="direct#allerEtape">${this.echapper(m)}</button>`;
        }).join(' ▸ ') + (this.choix.type || this.choix.joueur !== undefined ? ' <button type="button" class="lien-bouton annuler" data-action="direct#annulerChoix">Annuler</button>' : '');
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
        this.etatEnvoi();
        if (this.infos.meteo) this.meteoTarget.value = this.infos.meteo;
        if (this.infos.terrain) this.solTarget.value = this.infos.terrain;
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
        const quoi = this.cfg.types[a.type] ?? this.cfg.adverses[a.type] ?? a.type;
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
