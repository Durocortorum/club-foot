import { Controller } from '@hotwired/stimulus';
import { confirmer } from '../confirmation.js';

/*
 * Schéma d'exercice : terrain en SVG (unité = 1 mètre) et objets que l'on place et déplace
 * à la souris ou au doigt (joueurs, ballons, cônes, plots, buts, mannequins, flèches, zones, textes).
 * En édition, le schéma est rangé en JSON dans le champ caché du formulaire ;
 * en lecture (lecture: true), il est seulement dessiné à partir de la valeur « schema ».
 *
 * Animation : les objets donnent la position de départ ; « etapes » liste les étapes suivantes,
 * chacune avec, par indice d'objet, la nouvelle place [x, y] d'un joueur ou d'un ballon (null = ne bouge pas),
 * et éventuellement les points de passage du trajet pour y arriver : [x, y, [[x, y], …]] (trajet courbé).
 * La lecture fait glisser joueurs et ballons d'une étape à l'autre, le long de leur trajet.
 */

const NS = 'http://www.w3.org/2000/svg';
const MARGE = 3;
const TERRAINS = {
    entier: { largeur: 105 + 2 * MARGE, hauteur: 68 + 2 * MARGE },
    demi: { largeur: 68 + 2 * MARGE, hauteur: 52.5 + 2 * MARGE },
    libre: { largeur: 60, hauteur: 40 },
};
const COULEURS = {
    rouge: '#d62839', bleu: '#1f5fd1', jaune: '#f5c518', orange: '#ff7a1a', blanc: '#ffffff', noir: '#1d1d1f',
};
// Couleurs proposées pour chaque type d'objet (la première est celle par défaut).
const PALETTES = {
    joueur: ['rouge', 'bleu', 'jaune', 'blanc'],
    cone: ['orange', 'jaune', 'bleu', 'rouge', 'blanc'],
    plot: ['jaune', 'orange', 'bleu', 'rouge', 'blanc'],
    fleche: ['noir', 'blanc', 'jaune', 'rouge', 'bleu'],
    zone: ['jaune', 'bleu', 'rouge', 'blanc'],
    texte: ['blanc', 'noir', 'jaune'],
};
const NOMS = {
    joueur: 'Joueur', ballon: 'Ballon', cone: 'Cône', plot: 'Plot', but: 'But', minibut: 'Mini-but',
    mannequin: 'Mannequin', fleche: 'Flèche', zone: 'Zone', texte: 'Texte',
};
const STYLES_FLECHE = { passe: 'Passe', course: 'Course', conduite: 'Conduite de balle' };
const TOURNANTS = ['but', 'minibut', 'mannequin'];
// Objets qui se déplacent d'une étape à l'autre ; les autres restent fixes.
const MOBILES = ['joueur', 'ballon'];
const MAX_ETAPES = 30;
// Durée d'un déplacement et temps d'arrêt sur chaque étape (millisecondes).
const DUREE = 1300;
const PAUSE = 500;
const arrondi = (v) => Math.round(v * 10) / 10;
const lisser = (f) => (f < 0.5 ? 2 * f * f : 1 - ((-2 * f + 2) ** 2) / 2);

let instances = 0;

export default class extends Controller {
    static targets = [
        'svg', 'champ', 'terrain', 'selection', 'nom', 'couleurs', 'texte', 'libelleTexte', 'style', 'tourner', 'annuler',
        'etapes', 'supprimerEtape', 'ajouterEtape', 'lecture', 'aideEtape', 'trace', 'redresser', 'aidePliage',
    ];
    static values = { lecture: Boolean, schema: Object };

    connect() {
        this.id = `schema${++instances}`;
        const source = this.hasChampTarget ? this.lire(this.champTarget.value) : this.schemaValue;
        this.terrain = TERRAINS[source.terrain] ? source.terrain : 'demi';
        // Indices des étapes alignés sur ceux des objets : les objets inconnus sont gardés comme trous (null).
        const objets = Array.isArray(source.objets) ? source.objets : [];
        const gardes = objets.map((o) => !!(o && NOMS[o.t]));
        this.objets = objets.filter((o, i) => gardes[i]);
        this.etapes = (Array.isArray(source.etapes) ? source.etapes : []).filter(Array.isArray).slice(0, MAX_ETAPES)
            .map((e) => e.filter((p, i) => gardes[i]).map((p) => {
                if (!Array.isArray(p) || !Number.isFinite(p[0]) || !Number.isFinite(p[1])) return null;
                const passages = Array.isArray(p[2]) ? p[2].filter((q) => Array.isArray(q) && Number.isFinite(q[0]) && Number.isFinite(q[1])) : [];
                return passages.length ? [p[0], p[1], passages] : [p[0], p[1]];
            }));
        this.etape = 0;
        this.animation = null;
        this.choisi = null;
        this.historique = [];
        if (this.hasTerrainTarget) {
            this.terrainTarget.value = this.terrain;
        }
        if (!this.lectureValue) {
            this.clavier = this.toucheClavier.bind(this);
            document.addEventListener('keydown', this.clavier);
            this.svgTarget.addEventListener('pointerdown', (e) => this.saisir(e));
            this.svgTarget.addEventListener('pointermove', (e) => this.glisser(e));
            this.svgTarget.addEventListener('pointerup', () => this.lacher());
            this.svgTarget.addEventListener('pointercancel', () => this.lacher());
            this.svgTarget.addEventListener('dblclick', (e) => this.retirerPliage(e));
        } else if (this.etapes.length) {
            // En lecture, un clic sur le schéma lance ou arrête l'animation.
            this.svgTarget.classList.add('anime');
            this.svgTarget.addEventListener('click', () => this.lireAnimation());
        }
        this.dessiner();
        this.afficherEtapes();
    }

    disconnect() {
        if (this.clavier) {
            document.removeEventListener('keydown', this.clavier);
        }
        if (this.animation) {
            cancelAnimationFrame(this.animation);
            this.animation = null;
        }
    }

    lire(json) {
        try {
            const valeur = JSON.parse(json || '{}');
            return valeur && typeof valeur === 'object' ? valeur : {};
        } catch {
            return {};
        }
    }

    // --- Actions de la barre d'outils ---

    ajouter({ params: { type, couleur } }) {
        this.memoriser();
        const { largeur, hauteur } = TERRAINS[this.terrain];
        // Chaque nouvel objet prend la case suivante d'une grille autour du centre, pour ne pas s'empiler.
        const n = this.objets.length;
        const x = arrondi(largeur / 2 + ((n % 7) - 3) * 5);
        const y = arrondi(hauteur / 2 + ((Math.floor(n / 7) % 4) - 1.5) * 5);
        const objet = { t: type, x, y };
        if (PALETTES[type]) {
            objet.c = couleur || PALETTES[type][0];
        }
        if (type === 'joueur') {
            objet.n = String(this.objets.filter((o) => o.t === 'joueur' && o.c === objet.c).length + 1);
        } else if (type === 'fleche') {
            Object.assign(objet, { x1: x - 5, y1: y, x2: x + 5, y2: y, s: 'passe' });
            delete objet.x;
            delete objet.y;
        } else if (type === 'zone') {
            Object.assign(objet, { x: x - 8, y: y - 5, w: 16, h: 10 });
        } else if (type === 'texte') {
            objet.v = 'Texte';
        } else if (TOURNANTS.includes(type)) {
            objet.r = 0;
        }
        this.objets.push(objet);
        this.choisi = this.objets.length - 1;
        this.modifie();
        if (type === 'texte') {
            this.texteTarget.select();
        }
    }

    changerTerrain() {
        this.memoriser();
        this.terrain = this.terrainTarget.value;
        const { largeur, hauteur } = TERRAINS[this.terrain];
        // Les objets sortis du nouveau terrain sont ramenés au bord.
        this.objets.forEach((o) => {
            ['x', 'x1', 'x2'].forEach((k) => { if (k in o) o[k] = Math.min(Math.max(o[k], 0), largeur); });
            ['y', 'y1', 'y2'].forEach((k) => { if (k in o) o[k] = Math.min(Math.max(o[k], 0), hauteur); });
            (o.p || []).forEach((q) => {
                q[0] = Math.min(Math.max(q[0], 0), largeur);
                q[1] = Math.min(Math.max(q[1], 0), hauteur);
            });
        });
        this.etapes.forEach((e) => e.forEach((p) => {
            if (p) {
                [p, ...(p[2] || [])].forEach((q) => {
                    q[0] = Math.min(Math.max(q[0], 0), largeur);
                    q[1] = Math.min(Math.max(q[1], 0), hauteur);
                });
            }
        }));
        this.modifie();
    }

    couleur({ params: { couleur } }) {
        this.memoriser();
        this.objetChoisi().c = couleur;
        this.modifie();
    }

    texte() {
        const objet = this.objetChoisi();
        if (!this.saisieTexte) {
            this.memoriser();
            this.saisieTexte = true;
        }
        objet[objet.t === 'joueur' ? 'n' : 'v'] = this.texteTarget.value.slice(0, objet.t === 'joueur' ? 3 : 60);
        this.dessiner();
        this.enregistrer();
    }

    finTexte() {
        this.saisieTexte = false;
    }

    style() {
        this.memoriser();
        this.objetChoisi().s = this.styleTarget.value;
        this.modifie();
    }

    /** Flèche pliée : tracé arrondi (courbe) ou en lignes droites (angles). */
    trace() {
        this.memoriser();
        const objet = this.objetChoisi();
        if (this.traceTarget.value === 'brise') objet.k = 'brise';
        else delete objet.k;
        this.modifie();
    }

    /** Trajet de l'objet choisi pour arriver à l'étape affichée (entrée de l'étape), s'il s'y déplace. */
    entreeEtape(i = this.choisi) {
        return this.etape > 0 ? this.etapes[this.etape - 1]?.[i] || null : null;
    }

    /** Retire tous les points de pliage : la flèche redevient droite, ou le trajet de l'étape redevient direct. */
    redresser() {
        const entree = this.entreeEtape();
        if (entree?.[2]) {
            this.memoriser();
            entree.length = 2;
            this.modifie();
            return;
        }
        const objet = this.objetChoisi();
        if (this.etape > 0 || !objet?.p?.length) return;
        this.memoriser();
        delete objet.p;
        this.modifie();
    }

    /** Double-clic sur un point de pliage (flèche) ou de passage (trajet d'une étape) : il est retiré. */
    retirerPliage(event) {
        const cible = event.target.closest('[data-poignee^="p"], [data-poignee^="w"]');
        if (!cible) return;
        const i = Number(cible.dataset.indice);
        const k = Number(cible.dataset.poignee.slice(1));
        const liste = cible.dataset.poignee.startsWith('w') ? this.entreeEtape(i)?.[2] : (this.etape === 0 ? this.objets[i]?.p : null);
        if (!liste?.[k]) return;
        this.memoriser();
        liste.splice(k, 1);
        if (!liste.length) {
            if (cible.dataset.poignee.startsWith('w')) this.entreeEtape(i).length = 2;
            else delete this.objets[i].p;
        }
        this.modifie();
    }

    tourner() {
        this.memoriser();
        const objet = this.objetChoisi();
        objet.r = ((objet.r || 0) + 90) % 360;
        this.modifie();
    }

    dupliquer() {
        this.memoriser();
        const copie = structuredClone(this.objetChoisi());
        ['x', 'x1', 'x2'].forEach((k) => { if (k in copie) copie[k] += 2; });
        ['y', 'y1', 'y2'].forEach((k) => { if (k in copie) copie[k] += 2; });
        (copie.p || []).forEach((q) => { q[0] += 2; q[1] += 2; });
        this.objets.push(copie);
        this.choisi = this.objets.length - 1;
        this.modifie();
    }

    supprimer() {
        if (this.choisi === null) return;
        this.memoriser();
        this.objets.splice(this.choisi, 1);
        this.etapes.forEach((e) => e.splice(this.choisi, 1));
        this.choisi = null;
        this.modifie();
    }

    async toutEffacer() {
        if (!this.objets.length || !(await confirmer('Effacer tous les objets du schéma et les étapes de l\'animation ?', { bouton: 'Tout effacer' }))) return;
        this.memoriser();
        this.objets = [];
        this.etapes = [];
        this.etape = 0;
        this.choisi = null;
        this.modifie();
    }

    annuler() {
        const etat = this.historique.pop();
        if (!etat) return;
        this.arreterAnimation();
        const valeur = JSON.parse(etat);
        ({ terrain: this.terrain, objets: this.objets } = valeur);
        this.etapes = valeur.etapes || [];
        this.etape = Math.min(this.etape, this.etapes.length);
        this.terrainTarget.value = this.terrain;
        this.choisi = null;
        this.modifie();
    }

    // --- Étapes de l'animation ---

    choisirEtape({ params: { etape } }) {
        this.arreterAnimation();
        this.etape = etape;
        this.choisi = null;
        this.dessiner();
        this.afficherSelection();
        this.afficherEtapes();
    }

    // Nouvelle étape juste après celle affichée : elle part des mêmes positions.
    ajouterEtape() {
        if (this.etapes.length >= MAX_ETAPES) return;
        this.arreterAnimation();
        this.memoriser();
        this.etapes.splice(this.etape, 0, []);
        this.etape += 1;
        this.choisi = null;
        this.modifie();
    }

    supprimerEtape() {
        if (this.etape === 0) return;
        this.arreterAnimation();
        this.memoriser();
        this.etapes.splice(this.etape - 1, 1);
        this.etape -= 1;
        this.choisi = null;
        this.modifie();
    }

    /** Place de l'objet i à l'étape k : la dernière place donnée jusqu'à cette étape, sinon celle de départ. */
    positionA(i, k) {
        for (let j = k; j >= 1; j--) {
            const p = this.etapes[j - 1]?.[i];
            if (p) return { x: p[0], y: p[1] };
        }
        return { x: this.objets[i].x, y: this.objets[i].y };
    }

    /** Points du trajet de l'objet i entre l'étape k et la suivante : départ, points de passage, arrivée. */
    trajetEntre(i, k) {
        const a = this.positionA(i, k);
        const b = this.positionA(i, k + 1);
        return [[a.x, a.y], ...(this.etapes[k]?.[i]?.[2] || []), [b.x, b.y]];
    }

    /** Objets tels qu'affichés à l'étape k, ou entre l'étape k et la suivante (f de 0 à 1), le long du trajet. */
    objetsA(k, f = 0) {
        return this.objets.map((o, i) => {
            if (!MOBILES.includes(o.t)) return o;
            if (f <= 0) return { ...o, ...this.positionA(i, k) };
            const [x, y] = this.pointAuLong(this.echantillonnerPoints(this.trajetEntre(i, k)), f);
            return { ...o, x, y };
        });
    }

    /** Point situé à la fraction f (0 à 1) de la longueur d'une ligne de points. */
    pointAuLong(trace, f) {
        const longueurs = [0];
        for (let j = 1; j < trace.length; j++) {
            longueurs.push(longueurs[j - 1] + Math.hypot(trace[j][0] - trace[j - 1][0], trace[j][1] - trace[j - 1][1]));
        }
        const cible = f * longueurs[longueurs.length - 1];
        let j = 1;
        while (j < trace.length - 1 && longueurs[j] < cible) j++;
        const l = longueurs[j] - longueurs[j - 1];
        const r = l > 0 ? (cible - longueurs[j - 1]) / l : 1;
        return [trace[j - 1][0] + (trace[j][0] - trace[j - 1][0]) * r, trace[j - 1][1] + (trace[j][1] - trace[j - 1][1]) * r];
    }

    lireAnimation() {
        if (this.animation) {
            this.arreterAnimation();
            return;
        }
        if (!this.etapes.length) return;
        this.choisi = null;
        this.afficherSelection();
        const debut = performance.now();
        const total = this.etapes.length;
        const image = () => {
            // Arrêt sur la position de départ, puis un déplacement et un arrêt par étape.
            const ecoule = performance.now() - debut - PAUSE;
            const segment = Math.max(0, Math.floor(ecoule / (DUREE + PAUSE)));
            if (segment >= total) {
                this.arreterAnimation(true);
                return;
            }
            const f = ecoule < 0 ? 0 : Math.min(1, (ecoule - segment * (DUREE + PAUSE)) / DUREE);
            this.dessiner(this.objetsA(segment, lisser(f)), (f >= 1 ? segment + 1 : segment) + 1);
            this.animation = requestAnimationFrame(image);
        };
        this.animation = requestAnimationFrame(image);
        this.afficherEtapes();
    }

    // En lecture, l'animation finie reste sur sa dernière étape ; en édition, on revient à l'étape choisie.
    arreterAnimation(fin = false) {
        if (!this.animation) return;
        cancelAnimationFrame(this.animation);
        this.animation = null;
        if (this.lectureValue) {
            this.etape = fin ? this.etapes.length : 0;
        }
        this.dessiner();
        this.afficherEtapes();
    }

    afficherEtapes() {
        if (!this.hasEtapesTarget) return;
        const libelles = ['Départ', ...this.etapes.map((e, k) => `Étape ${k + 2}`)];
        this.etapesTarget.replaceChildren(...libelles.map((libelle, k) => {
            const bouton = document.createElement('button');
            bouton.type = 'button';
            bouton.className = `bouton petit${k === this.etape ? ' actif' : ''}`;
            bouton.textContent = libelle;
            bouton.dataset.action = 'schema#choisirEtape';
            bouton.dataset.schemaEtapeParam = k;
            return bouton;
        }));
        this.supprimerEtapeTarget.hidden = this.etape === 0;
        this.ajouterEtapeTarget.disabled = this.etapes.length >= MAX_ETAPES;
        this.lectureTarget.disabled = !this.etapes.length;
        this.lectureTarget.textContent = this.animation ? '■ Arrêter' : '▶ Lire l\'animation';
        this.aideEtapeTarget.textContent = !this.etapes.length
            ? 'Pour animer l\'exercice : placez les objets au départ, ajoutez une étape, puis déplacez joueurs et ballons à leur nouvelle place.'
            : this.etape === 0
                ? 'Départ : place de tous les objets au début de l\'exercice.'
                : `Étape ${this.etape + 1} : déplacez les joueurs et les ballons vers leur nouvelle place (la trace en pointillés part de l'étape précédente). Les autres objets restent fixes.`;
    }

    toucheClavier(event) {
        if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) return;
        if ((event.key === 'Delete' || event.key === 'Backspace') && this.choisi !== null) {
            event.preventDefault();
            this.supprimer();
        } else if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'z') {
            event.preventDefault();
            this.annuler();
        } else if (event.key === 'Escape') {
            this.choisi = null;
            this.dessiner();
            this.afficherSelection();
        }
    }

    // --- Glisser-déposer ---

    point(event) {
        const p = new DOMPoint(event.clientX, event.clientY).matrixTransform(this.svgTarget.getScreenCTM().inverse());
        const { largeur, hauteur } = TERRAINS[this.terrain];

        return { x: Math.min(Math.max(p.x, 0), largeur), y: Math.min(Math.max(p.y, 0), hauteur) };
    }

    saisir(event) {
        if (this.animation) return;
        const cible = event.target.closest('[data-indice]');
        if (!cible) {
            this.choisi = null;
            this.dessiner();
            this.afficherSelection();
            return;
        }
        event.preventDefault();
        this.choisi = Number(cible.dataset.indice);
        const objet = this.objetChoisi();
        const p = this.point(event);
        // Après le départ, seuls joueurs et ballons se déplacent ; les autres objets restent fixes.
        const fixe = this.etape > 0 && !MOBILES.includes(objet.t);
        const etat = this.etat();
        let poignee = cible.dataset.poignee || null;
        let bouge = false;
        if (!fixe && poignee?.startsWith('m')) {
            // Rond « + » au milieu d'un tronçon : un nouveau point de pliage y est créé, puis on le tire.
            const k = Number(poignee.slice(1));
            objet.p = objet.p || [];
            objet.p.splice(k, 0, [arrondi(p.x), arrondi(p.y)]);
            poignee = `p${k}`;
            bouge = true;
        } else if (!fixe && poignee?.startsWith('v')) {
            // Même chose sur le trajet d'un joueur ou d'un ballon à cette étape : nouveau point de passage.
            const k = Number(poignee.slice(1));
            const entree = this.entreeEtape();
            entree[2] = entree[2] || [];
            entree[2].splice(k, 0, [arrondi(p.x), arrondi(p.y)]);
            poignee = `w${k}`;
            bouge = true;
        }
        this.prise = fixe ? null : {
            poignee,
            depart: p,
            origine: this.etape > 0 ? { ...objet, ...this.positionA(this.choisi, this.etape) } : structuredClone(objet),
            etat,
            bouge,
        };
        this.svgTarget.setPointerCapture(event.pointerId);
        this.dessiner();
        this.afficherSelection();
    }

    glisser(event) {
        if (!this.prise) return;
        const p = this.point(event);
        const dx = p.x - this.prise.depart.x;
        const dy = p.y - this.prise.depart.y;
        const o = this.prise.origine;
        const objet = this.objetChoisi();
        if (this.etape > 0 && this.prise.poignee?.startsWith('w')) {
            this.entreeEtape()[2][Number(this.prise.poignee.slice(1))] = [arrondi(p.x), arrondi(p.y)];
        } else if (this.etape > 0) {
            // Nouvelle place à cette étape ; les points de passage déjà donnés au trajet restent.
            const passages = this.entreeEtape()?.[2];
            this.etapes[this.etape - 1][this.choisi] = [arrondi(o.x + dx), arrondi(o.y + dy), ...(passages ? [passages] : [])];
        } else if (this.prise.poignee === '1') {
            Object.assign(objet, { x1: arrondi(p.x), y1: arrondi(p.y) });
        } else if (this.prise.poignee === '2') {
            Object.assign(objet, { x2: arrondi(p.x), y2: arrondi(p.y) });
        } else if (this.prise.poignee?.startsWith('p')) {
            objet.p[Number(this.prise.poignee.slice(1))] = [arrondi(p.x), arrondi(p.y)];
        } else if (this.prise.poignee === 'taille') {
            Object.assign(objet, { w: arrondi(Math.max(2, o.w + dx)), h: arrondi(Math.max(2, o.h + dy)) });
        } else if (objet.t === 'fleche') {
            Object.assign(objet, { x1: arrondi(o.x1 + dx), y1: arrondi(o.y1 + dy), x2: arrondi(o.x2 + dx), y2: arrondi(o.y2 + dy) });
            if (o.p) objet.p = o.p.map(([x, y]) => [arrondi(x + dx), arrondi(y + dy)]);
        } else {
            Object.assign(objet, { x: arrondi(o.x + dx), y: arrondi(o.y + dy) });
        }
        this.prise.bouge = this.prise.bouge || Math.hypot(dx, dy) > 0.2;
        this.dessiner();
    }

    lacher() {
        if (!this.prise) return;
        if (this.prise.bouge) {
            this.historique.push(this.prise.etat);
            this.enregistrer();
            this.majAnnuler();
        }
        this.prise = null;
    }

    // --- État ---

    objetChoisi() {
        return this.objets[this.choisi];
    }

    etat() {
        // Les trous des étapes (objets sans nouvelle place) deviennent null en JSON.
        return JSON.stringify({ terrain: this.terrain, objets: this.objets, ...(this.etapes.length ? { etapes: this.etapes } : {}) });
    }

    memoriser() {
        this.historique.push(this.etat());
        if (this.historique.length > 100) this.historique.shift();
    }

    modifie() {
        this.dessiner();
        this.afficherSelection();
        this.afficherEtapes();
        this.enregistrer();
        this.majAnnuler();
    }

    enregistrer() {
        if (this.hasChampTarget) {
            this.champTarget.value = this.etat();
        }
    }

    majAnnuler() {
        if (this.hasAnnulerTarget) {
            this.annulerTarget.disabled = !this.historique.length;
        }
    }

    // Panneau de l'objet choisi : couleurs, numéro ou texte, style de flèche, rotation.
    afficherSelection() {
        if (!this.hasSelectionTarget) return;
        const objet = this.choisi === null ? null : this.objetChoisi();
        this.selectionTarget.hidden = !objet;
        if (!objet) return;
        this.nomTarget.textContent = NOMS[objet.t];
        this.couleursTarget.replaceChildren(...(PALETTES[objet.t] || []).map((nom) => {
            const bouton = document.createElement('button');
            bouton.type = 'button';
            bouton.className = `pastille-couleur${objet.c === nom ? ' choisie' : ''}`;
            bouton.style.background = COULEURS[nom];
            bouton.title = nom;
            bouton.dataset.action = 'schema#couleur';
            bouton.dataset.schemaCouleurParam = nom;
            return bouton;
        }));
        const avecTexte = objet.t === 'joueur' || objet.t === 'texte';
        this.libelleTexteTarget.hidden = !avecTexte;
        if (avecTexte) {
            this.libelleTexteTarget.firstChild.textContent = objet.t === 'joueur' ? 'N° ' : 'Texte ';
            this.texteTarget.value = objet.t === 'joueur' ? objet.n || '' : objet.v || '';
            this.texteTarget.maxLength = objet.t === 'joueur' ? 3 : 60;
        }
        this.styleTarget.hidden = objet.t !== 'fleche';
        if (objet.t === 'fleche') {
            this.styleTarget.value = objet.s || 'passe';
        }
        if (this.hasTraceTarget) {
            // Tracé et « Redresser » pour une flèche pliée ; « Redresser » aussi pour un trajet courbé à cette étape.
            const pliee = this.etape === 0 && objet.t === 'fleche' && !!objet.p?.length;
            const entree = MOBILES.includes(objet.t) ? this.entreeEtape() : null;
            this.traceTarget.hidden = !pliee;
            this.redresserTarget.hidden = !pliee && !entree?.[2]?.length;
            if (pliee) this.traceTarget.value = objet.k === 'brise' ? 'brise' : 'courbe';
            if (objet.t === 'fleche' && this.etape === 0) {
                this.aidePliageTarget.hidden = false;
                this.aidePliageTarget.textContent = 'Tirez un rond ＋ pour plier la flèche ; double-clic sur un point pour le retirer.';
            } else if (entree) {
                this.aidePliageTarget.hidden = false;
                this.aidePliageTarget.textContent = 'Tirez le rond ＋ du trajet en pointillés pour le courber ; double-clic sur un point pour le retirer.';
            } else {
                this.aidePliageTarget.hidden = true;
            }
        }
        this.tournerTarget.hidden = !TOURNANTS.includes(objet.t);
    }

    // --- Dessin ---

    el(nom, attributs = {}, parent = null) {
        const element = document.createElementNS(NS, nom);
        Object.entries(attributs).forEach(([cle, valeur]) => element.setAttribute(cle, valeur));
        parent?.append(element);
        return element;
    }

    /**
     * @param affiches objets à dessiner (pendant l'animation), sinon ceux de l'étape choisie
     * @param numero   numéro de l'étape affiché pendant l'animation
     */
    dessiner(affiches = null, numero = null) {
        const objets = affiches || this.objetsA(this.etape);
        const svg = this.svgTarget;
        const { largeur, hauteur } = TERRAINS[this.terrain];
        svg.replaceChildren();
        svg.setAttribute('viewBox', `0 0 ${largeur} ${hauteur}`);

        const defs = this.el('defs', {}, svg);
        Object.entries(COULEURS).forEach(([nom, valeur]) => {
            const marqueur = this.el('marker', {
                id: `${this.id}-pointe-${nom}`, viewBox: '0 0 4 4', refX: 3.2, refY: 2,
                markerWidth: 4, markerHeight: 4, orient: 'auto-start-reverse',
            }, defs);
            this.el('path', { d: 'M0,0 L4,2 L0,4 z', fill: valeur }, marqueur);
        });

        this.dessinerTerrain(svg, largeur, hauteur);
        if (!this.lectureValue && !affiches && this.etape > 0) {
            this.dessinerTraces(svg, objets);
        }
        const calque = this.el('g', {}, svg);
        // Les zones sous les autres objets, pour qu'ils restent saisissables.
        const ordre = objets.map((o, i) => i).sort((a, b) => (objets[b].t === 'zone') - (objets[a].t === 'zone'));
        ordre.forEach((i) => this.dessinerObjet(calque, objets[i], i));
        if (this.choisi !== null && objets[this.choisi] && !this.lectureValue && !affiches) {
            this.dessinerSelection(svg, objets[this.choisi], this.choisi);
        }
        if (this.etapes.length) {
            this.dessinerRepereAnimation(svg, largeur, hauteur, numero);
        }
    }

    // Édition d'une étape : place précédente en transparence et trajet en pointillés.
    dessinerTraces(svg, objets) {
        const g = this.el('g', { 'pointer-events': 'none' }, svg);
        objets.forEach((o, i) => {
            if (!MOBILES.includes(o.t)) return;
            const avant = this.positionA(i, this.etape - 1);
            if (Math.hypot(avant.x - o.x, avant.y - o.y) < 0.2) return;
            this.el('path', {
                d: this.cheminPar(this.trajetEntre(i, this.etape - 1)), stroke: '#fff', 'stroke-width': 0.25, 'stroke-dasharray': '0.7 0.5', fill: 'none', opacity: 0.85,
            }, g);
            const fantome = this.el('g', { opacity: 0.35 }, g);
            this.dessinerObjet(fantome, { ...o, x: avant.x, y: avant.y }, i);
            fantome.querySelectorAll('[data-indice]').forEach((n) => n.removeAttribute('data-indice'));
        });
    }

    // Numéro d'étape pendant l'animation ; bouton ▶ sur les schémas en lecture.
    dessinerRepereAnimation(svg, largeur, hauteur, numero) {
        const animation = this.animation || numero !== null;
        if (animation) {
            const texte = this.el('text', {
                x: 1.5, y: 2.6, 'font-size': 2, 'font-weight': 700, fill: '#fff', stroke: 'rgba(0,0,0,.5)', 'stroke-width': 0.3,
                'paint-order': 'stroke', 'font-family': 'system-ui, sans-serif', 'pointer-events': 'none',
            }, svg);
            texte.textContent = `${numero ?? this.etape + 1} / ${this.etapes.length + 1}`;
        }
        if (!this.lectureValue) return;
        const g = this.el('g', { transform: `translate(${largeur - 4.5} ${hauteur - 4.5})` }, svg);
        this.el('circle', { r: 3, fill: 'rgba(0,0,0,.55)', stroke: '#fff', 'stroke-width': 0.25 }, g);
        this.el('path', { d: animation ? 'M-1,-1 h2 v2 h-2 z' : 'M-0.9,-1.4 L1.5,0 L-0.9,1.4 z', fill: '#fff' }, g);
    }

    dessinerTerrain(svg, largeur, hauteur) {
        const bandes = this.terrain === 'demi' ? hauteur : largeur;
        for (let i = 0; i * 6 < bandes; i++) {
            const rect = this.terrain === 'demi'
                ? { x: 0, y: i * 6, width: largeur, height: 6 }
                : { x: i * 6, y: 0, width: 6, height: hauteur };
            this.el('rect', { ...rect, fill: i % 2 ? '#3f9b4a' : '#46a653' }, svg);
        }
        if (this.terrain === 'libre') return;

        const lignes = this.el('g', { fill: 'none', stroke: 'rgba(255,255,255,.9)', 'stroke-width': 0.25 }, svg);
        const ligne = (attributs, nom = 'path') => this.el(nom, attributs, lignes);
        const m = MARGE;
        const point = (x, y) => this.el('circle', { cx: x, cy: y, r: 0.3, fill: 'rgba(255,255,255,.9)', stroke: 'none' }, lignes);

        if (this.terrain === 'entier') {
            const cy = m + 34;
            ligne({ x: m, y: m, width: 105, height: 68 }, 'rect');
            ligne({ d: `M${m + 52.5},${m} V${m + 68}` });
            ligne({ cx: m + 52.5, cy, r: 9.15 }, 'circle');
            point(m + 52.5, cy);
            [[m, 1], [m + 105, -1]].forEach(([bord, sens]) => {
                ligne({ d: `M${bord},${cy - 20.16} h${sens * 16.5} V${cy + 20.16} h${-sens * 16.5}` });
                ligne({ d: `M${bord},${cy - 9.16} h${sens * 5.5} V${cy + 9.16} h${-sens * 5.5}` });
                ligne({ d: `M${bord},${cy - 3.66} h${-sens * 2} V${cy + 3.66} h${sens * 2}` });
                point(bord + sens * 11, cy);
                ligne({ d: `M${bord + sens * 16.5},${cy - 7.31} A9.15,9.15 0 0 ${sens > 0 ? 1 : 0} ${bord + sens * 16.5},${cy + 7.31}` });
            });
        } else {
            const cx = m + 34;
            ligne({ x: m, y: m, width: 68, height: 52.5 }, 'rect');
            ligne({ d: `M${cx - 20.16},${m} v16.5 H${cx + 20.16} v-16.5` });
            ligne({ d: `M${cx - 9.16},${m} v5.5 H${cx + 9.16} v-5.5` });
            ligne({ d: `M${cx - 3.66},${m} v-2 H${cx + 3.66} v2` });
            point(cx, m + 11);
            ligne({ d: `M${cx - 7.31},${m + 16.5} A9.15,9.15 0 0 0 ${cx + 7.31},${m + 16.5}` });
            ligne({ d: `M${cx - 9.15},${m + 52.5} A9.15,9.15 0 0 1 ${cx + 9.15},${m + 52.5}` });
            point(cx, m + 52.5);
        }
    }

    dessinerObjet(calque, o, i) {
        const couleur = COULEURS[o.c] || COULEURS.noir;
        const contour = { stroke: '#1d1d1f', 'stroke-width': 0.12 };

        if (o.t === 'fleche') {
            const g = this.el('g', { 'data-indice': i, class: 'schema-objet' }, calque);
            const d = this.cheminFleche(o);
            this.el('path', { d, fill: 'none', stroke: 'transparent', 'stroke-width': 2.2 }, g);
            this.el('path', {
                d, fill: 'none', stroke: couleur, 'stroke-width': 0.35, 'stroke-linecap': 'round',
                'stroke-dasharray': o.s === 'course' ? '1 0.7' : 'none', 'marker-end': `url(#${this.id}-pointe-${o.c || 'noir'})`,
            }, g);
            return;
        }
        if (o.t === 'zone') {
            const g = this.el('g', { 'data-indice': i, class: 'schema-objet' }, calque);
            this.el('rect', {
                x: o.x, y: o.y, width: o.w, height: o.h, fill: couleur, 'fill-opacity': 0.22,
                stroke: couleur, 'stroke-width': 0.25, 'stroke-dasharray': '0.8 0.5',
            }, g);
            return;
        }

        const g = this.el('g', { 'data-indice': i, class: 'schema-objet', transform: `translate(${o.x} ${o.y}) rotate(${o.r || 0})` }, calque);
        switch (o.t) {
            case 'joueur': {
                this.el('circle', { r: 2.2, fill: 'transparent' }, g);
                this.el('circle', { r: 1.5, fill: couleur, stroke: o.c === 'blanc' || o.c === 'jaune' ? '#1d1d1f' : '#fff', 'stroke-width': 0.22 }, g);
                if (o.n) {
                    const texte = this.el('text', {
                        'text-anchor': 'middle', 'dominant-baseline': 'central', 'font-size': o.n.length > 2 ? 1.2 : 1.6,
                        'font-weight': 700, fill: o.c === 'blanc' || o.c === 'jaune' ? '#1d1d1f' : '#fff', 'font-family': 'system-ui, sans-serif',
                    }, g);
                    texte.textContent = o.n;
                }
                break;
            }
            case 'ballon':
                this.el('circle', { r: 1.6, fill: 'transparent' }, g);
                this.el('circle', { r: 0.65, fill: '#fff', ...contour }, g);
                this.el('path', { d: 'M0,-0.28 L0.27,-0.09 L0.17,0.23 L-0.17,0.23 L-0.27,-0.09 z', fill: '#1d1d1f' }, g);
                break;
            case 'cone':
                this.el('circle', { r: 1.6, fill: 'transparent' }, g);
                this.el('path', { d: 'M0,-0.95 L0.85,0.7 L-0.85,0.7 z', fill: couleur, ...contour, 'stroke-linejoin': 'round' }, g);
                break;
            case 'plot':
                this.el('circle', { r: 1.4, fill: 'transparent' }, g);
                this.el('ellipse', { rx: 0.75, ry: 0.5, fill: couleur, ...contour }, g);
                break;
            case 'but':
            case 'minibut': {
                const [l, p] = o.t === 'but' ? [7.32, 2] : [3, 1.2];
                this.el('rect', { x: -l / 2 - 0.6, y: -p - 0.6, width: l + 1.2, height: p + 1.2, fill: 'transparent' }, g);
                this.el('rect', { x: -l / 2, y: -p, width: l, height: p, fill: 'rgba(255,255,255,.35)', stroke: '#fff', 'stroke-width': 0.3 }, g);
                for (let x = -l / 2 + 0.8; x < l / 2; x += 0.8) {
                    this.el('path', { d: `M${x},${-p} V0`, stroke: 'rgba(255,255,255,.6)', 'stroke-width': 0.06 }, g);
                }
                break;
            }
            case 'mannequin':
                this.el('circle', { r: 1.6, fill: 'transparent' }, g);
                this.el('rect', { x: -0.9, y: -0.35, width: 1.8, height: 0.7, rx: 0.35, fill: '#e9e3d5', ...contour }, g);
                this.el('circle', { r: 0.32, fill: '#c9bfa8', ...contour }, g);
                break;
            case 'texte': {
                const texte = this.el('text', {
                    'text-anchor': 'middle', 'dominant-baseline': 'central', 'font-size': 2, 'font-weight': 600,
                    fill: couleur, stroke: o.c === 'noir' ? 'rgba(255,255,255,.6)' : 'rgba(0,0,0,.45)', 'stroke-width': 0.3,
                    'paint-order': 'stroke', 'font-family': 'system-ui, sans-serif',
                }, g);
                texte.textContent = o.v || '';
                break;
            }
        }
    }

    /** Points de passage d'une flèche : départ, points de pliage, arrivée. */
    pointsFleche(o) {
        return [[o.x1, o.y1], ...(Array.isArray(o.p) ? o.p.filter((q) => Array.isArray(q) && q.length === 2) : []), [o.x2, o.y2]];
    }

    /**
     * Segments de Bézier d'une courbe douce passant par tous les points (Catmull-Rom) :
     * pour chaque tronçon, [départ, contrôle 1, contrôle 2, arrivée].
     */
    courbe(points) {
        const segments = [];
        for (let i = 0; i < points.length - 1; i++) {
            const p0 = points[i - 1] || points[i];
            const [p1, p2] = [points[i], points[i + 1]];
            const p3 = points[i + 2] || p2;
            segments.push([p1, [p1[0] + (p2[0] - p0[0]) / 6, p1[1] + (p2[1] - p0[1]) / 6], [p2[0] - (p3[0] - p1[0]) / 6, p2[1] - (p3[1] - p1[1]) / 6], p2]);
        }
        return segments;
    }

    /** Une ligne passant par ces points (courbe douce, ou droite entre eux si « brise ») en suite de points rapprochés. */
    echantillonnerPoints(points, brise = false) {
        if (points.length === 2 || brise) return points;
        const resultat = [points[0]];
        this.courbe(points).forEach(([a, b, c, d]) => {
            for (let t = 0.05; t <= 1.0001; t += 0.05) {
                const u = 1 - t;
                resultat.push([0, 1].map((k) => u * u * u * a[k] + 3 * u * u * t * b[k] + 3 * u * t * t * c[k] + t * t * t * d[k]));
            }
        });
        return resultat;
    }

    /** Chemin SVG passant par ces points : droit, en angles (brise) ou en courbe douce. */
    cheminPar(points, brise = false) {
        const f = (q) => `${arrondi(q[0])},${arrondi(q[1])}`;
        if (points.length === 2 || brise) return `M${points.map(f).join(' L')}`;
        return `M${f(points[0])} ${this.courbe(points).map(([, b, c, d]) => `C${f(b)} ${f(c)} ${f(d)}`).join(' ')}`;
    }

    /** Milieu de chaque tronçon d'une ligne (sur la courbe si elle est courbe) : place des ronds « + ». */
    milieux(points, brise = false) {
        return points.length > 2 && !brise
            ? this.courbe(points).map(([a, b, c, d]) => [0, 1].map((k) => (a[k] + 3 * b[k] + 3 * c[k] + d[k]) / 8))
            : points.slice(0, -1).map((q, k) => [(q[0] + points[k + 1][0]) / 2, (q[1] + points[k + 1][1]) / 2]);
    }

    // Trait droit ou plié (courbe ou angles), ou ondulé pour une conduite de balle (la fin reste droite pour la pointe).
    cheminFleche(o) {
        const points = this.pointsFleche(o);
        const f = (q) => `${arrondi(q[0])},${arrondi(q[1])}`;
        if (o.s !== 'conduite') {
            return this.cheminPar(points, o.k === 'brise');
        }
        // Conduite de balle : ondulation le long du tracé, mesurée en distance parcourue.
        const trace = this.echantillonnerPoints(points, o.k === 'brise');
        const longueurs = [0];
        for (let i = 1; i < trace.length; i++) {
            longueurs.push(longueurs[i - 1] + Math.hypot(trace[i][0] - trace[i - 1][0], trace[i][1] - trace[i - 1][1]));
        }
        const total = longueurs[longueurs.length - 1];
        if (total < 3) return `M${points.map(f).join(' L')}`;
        const sortie = [];
        let i = 1;
        for (let s = 0; s <= total - 1.2; s += 0.2) {
            while (i < trace.length - 1 && longueurs[i] < s) i++;
            const [a, b] = [trace[i - 1], trace[i]];
            const l = Math.max(longueurs[i] - longueurs[i - 1], 0.0001);
            const r = (s - longueurs[i - 1]) / l;
            const [ux, uy] = [(b[0] - a[0]) / l, (b[1] - a[1]) / l];
            const ecart = 0.55 * Math.sin((2 * Math.PI * s) / 2);
            sortie.push([a[0] + (b[0] - a[0]) * r - uy * ecart, a[1] + (b[1] - a[1]) * r + ux * ecart]);
        }
        return `M${sortie.map(f).join(' L')} L${f(points[points.length - 1])}`;
    }

    dessinerSelection(svg, o, i) {
        const g = this.el('g', { fill: 'none', stroke: '#fff', 'stroke-width': 0.18, 'stroke-dasharray': '0.6 0.4' }, svg);
        const poignee = (cx, cy, nom) => this.el('circle', {
            cx, cy, r: 0.9, fill: '#fff', stroke: '#1d1d1f', 'stroke-width': 0.15, 'stroke-dasharray': 'none',
            'data-indice': i, 'data-poignee': nom, class: 'schema-poignee',
        }, svg);
        if (o.t === 'fleche') {
            poignee(o.x1, o.y1, '1');
            poignee(o.x2, o.y2, '2');
            const points = this.pointsFleche(o);
            points.slice(1, -1).forEach(([x, y], k) => poignee(x, y, `p${k}`));
            if (this.etape > 0) return;
            // Rond « + » au milieu de chaque tronçon : le tirer crée un point de pliage.
            this.milieux(points, o.k === 'brise').forEach(([x, y], k) => this.rondPlus(svg, x, y, i, `m${k}`));
        } else if (o.t === 'zone') {
            this.el('rect', { x: o.x - 0.4, y: o.y - 0.4, width: o.w + 0.8, height: o.h + 0.8 }, g);
            poignee(o.x + o.w, o.y + o.h, 'taille');
        } else {
            this.el('circle', { cx: o.x, cy: o.y, r: o.t === 'but' ? 4.6 : 2.5 }, g);
            // Joueur ou ballon déplacé à cette étape : son trajet se courbe comme une flèche (ronds « + »,
            // points de passage à tirer, double-clic pour en retirer un).
            const entree = this.entreeEtape(i);
            if (entree && MOBILES.includes(o.t)) {
                const points = this.trajetEntre(i, this.etape - 1);
                if (Math.hypot(points[0][0] - o.x, points[0][1] - o.y) >= 0.2) {
                    points.slice(1, -1).forEach(([x, y], k) => poignee(x, y, `w${k}`));
                    this.milieux(points).forEach(([x, y], k) => this.rondPlus(svg, x, y, i, `v${k}`));
                }
            }
        }
    }

    /** Rond « + » à tirer pour plier une flèche ou courber un trajet. */
    rondPlus(svg, x, y, i, nom) {
        const plus = this.el('g', { 'data-indice': i, 'data-poignee': nom, class: 'schema-poignee milieu' }, svg);
        this.el('circle', { cx: x, cy: y, r: 0.75, fill: 'rgba(255,255,255,.75)', stroke: '#1d1d1f', 'stroke-width': 0.1 }, plus);
        this.el('path', { d: `M${x - 0.4},${y} h0.8 M${x},${y - 0.4} v0.8`, stroke: '#1d1d1f', 'stroke-width': 0.14 }, plus);
    }
}
