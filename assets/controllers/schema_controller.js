import { Controller } from '@hotwired/stimulus';
import { confirmer } from '../confirmation.js';

/*
 * Schéma d'exercice : terrain en SVG (unité = 1 mètre) et objets que l'on place et déplace
 * à la souris ou au doigt (joueurs, ballons, cônes, plots, buts, mannequins, flèches, zones, textes).
 * En édition, le schéma est rangé en JSON dans le champ caché du formulaire ;
 * en lecture (lecture: true), il est seulement dessiné à partir de la valeur « schema ».
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
const arrondi = (v) => Math.round(v * 10) / 10;

let instances = 0;

export default class extends Controller {
    static targets = ['svg', 'champ', 'terrain', 'selection', 'nom', 'couleurs', 'texte', 'libelleTexte', 'style', 'tourner', 'annuler'];
    static values = { lecture: Boolean, schema: Object };

    connect() {
        this.id = `schema${++instances}`;
        const source = this.hasChampTarget ? this.lire(this.champTarget.value) : this.schemaValue;
        this.terrain = TERRAINS[source.terrain] ? source.terrain : 'demi';
        this.objets = Array.isArray(source.objets) ? source.objets.filter((o) => o && NOMS[o.t]) : [];
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
        }
        this.dessiner();
    }

    disconnect() {
        if (this.clavier) {
            document.removeEventListener('keydown', this.clavier);
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
        });
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
        this.objets.push(copie);
        this.choisi = this.objets.length - 1;
        this.modifie();
    }

    supprimer() {
        if (this.choisi === null) return;
        this.memoriser();
        this.objets.splice(this.choisi, 1);
        this.choisi = null;
        this.modifie();
    }

    async toutEffacer() {
        if (!this.objets.length || !(await confirmer('Effacer tous les objets du schéma ?', { bouton: 'Tout effacer' }))) return;
        this.memoriser();
        this.objets = [];
        this.choisi = null;
        this.modifie();
    }

    annuler() {
        const etat = this.historique.pop();
        if (!etat) return;
        ({ terrain: this.terrain, objets: this.objets } = JSON.parse(etat));
        this.terrainTarget.value = this.terrain;
        this.choisi = null;
        this.modifie();
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
        this.prise = { poignee: cible.dataset.poignee || null, depart: p, origine: structuredClone(objet), etat: this.etat() };
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
        if (this.prise.poignee === '1') {
            Object.assign(objet, { x1: arrondi(p.x), y1: arrondi(p.y) });
        } else if (this.prise.poignee === '2') {
            Object.assign(objet, { x2: arrondi(p.x), y2: arrondi(p.y) });
        } else if (this.prise.poignee === 'taille') {
            Object.assign(objet, { w: arrondi(Math.max(2, o.w + dx)), h: arrondi(Math.max(2, o.h + dy)) });
        } else if (objet.t === 'fleche') {
            Object.assign(objet, { x1: arrondi(o.x1 + dx), y1: arrondi(o.y1 + dy), x2: arrondi(o.x2 + dx), y2: arrondi(o.y2 + dy) });
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
        return JSON.stringify({ terrain: this.terrain, objets: this.objets });
    }

    memoriser() {
        this.historique.push(this.etat());
        if (this.historique.length > 100) this.historique.shift();
    }

    modifie() {
        this.dessiner();
        this.afficherSelection();
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
        this.tournerTarget.hidden = !TOURNANTS.includes(objet.t);
    }

    // --- Dessin ---

    el(nom, attributs = {}, parent = null) {
        const element = document.createElementNS(NS, nom);
        Object.entries(attributs).forEach(([cle, valeur]) => element.setAttribute(cle, valeur));
        parent?.append(element);
        return element;
    }

    dessiner() {
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
        const calque = this.el('g', {}, svg);
        // Les zones sous les autres objets, pour qu'ils restent saisissables.
        const ordre = this.objets.map((o, i) => i).sort((a, b) => (this.objets[b].t === 'zone') - (this.objets[a].t === 'zone'));
        ordre.forEach((i) => this.dessinerObjet(calque, this.objets[i], i));
        if (this.choisi !== null && this.objets[this.choisi] && !this.lectureValue) {
            this.dessinerSelection(svg, this.objets[this.choisi], this.choisi);
        }
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

    // Trait droit, ou ondulé pour une conduite de balle (la fin reste droite pour la pointe).
    cheminFleche(o) {
        const dx = o.x2 - o.x1;
        const dy = o.y2 - o.y1;
        const longueur = Math.hypot(dx, dy) || 0.01;
        if (o.s !== 'conduite' || longueur < 3) {
            return `M${o.x1},${o.y1} L${o.x2},${o.y2}`;
        }
        const [ux, uy] = [dx / longueur, dy / longueur];
        const points = [];
        for (let s = 0; s <= longueur - 1.2; s += 0.2) {
            const ecart = 0.55 * Math.sin((2 * Math.PI * s) / 2);
            points.push(`${arrondi(o.x1 + ux * s - uy * ecart)},${arrondi(o.y1 + uy * s + ux * ecart)}`);
        }
        return `M${points.join(' L')} L${o.x2},${o.y2}`;
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
        } else if (o.t === 'zone') {
            this.el('rect', { x: o.x - 0.4, y: o.y - 0.4, width: o.w + 0.8, height: o.h + 0.8 }, g);
            poignee(o.x + o.w, o.y + o.h, 'taille');
        } else {
            this.el('circle', { cx: o.x, cy: o.y, r: o.t === 'but' ? 4.6 : 2.5 }, g);
        }
    }
}
