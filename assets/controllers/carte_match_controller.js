import { Controller } from '@hotwired/stimulus';
import { rondsHtml } from '../terrain_point.js';

/*
 * Carte du terrain des statistiques du match : même terrain vert que le Match Live (nous attaquons vers la droite).
 * - « Endroits » : un rond de la couleur de l'action là où elle a eu lieu, un ballon pour les buts, un drapeau avec
 *   le nombre de corners dans chaque coin.
 * - « Zones » : le nombre d'actions de chacune des 18 zones (rouge pour nous, bleu pour l'adversaire), utile pour les
 *   actions notées sans endroit précis.
 * Boutons : tout le match, notre équipe, l'adversaire, chaque joueur ; la légende se touche pour ne garder qu'un type.
 */
const BUTS = ['B', 'BA'];
const CORNERS = ['CO', 'CA'];

export default class extends Controller {
    static values = { donnees: Object };

    static targets = ['filtres', 'terrain', 'legende', 'boutonMode'];

    connect() {
        const choisi = this.donneesValue.joueur;
        this.filtre = { qui: choisi && this.donneesValue.actions.some((a) => a.joueur === choisi) ? choisi : 'tous', type: null };
        // Au départ : les endroits précis s'il y en a, sinon les zones.
        this.mode = this.utiles('points').length ? 'points' : 'zones';
        this.dessiner();
    }

    /** Actions dessinables dans ce mode (la frappe ajoutée avec un but n'est pas dessinée en double). */
    utiles(mode) {
        return this.donneesValue.actions.filter((a) => !a.avecBut
            && (mode === 'points' ? a.details.point : (CORNERS.includes(a.type) || a.zx !== null)));
    }

    duFiltre(a) {
        const q = this.filtre.qui;
        return q === 'tous' || (q === 'nous' && !a.eux) || (q === 'eux' && a.eux) || (typeof q === 'number' && a.joueur === q);
    }

    dessiner() {
        const utiles = this.utiles(this.mode);
        const choisies = utiles.filter((a) => this.duFiltre(a));
        const montrees = choisies.filter((a) => !this.filtre.type || a.type === this.filtre.type);
        const zones = this.terrainTarget.querySelector('.zones');
        if (this.mode === 'points') {
            zones.innerHTML = rondsHtml(montrees.map((a) => ({ ...a, libelle: a.libelle })), false, 1, (t) => this.echapper(t)).replace(/ dernier"/, '"');
        } else {
            // Corners : leurs drapeaux ; le reste compté par zone.
            zones.innerHTML = this.zonesHtml(montrees.filter((a) => !CORNERS.includes(a.type)))
                + rondsHtml(montrees.filter((a) => CORNERS.includes(a.type)), false, 1, (t) => this.echapper(t));
        }
        this.terrainTarget.classList.toggle('mode-zones', this.mode === 'zones');
        this.boutonModeTargets.forEach((b) => {
            b.classList.toggle('actif', b.dataset.mode === this.mode);
            b.setAttribute('aria-pressed', b.dataset.mode === this.mode);
        });
        this.dessinerFiltres(utiles);
        this.dessinerLegende(choisies);
    }

    /** 18 zones ; pour tout le match, chaque zone coupée en deux : nous en haut, l'adversaire en bas. */
    zonesHtml(actions) {
        const compte = (eux) => {
            const c = Array.from({ length: 6 }, () => [0, 0, 0]);
            actions.filter((a) => a.eux === eux && a.zx !== null).forEach((a) => { c[a.zx][a.zy] += 1; });
            return c;
        };
        const nous = compte(false);
        const eux = compte(true);
        const max = Math.max(1, ...nous.flat(), ...eux.flat());
        const deux = nous.flat().some(Boolean) && eux.flat().some(Boolean);
        let html = '';
        for (let zx = 0; zx < 6; zx += 1) {
            for (let zy = 0; zy < 3; zy += 1) {
                const a = nous[zx][zy];
                const b = eux[zx][zy];
                const demi = (n, camp) => `<span class="demi ${camp}" style="--intensite:${n ? (0.35 + 0.65 * n / max).toFixed(2) : 0}">${n || ''}</span>`;
                html += `<span class="zone${deux ? ' partagee' : ''}" style="grid-row:${zy + 1};grid-column:${zx + 1}" title="${this.echapper(this.donneesValue.nous)} : ${a} · ${this.echapper(this.donneesValue.adversaire)} : ${b}">`
                    + (deux ? demi(a, 'nous') + demi(b, 'eux') : demi(a || b, b > a ? 'eux' : 'nous'))
                    + '</span>';
            }
        }
        return html;
    }

    dessinerFiltres(utiles) {
        const f = this.filtre;
        const d = this.donneesValue;
        const bouton = (qui, texte, n, classe = '') => `<button type="button" class="filtre-terrain${classe}${f.qui === qui ? ' actif' : ''}" data-qui="${qui}" data-action="carte-match#filtrerQui" aria-pressed="${f.qui === qui}">${texte} <b>${n}</b></button>`;
        const parJoueur = new Map();
        utiles.filter((a) => a.joueur && !a.eux).forEach((a) => parJoueur.set(a.joueur, (parJoueur.get(a.joueur) ?? 0) + 1));
        const joueur = (id) => d.joueurs.find((j) => j.id === id);
        const joueurs = [...parJoueur].map(([id, n]) => ({ j: joueur(id), id, n })).sort((x, y) => (x.j?.numero ?? 99) - (y.j?.numero ?? 99));
        this.filtresTarget.innerHTML = `<div class="filtres-equipes">${bouton('tous', 'Tout le match', utiles.length)}${bouton('nous', this.echapper(d.nous), utiles.filter((a) => !a.eux).length, ' nous')}${bouton('eux', this.echapper(d.adversaire), utiles.filter((a) => a.eux).length, ' eux')}</div>`
            + (joueurs.length ? `<div class="filtres-joueurs">${joueurs.map(({ j, id, n }) => bouton(id, j ? `<span class="num">${j.numero ?? ''}</span>${this.echapper(`${j.prenom} ${j.nom}`)}` : 'Joueur', n)).join('')}</div>` : '');
    }

    dessinerLegende(choisies) {
        const f = this.filtre;
        const parType = new Map();
        choisies.forEach((a) => parType.set(a.type, (parType.get(a.type) ?? 0) + 1));
        const icone = (type) => {
            if (BUTS.includes(type)) return `<span class="but-terrain legende ${type === 'B' ? 'nous' : 'eux'}">⚽</span>`;
            if (CORNERS.includes(type)) return `<span class="corner-legende ${type === 'CO' ? 'nous' : 'eux'}"><svg class="drapeau-mini" viewBox="0 0 10 12" aria-hidden="true"><path d="M1.5 0.5v11" stroke="currentColor" stroke-width="1.4"/><path d="M2 1h7l-2 2.5 2 2.5H2z" fill="currentColor"/></svg></span>`;
            return this.mode === 'points' ? `<span class="rond-action action-${type}"></span>` : `<span class="pastille-carte ${choisies.find((a) => a.type === type)?.eux ? 'eux' : 'nous'}"></span>`;
        };
        // Actions sans endroit précis (notées par zone) : visibles avec « Zones ».
        const sansEndroit = this.mode === 'points'
            ? this.utiles('zones').filter((a) => !a.details.point && this.duFiltre(a)).length : 0;
        this.legendeTarget.innerHTML = (parType.size
            ? [...parType].sort((a, b) => b[1] - a[1]).map(([type, n]) => `<button type="button" class="legende-rond${f.type === type ? ' actif' : ''}${f.type && f.type !== type ? ' estompe' : ''}" data-type="${type}" data-action="carte-match#filtrerType" aria-pressed="${f.type === type}" title="${f.type === type ? 'Revoir toutes les actions' : 'Ne montrer que cette action'}">${icone(type)}${this.echapper(this.donneesValue.types[type] ?? type)} <b>${n}</b></button>`).join('')
            : 'Aucune action à montrer pour ce choix.')
            + (sansEndroit ? `<span class="discret">+ ${sansEndroit} action${sansEndroit > 1 ? 's' : ''} sans endroit précis (voir « Zones »)</span>` : '');
    }

    filtrerQui(event) {
        const q = event.currentTarget.dataset.qui;
        this.filtre = { qui: ['tous', 'nous', 'eux'].includes(q) ? q : Number(q), type: null };
        this.dessiner();
    }

    filtrerType(event) {
        const { type } = event.currentTarget.dataset;
        this.filtre = { ...this.filtre, type: this.filtre.type === type ? null : type };
        this.dessiner();
    }

    choisirMode(event) {
        this.mode = event.currentTarget.dataset.mode;
        this.dessiner();
    }

    echapper(texte) {
        return String(texte ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    }
}
