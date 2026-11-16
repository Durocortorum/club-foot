import { Controller } from '@hotwired/stimulus';

/*
 * Match en direct (table de statistiques) : joueur → action → zone, la minute vient du chrono.
 *
 * Tout est d'abord gardé sur l'appareil (localStorage) puis envoyé au serveur dès que possible : une coupure de réseau
 * au stade ne fait rien perdre ; les envois sont rejoués (même uuid : pas de doublon). Le chrono aussi est gardé :
 * recharger la page ou verrouiller la tablette ne l'arrête pas.
 *
 * Zones enregistrées dans le sens de notre attaque : zx 0 (devant notre but) → 5 (devant le but adverse),
 * zy 0 (gauche) → 2 (droite). L'affichage tourne selon l'écran (horizontal ou vertical) et le côté de chaque période.
 */
const COLONNES = 6;
const COULOIRS = 3;

export default class extends Controller {
    static values = { config: Object };
    static targets = ['joueurs', 'types', 'terrain', 'journal', 'score', 'chrono', 'minute', 'periode', 'boutonChrono', 'etat', 'consigne', 'meteo', 'sol', 'sens', 'tout', 'plein'];

    connect() {
        this.cfg = this.configValue;
        this.cle = `direct-${this.cfg.match}`;
        const sauve = this.lire();
        this.chrono = sauve.chrono ?? { periode: 1, enCours: false, debut: null, cumul: 0, fini: false };
        this.sens = sauve.sens ?? 1;
        this.enAttente = sauve.enAttente ?? {};
        this.supprimes = sauve.supprimes ?? [];
        this.infos = sauve.infos ?? {};
        // Actions connues : celles du serveur, puis celles pas encore envoyées (ajoutées ou corrigées), moins les supprimées.
        this.actions = new Map(this.cfg.actions.map((a) => [a.uuid, a]));
        Object.values(this.enAttente).forEach((a) => this.actions.set(a.uuid, a));
        this.supprimes.forEach((u) => this.actions.delete(u));
        this.choix = { joueur: undefined, type: null, modifie: null };
        // Terrain vertical sur téléphone et tablette en portrait (même seuil que la feuille de style).
        this.vertical = window.matchMedia('(max-width: 1100px)');
        this.surChangementEcran = () => this.dessinerTerrain();
        this.vertical.addEventListener('change', this.surChangementEcran);
        this.surReseau = () => this.envoyer();
        window.addEventListener('online', this.surReseau);
        this.minuteur = setInterval(() => this.tic(), 1000);
        this.relance = setInterval(() => this.envoyer(), 20000);
        // Bouton « Plein écran » seulement si l'appareil le permet (pas sur iPhone).
        this.pleinTarget.hidden = !(this.element.requestFullscreen || this.element.webkitRequestFullscreen);
        this.dessinerJoueurs();
        this.dessinerTypes();
        this.dessinerTerrain();
        this.afficher();
        this.tic();
        this.envoyer();
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
            localStorage.setItem(this.cle, JSON.stringify({ chrono: this.chrono, sens: this.sens, enAttente: this.enAttente, supprimes: this.supprimes, infos: this.infos }));
        } catch (e) { /* stockage plein ou interdit : l'envoi au serveur reste tenté */ }
    }

    // ———————— Chrono ————————

    secondes() {
        const c = this.chrono;
        return Math.max(0, c.cumul + (c.enCours && c.debut ? (Date.now() - c.debut) / 1000 : 0));
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
            c.debut = Date.now();
            this.garderEcranAllume();
        }
        this.garder();
        this.tic();
    }

    finPeriode() {
        const c = this.chrono;
        if (c.periode === 1) {
            if (!confirm('Fin de la 1re période ? Le chrono repartira à la mi-temps et les équipes changent de côté.')) return;
            this.chrono = { periode: 2, enCours: false, debut: null, cumul: 0, fini: false };
            this.sens = -this.sens;
            this.dessinerTerrain();
        } else {
            if (!confirm('Fin du match ?')) return;
            c.cumul = this.secondes();
            c.enCours = false;
            c.debut = null;
            c.fini = true;
        }
        this.garder();
        this.tic();
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
        if (c.enCours) c.debut = Date.now();
        this.garder();
        this.tic();
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

    // ———————— Choix : joueur, action, zone ————————

    dessinerJoueurs() {
        const tuiles = this.cfg.joueurs.map((j) => `<button type="button" class="tuile-joueur" data-joueur="${j.id}" data-action="direct#choisirJoueur">
            <span class="num">${j.numero ?? ''}</span><span class="nom">${this.echapper(j.nom)}</span><span class="prenom">${this.echapper(j.prenom)}</span></button>`);
        tuiles.push(`<button type="button" class="tuile-joueur adverse" data-joueur="0" data-action="direct#choisirJoueur"><span class="nom">Adversaire</span><span class="prenom">frappe, corner, but</span></button>`);
        this.joueursTarget.innerHTML = tuiles.join('');
    }

    dessinerTypes() {
        const bouton = (code, libelle, adverse) => `<button type="button" class="tuile-action action-${code}${adverse ? ' adverse' : ''}" data-type="${code}" data-action="direct#choisirType"><b>${code}</b> ${libelle}</button>`;
        this.typesTarget.innerHTML = Object.entries(this.cfg.types).map(([c, l]) => bouton(c, l, false)).join('')
            + Object.entries(this.cfg.adverses).map(([c, l]) => bouton(c, l, true)).join('');
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
        this.actions.set(action.uuid, action);
        this.enAttente[action.uuid] = action;
        this.garder();
        navigator.vibrate?.(25);
        event.currentTarget.classList.add('touchee');
        setTimeout(() => event.currentTarget.classList.remove('touchee'), 350);
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

    // ———————— Journal : corriger, supprimer ————————

    modifier(event) {
        const a = this.actions.get(event.currentTarget.dataset.uuid);
        if (!a) return;
        this.choix = { joueur: this.estAdverse(a.type) ? null : a.joueur, type: a.type, modifie: a.uuid };
        this.etape('zone');
        this.element.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    /** Fil « joueur ▸ action ▸ zone » : revenir à une étape (sur téléphone, une étape à la fois). */
    allerEtape(event) {
        this.etape(event.currentTarget.dataset.etape);
    }

    supprimer(event) {
        const uuid = event.currentTarget.dataset.uuid;
        const a = this.actions.get(uuid);
        if (!a || !confirm(`Supprimer « ${this.libelle(a)} » ?`)) return;
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
        this.sens = -this.sens;
        this.garder();
        this.dessinerTerrain();
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
        this.terrainTarget.classList.toggle('vertical', vertical);
        this.terrainTarget.classList.toggle('horizontal', !vertical);
        const zones = [];
        for (let zx = 0; zx < COLONNES; zx++) {
            for (let zy = 0; zy < COULOIRS; zy++) {
                let ligne;
                let colonne;
                if (vertical) {
                    // Attaque vers le haut (sens 1) ou vers le bas.
                    ligne = this.sens > 0 ? COLONNES - zx : zx + 1;
                    colonne = this.sens > 0 ? zy + 1 : COULOIRS - zy;
                } else {
                    // Attaque vers la droite (sens 1) ou vers la gauche ; zy 0 (gauche de l'attaquant) en haut.
                    colonne = this.sens > 0 ? zx + 1 : COLONNES - zx;
                    ligne = this.sens > 0 ? zy + 1 : COULOIRS - zy;
                }
                zones.push(`<button type="button" class="zone" style="grid-row:${ligne};grid-column:${colonne}" data-zx="${zx}" data-zy="${zy}" data-action="direct#choisirZone" aria-label="Zone ${zx + 1}-${zy + 1}"></button>`);
            }
        }
        this.terrainTarget.querySelector('.zones').innerHTML = zones.join('');
        const fleche = vertical ? (this.sens > 0 ? '↑' : '↓') : (this.sens > 0 ? '→' : '←');
        this.sensTarget.textContent = `Nous attaquons ${fleche}`;
        this.terrainTarget.dataset.sens = this.sens > 0 ? 'avant' : 'arriere';
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
        // Score : buts notés.
        const liste = [...this.actions.values()];
        this.scoreTarget.textContent = `${liste.filter((a) => a.type === 'B').length} – ${liste.filter((a) => a.type === 'BA').length}`;
        // Journal : dernières actions d'abord.
        const triees = liste.sort((a, b) => (b.periode - a.periode) || (b.minute - a.minute));
        const visibles = this.journalComplet ? triees : triees.slice(0, 6);
        this.journalTarget.innerHTML = visibles.map((a) => `<li class="${this.enAttente[a.uuid] ? 'en-attente' : ''}${a.uuid === this.choix.modifie ? ' modifiee' : ''}">
            <span class="min">${a.minute}'</span><span class="quoi"><b class="pastille-action action-${a.type}">${a.type}</b> ${this.echapper(this.libelle(a))}</span>
            <button type="button" class="lien-bouton" data-uuid="${a.uuid}" data-action="direct#modifier" title="Corriger">✎</button>
            <button type="button" class="lien-bouton danger" data-uuid="${a.uuid}" data-action="direct#supprimer" title="Supprimer">✕</button></li>`).join('') || '<li class="discret">Aucune action notée.</li>';
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

    // ———————— Envoi au serveur ————————

    async envoyer() {
        if (this.envoiEnCours) return;
        const actions = Object.values(this.enAttente);
        const supprimes = [...this.supprimes];
        if (!actions.length && !supprimes.length && !this.infosAEnvoyer) {
            this.afficher();
            return;
        }
        this.envoiEnCours = true;
        try {
            const reponse = await fetch(this.cfg.url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ jeton: this.cfg.jeton, actions, supprimes, infos: this.infosAEnvoyer ? this.infos : {} }),
            });
            if (!reponse.ok) {
                const corps = await reponse.json().catch(() => ({}));
                throw new Error(corps.erreur || `Erreur ${reponse.status}`);
            }
            // Seules les actions envoyées sortent de l'attente (une action ajoutée pendant l'envoi y reste).
            actions.forEach((a) => { if (this.enAttente[a.uuid] === a) delete this.enAttente[a.uuid]; });
            this.supprimes = this.supprimes.filter((u) => !supprimes.includes(u));
            this.infosAEnvoyer = false;
            this.garder();
            this.afficher();
        } catch (e) {
            this.afficher();
            this.etatEnvoi(navigator.onLine === false ? 'Hors réseau : gardé sur l\'appareil' : `Non envoyé (${e.message}) : nouvel essai bientôt`);
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
