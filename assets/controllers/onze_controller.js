import { Controller } from '@hotwired/stimulus';
import { confirmer } from '../confirmation.js';

/*
 * « Composer le onze » : terrain avec les places de la formation (le poste de chaque place), joueurs disponibles
 * avec leur note à ce poste, remplaçants, absences, comparaison avec les onze des matchs précédents.
 *
 * Au toucher (téléphone, tablette) comme à la souris : toucher une place puis un joueur de la liste (la liste
 * montre d'abord les joueurs de cette ligne, triés par note au poste) ; ou un joueur puis une place.
 * Toucher un joueur sur le terrain le sélectionne : toucher une autre place l'y déplace (échange si elle est prise).
 */
const ETATS = {
    dispo: ['✓', 'Disponible'],
    attente: ['?', 'Sans réponse à la convocation'],
    absent: ['✗', 'Absent'],
    blesse: ['✚', 'Indisponible'],
    suspendu: ['⛔', 'Suspendu'],
};
const LIGNES = { G: 'Gardiens', D: 'Défenseurs', M: 'Milieux', A: 'Attaquants' };
const PAR_DEFAUT = { 11: '4 3 3', 9: '3 3 2', 8: '3 3 1' };
const TERRAIN = `<svg class="terrain-lignes" viewBox="0 0 68 105" preserveAspectRatio="none" aria-hidden="true">
    <g fill="none" stroke="rgba(255,255,255,.85)" stroke-width=".35">
        <rect x="1" y="1" width="66" height="103"/><line x1="1" y1="52.5" x2="67" y2="52.5"/><circle cx="34" cy="52.5" r="9.15"/>
        <rect x="13.84" y="1" width="40.32" height="16.5"/><rect x="24.84" y="1" width="18.32" height="5.5"/>
        <rect x="13.84" y="87.5" width="40.32" height="16.5"/><rect x="24.84" y="98.5" width="18.32" height="5.5"/>
        <path d="M26.7,17.5 A9.15,9.15 0 0 0 41.3,17.5"/><path d="M26.7,87.5 A9.15,9.15 0 0 1 41.3,87.5"/>
    </g></svg>`;

export default class extends Controller {
    static values = { config: Object };
    static targets = ['formation', 'compteur', 'consigne', 'terrain', 'banc', 'nbBanc', 'boutonValider', 'erreur',
        'filtre', 'liste', 'nbAbsences', 'comparaison', 'boutonComparer'];

    connect() {
        const c = this.configValue;
        this.d = c.donnees;
        this.abr = c.abreviations || {};
        this.joueurs = new Map(this.d.joueurs.map((j) => [j.id, j]));
        this.onglet = 'joueurs';
        this.tousJoueurs = !this.d.avecConvocation;
        this.choixPlace = null;
        this.choixJoueur = null;

        const actuel = this.d.actuel;
        this.systeme = this.d.formations[actuel.systeme] ? actuel.systeme : (PAR_DEFAUT[this.d.joueursParEquipe] || '4 4 2');
        this.formationTarget.value = this.systeme;
        this.places = this.d.formations[this.systeme].map((p) => ({ ...p, joueur: null }));
        this.ranger(actuel.titulaires.filter((t) => this.joueurs.has(t.joueur)));
        this.banc = actuel.remplacants.filter((id) => this.joueurs.has(id) && !this.estTitulaire(id));
        this.afficher();
    }

    // ———————— État ————————

    estTitulaire(id) {
        return this.places.some((p) => p.joueur === id);
    }

    /** Joueurs placés (x, y) mis chacun à la place libre la plus proche de la formation (les plus proches d'abord). */
    ranger(placés) {
        const couples = [];
        placés.forEach((t) => this.places.forEach((p, i) => couples.push([Math.hypot(p.x - t.x, (p.y - t.y) * 1.4), t.joueur, i])));
        couples.sort((a, b) => a[0] - b[0]);
        const faits = new Set();
        couples.forEach(([, joueur, i]) => {
            if (faits.has(joueur) || this.places[i].joueur !== null) return;
            this.places[i].joueur = joueur;
            faits.add(joueur);
        });
        return placés.filter((t) => !faits.has(t.joueur)).map((t) => t.joueur);
    }

    changerFormation() {
        const anciens = this.places.filter((p) => p.joueur !== null).map((p) => ({ joueur: p.joueur, x: p.x, y: p.y }));
        this.systeme = this.formationTarget.value;
        this.places = this.d.formations[this.systeme].map((p) => ({ ...p, joueur: null }));
        // Formation plus petite : ceux qui n'ont plus de place passent sur le banc.
        this.ranger(anciens).forEach((id) => { if (!this.banc.includes(id)) this.banc.push(id); });
        this.choixPlace = null;
        this.afficher();
    }

    /** Met le joueur à la place i ; s'il était déjà sur le terrain, échange avec l'occupant. */
    placer(id, i) {
        const cible = this.places[i];
        const depuis = this.places.findIndex((p) => p.joueur === id);
        const occupant = cible.joueur;
        this.banc = this.banc.filter((b) => b !== id);
        if (depuis >= 0) this.places[depuis].joueur = occupant;
        else if (occupant !== null && !this.banc.includes(occupant)) this.banc.push(occupant);
        cible.joueur = id;
        this.choixPlace = null;
        this.choixJoueur = null;
        this.afficher();
    }

    retirer(id) {
        this.places.forEach((p) => { if (p.joueur === id) p.joueur = null; });
        this.banc = this.banc.filter((b) => b !== id);
        this.choixJoueur = null;
        this.afficher();
    }

    /**
     * Valeur d'un joueur à une place : sa note moyenne à ce poste (un peu plus s'il y a joué souvent), sinon sa
     * note aux postes de la même ligne, sinon sa note générale (moins s'il n'est pas de la ligne). Un gardien ne
     * va pas dans le champ, un joueur de champ ne va pas au but (sauf s'il n'y a personne d'autre).
     */
    valeur(j, poste) {
        const ligne = this.ligneDe(poste);
        const general = j.note ?? 5.5;
        const exact = j.notesPostes?.[poste];
        let v;
        if (exact?.note != null) v = exact.note + Math.min(exact.matchs, 5) * 0.06;
        else {
            const memeLigne = Object.entries(j.notesPostes || {}).filter(([p, n]) => this.ligneDe(p) === ligne && n.note != null);
            if (memeLigne.length) v = memeLigne.reduce((s, [, n]) => s + n.note, 0) / memeLigne.length - 0.3;
            else if (j.poste === poste) v = general - 0.2;
            else {
                // Hors de sa ligne : moins on s'en éloigne, mieux c'est (un milieu offensif est presque un attaquant).
                const joues = Object.keys(j.notesPostes || {});
                const sien = j.poste || joues[0];
                const ecart = sien ? Math.abs(this.niveau(sien) - this.niveau(poste)) : 1.5;
                v = general - 0.4 - 0.8 * ecart;
            }
        }
        const gardien = j.ligne === 'G' || Object.keys(j.notesPostes || {}).some((p) => p === 'Gardien');
        if ((ligne === 'G') !== gardien) v -= 6;
        return v;
    }

    /** Remplit le terrain avec les meilleurs joueurs disponibles pour chaque place, puis le banc. */
    async meilleurOnze() {
        const deja = this.places.some((p) => p.joueur !== null) || this.banc.length;
        if (deja && !(await confirmer('Les joueurs déjà placés et le banc seront remplacés par la meilleure composition pour cette formation.', { titre: 'Proposer le meilleur onze ?', bouton: 'Proposer', style: 'principal' }))) return;
        // Joueurs qui peuvent jouer : convoqués (ou l'effectif), ni blessés, ni suspendus, ni absents.
        const dispo = this.d.joueurs.filter((j) => (j.convoque || !this.d.avecConvocation) && !['absent', 'blesse', 'suspendu'].includes(j.etat));
        this.places.forEach((p) => { p.joueur = null; });
        // Meilleures associations joueur – place d'abord ; le gardien en premier pour ne pas le perdre.
        const couples = [];
        dispo.forEach((j) => this.places.forEach((p, i) => couples.push([this.valeur(j, p.poste), j.id, i])));
        couples.sort((a, b) => b[0] - a[0]);
        const pris = new Set();
        couples.forEach(([, id, i]) => {
            if (pris.has(id) || this.places[i].joueur !== null) return;
            this.places[i].joueur = id;
            pris.add(id);
        });
        // Améliorations : échanger deux titulaires, ou un titulaire contre un non-retenu, tant que l'équipe y gagne.
        const val = (id, i) => (id === null ? -99 : this.valeur(this.joueurs.get(id), this.places[i].poste));
        for (let tour = 0, mieux = true; mieux && tour < 50; tour++) {
            mieux = false;
            for (let i = 0; i < this.places.length; i++) {
                for (let k = i + 1; k < this.places.length; k++) {
                    const [a, b] = [this.places[i].joueur, this.places[k].joueur];
                    if (val(b, i) + val(a, k) > val(a, i) + val(b, k) + 0.01) {
                        [this.places[i].joueur, this.places[k].joueur] = [b, a];
                        mieux = true;
                    }
                }
                dispo.filter((j) => !pris.has(j.id)).forEach((j) => {
                    const a = this.places[i].joueur;
                    if (val(j.id, i) > val(a, i) + 0.01) {
                        pris.delete(a);
                        pris.add(j.id);
                        this.places[i].joueur = j.id;
                        mieux = true;
                    }
                });
            }
        }
        // Banc : les meilleurs des autres (7 au plus), un gardien remplaçant d'abord s'il y en a un.
        const reste = dispo.filter((j) => !pris.has(j.id)).sort((a, b) => (b.note ?? 0) - (a.note ?? 0));
        const gardien = reste.find((j) => j.ligne === 'G');
        this.banc = [...(gardien ? [gardien] : []), ...reste.filter((j) => j !== gardien)].slice(0, 7).map((j) => j.id);
        this.choixPlace = null;
        this.choixJoueur = null;
        this.afficher();
        const vides = this.places.filter((p) => p.joueur === null).length;
        this.consigneTarget.textContent = vides
            ? `Proposition faite, mais ${vides} place${vides > 1 ? 's restent vides' : ' reste vide'} : pas assez de joueurs disponibles.`
            : 'Proposition : les meilleurs joueurs disponibles à chaque poste (note de la saison au poste). Ajustez si besoin, puis validez.';
    }

    vider() {
        this.places.forEach((p) => { p.joueur = null; });
        this.banc = [];
        this.choixPlace = null;
        this.choixJoueur = null;
        this.afficher();
    }

    // ———————— Interactions ————————

    toucherPlace(event) {
        const i = Number(event.currentTarget.dataset.place);
        const p = this.places[i];
        if (this.choixJoueur !== null && this.choixJoueur !== p.joueur) return this.placer(this.choixJoueur, i);
        if (p.joueur !== null && this.choixPlace !== i) {
            // Joueur du terrain choisi : toucher une autre place le déplace.
            this.choixJoueur = p.joueur;
            this.choixPlace = i;
        } else {
            this.choixJoueur = null;
            this.choixPlace = this.choixPlace === i ? null : i;
        }
        this.onglet = 'joueurs';
        this.afficher();
    }

    toucherJoueur(event) {
        const id = Number(event.currentTarget.dataset.joueur);
        if (this.choixPlace !== null && this.places[this.choixPlace].joueur !== id) return this.placer(id, this.choixPlace);
        this.choixJoueur = this.choixJoueur === id ? null : id;
        this.choixPlace = null;
        this.afficher();
    }

    basculerBanc(event) {
        event.stopPropagation();
        const id = Number(event.currentTarget.dataset.joueur);
        if (this.banc.includes(id)) this.banc = this.banc.filter((b) => b !== id);
        else {
            this.places.forEach((p) => { if (p.joueur === id) p.joueur = null; });
            this.banc.push(id);
        }
        this.afficher();
    }

    retirerJoueur(event) {
        event.stopPropagation();
        this.retirer(Number(event.currentTarget.dataset.joueur));
    }

    voirOnglet(event) {
        this.onglet = event.currentTarget.dataset.onglet;
        this.afficher();
    }

    voirTous() {
        this.tousJoueurs = !this.tousJoueurs;
        this.afficher();
    }

    basculerComparaison(event) {
        event?.preventDefault();
        this.comparaisonTarget.hidden = !this.comparaisonTarget.hidden;
        if (!this.comparaisonTarget.hidden) {
            this.dessinerComparaison();
            this.comparaisonTarget.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }

    // ———————— Dessin ————————

    afficher() {
        this.dessinerTerrain();
        this.dessinerBanc();
        this.dessinerPanneau();
        if (!this.comparaisonTarget.hidden) this.dessinerComparaison();
        const n = this.places.filter((p) => p.joueur !== null).length;
        this.compteurTarget.textContent = `${n} / ${this.places.length} titulaires`;
        this.compteurTarget.classList.toggle('complet', n === this.places.length);
        const indispos = this.d.joueurs.filter((j) => ['absent', 'blesse', 'suspendu'].includes(j.etat)).length;
        this.nbAbsencesTarget.textContent = indispos || '';
        this.consigneTarget.textContent = this.choixPlace !== null && this.places[this.choixPlace].joueur === null
            ? `Place choisie : ${this.places[this.choixPlace].poste}. Touchez un joueur dans la liste.`
            : (this.choixJoueur !== null ? `${this.nomCourt(this.joueurs.get(this.choixJoueur))} choisi : touchez sa place sur le terrain.`
                : 'Touchez une place du terrain, puis un joueur de la liste (ou l\'inverse).');
    }

    /** Note à afficher pour un joueur à un poste : sa note à ce poste, sinon sa moyenne (et d'où elle vient). */
    noteAuPoste(j, poste) {
        const auPoste = poste ? j.notesPostes?.[poste] : null;
        if (auPoste?.note != null) return { note: auPoste.note, titre: `${auPoste.note} de moyenne au poste (${auPoste.matchs} match${auPoste.matchs > 1 ? 's' : ''})` };
        if (j.note != null) return { note: j.note, titre: `${j.note} de moyenne tous postes (${j.matchs} match${j.matchs > 1 ? 's' : ''})${poste ? ', jamais noté à ce poste' : ''}` };
        return null;
    }

    pastilleNote(n, petite = false) {
        if (!n) return '';
        const classe = n.note >= 7 ? 'haute' : (n.note < 5 ? 'basse' : 'moyenne');
        return `<span class="onze-note ${classe}${petite ? ' petite' : ''}" title="${this.echapper(n.titre)}">${String(n.note).replace('.', ',')}</span>`;
    }

    dessinerTerrain() {
        const pions = this.places.map((p, i) => {
            const j = p.joueur !== null ? this.joueurs.get(p.joueur) : null;
            const choisie = this.choixPlace === i;
            const abr = this.abr[p.poste] || '';
            if (!j) {
                return `<button type="button" class="onze-place vide${choisie ? ' choisie' : ''}" style="left:${p.x}%;top:${100 - p.y}%" data-place="${i}" data-action="onze#toucherPlace" title="${this.echapper(p.poste)} : choisir un joueur">
                    <span class="onze-maillot">${abr}</span></button>`;
            }
            const alerte = j.etat !== 'dispo' && j.etat !== 'attente';
            return `<button type="button" class="onze-place${choisie ? ' choisie' : ''}${alerte ? ' alerte' : ''}" style="left:${p.x}%;top:${100 - p.y}%" data-place="${i}" data-action="onze#toucherPlace" title="${this.echapper(`${j.prenom} ${j.nom} – ${p.poste}${j.libelleEtat ? ` – ${j.libelleEtat}` : ''}`)}">
                <span class="onze-maillot${p.poste === 'Gardien' ? ' gardien' : ''}">${j.numero ?? abr}</span>
                <span class="onze-nom">${this.echapper(j.nom)}</span>${this.pastilleNote(this.noteAuPoste(j, p.poste), true)}${alerte ? '<span class="onze-alerte">!</span>' : ''}</button>`;
        }).join('');
        this.terrainTarget.innerHTML = TERRAIN + pions;
    }

    dessinerBanc() {
        this.nbBancTarget.textContent = this.banc.length;
        this.bancTarget.innerHTML = this.banc.map((id) => {
            const j = this.joueurs.get(id);
            return `<button type="button" class="onze-remplacant${this.choixJoueur === id ? ' choisi' : ''}" data-joueur="${id}" data-action="onze#toucherJoueur" title="Toucher puis une place pour le faire titulaire">
                <span class="onze-maillot petit">${j.numero ?? '–'}</span><span>${this.echapper(this.nomCourt(j))}</span>${this.pastilleNote(this.noteAuPoste(j, null), true)}
                <span class="onze-retirer" data-joueur="${id}" data-action="click->onze#retirerJoueur" title="Retirer du banc" role="button">✕</span></button>`;
        }).join('') || '<span class="discret petit">Ajoutez des remplaçants depuis la liste (bouton « Banc »).</span>';
    }

    dessinerPanneau() {
        this.element.querySelectorAll('.onze-onglets button').forEach((b) => b.classList.toggle('actif', b.dataset.onglet === this.onglet));
        if (this.onglet === 'stats') return this.dessinerStats();
        if (this.onglet === 'absences') return this.dessinerAbsences();
        this.dessinerJoueurs();
    }

    joueursVisibles() {
        return this.d.joueurs.filter((j) => this.tousJoueurs || j.convoque || this.estTitulaire(j.id) || this.banc.includes(j.id));
    }

    dessinerJoueurs() {
        const place = this.choixPlace !== null ? this.places[this.choixPlace] : null;
        const ligne = place ? this.ligneDe(place.poste) : null;
        const rang = { dispo: 0, attente: 1, suspendu: 2, blesse: 3, absent: 4 };
        const liste = [...this.joueursVisibles()].sort((a, b) => {
            if (ligne) {
                const la = a.ligne === ligne ? 0 : 1;
                const lb = b.ligne === ligne ? 0 : 1;
                if (la !== lb) return la - lb;
            }
            if (rang[a.etat] !== rang[b.etat]) return rang[a.etat] - rang[b.etat];
            const na = this.noteAuPoste(a, place?.poste)?.note ?? -1;
            const nb = this.noteAuPoste(b, place?.poste)?.note ?? -1;
            return nb - na || a.nom.localeCompare(b.nom);
        });
        this.filtreTarget.innerHTML = (place
            ? `<p class="onze-pour">Pour la place <strong>${this.echapper(place.poste)}</strong> : ${LIGNES[ligne] ? `${LIGNES[ligne].toLowerCase()} d'abord, ` : ''}triés par note au poste.</p>`
            : '')
            + (this.d.avecConvocation ? `<label class="onze-tous"><input type="checkbox" ${this.tousJoueurs ? 'checked' : ''} data-action="onze#voirTous"> Voir aussi les joueurs non convoqués</label>` : '');
        this.listeTarget.innerHTML = `<ul class="onze-joueurs">${liste.map((j) => {
            const titulaire = this.estTitulaire(j.id);
            const auBanc = this.banc.includes(j.id);
            const [icone, libelle] = ETATS[j.etat === 'dispo' && j.reponse === null && this.d.avecConvocation && j.convoque ? 'attente' : j.etat] || ETATS.dispo;
            const etat = j.etat === 'dispo' && j.reponse === null && this.d.avecConvocation && j.convoque ? 'attente' : j.etat;
            // Titulaire : le poste où il est placé (le même que sur le terrain), puis son poste habituel s'il diffère.
            const placeJoueur = this.places.find((p) => p.joueur === j.id);
            const habituel = j.poste || 'poste non renseigné';
            const postes = placeJoueur
                ? `Placé ${this.abr[placeJoueur.poste] || ''} – ${placeJoueur.poste}${j.poste !== placeJoueur.poste ? ` · habituellement ${habituel}` : ''}`
                : (j.poste || 'Poste non renseigné');
            return `<li class="onze-joueur${this.choixJoueur === j.id ? ' choisi' : ''}${titulaire ? ' titulaire' : ''}${['absent', 'blesse', 'suspendu'].includes(etat) ? ' indispo' : ''}" data-joueur="${j.id}" data-action="click->onze#toucherJoueur" role="button" tabindex="0">
                ${j.photo ? `<img class="avatar mini" src="${j.photo}" alt="" loading="lazy">` : `<span class="avatar mini">${this.echapper((j.prenom[0] || '') + (j.nom[0] || ''))}</span>`}
                <span class="onze-qui"><b>${this.echapper(j.nom)}</b> ${this.echapper(j.prenom)}<small${placeJoueur ? ' class="place"' : ''}>${this.echapper(postes)}${!j.convoque ? ' · non convoqué' : ''}${j.libelleEtat ? ` · ${this.echapper(j.libelleEtat)}` : ''}</small></span>
                ${this.pastilleNote(this.noteAuPoste(j, placeJoueur?.poste ?? place?.poste))}
                <span class="onze-etat ${etat}" title="${this.echapper(j.libelleEtat || libelle)}">${icone}</span>
                ${titulaire ? `<span class="pastille succes">titulaire</span><button type="button" class="lien-bouton petit" data-joueur="${j.id}" data-action="onze#retirerJoueur">Retirer</button>`
                    : `<button type="button" class="bouton petit${auBanc ? ' actif' : ''}" data-joueur="${j.id}" data-action="onze#basculerBanc">${auBanc ? '✓ Banc' : 'Banc'}</button>`}
            </li>`;
        }).join('')}</ul>${liste.length ? '' : '<p class="discret">Aucun joueur : convoquez l\'équipe ou ajoutez des joueurs à la catégorie.</p>'}`;
    }

    dessinerStats() {
        this.filtreTarget.innerHTML = '<p class="discret petit">Saison en cours, jusqu\'à ce match. Note : moyenne des notes de l\'éducateur.</p>';
        const liste = [...this.joueursVisibles()].sort((a, b) => b.matchs - a.matchs || (b.note ?? 0) - (a.note ?? 0));
        this.listeTarget.innerHTML = `<div class="table-defile"><table class="liste onze-stats"><thead><tr><th>Joueur</th><th>Matchs</th><th>Min.</th><th>Buts</th><th>Passes D.</th><th>Note</th><th>Meilleur poste</th></tr></thead><tbody>
            ${liste.map((j) => {
        const meilleur = Object.entries(j.notesPostes || {}).filter(([, p]) => p.note != null).sort((a, b) => b[1].note - a[1].note)[0];
        return `<tr><td class="principal">${this.echapper(this.nomCourt(j))}</td><td>${j.matchs}</td><td>${j.minutes}</td><td>${j.buts}</td><td>${j.passes}</td>
                <td>${this.pastilleNote(this.noteAuPoste(j, null), true)}</td><td class="petit">${meilleur ? `${this.echapper(meilleur[0])} (${String(meilleur[1].note).replace('.', ',')})` : '—'}</td></tr>`;
    }).join('')}</tbody></table></div>`;
    }

    dessinerAbsences() {
        this.filtreTarget.innerHTML = '';
        const groupes = [
            ['blesse', 'Blessés et indisponibles'], ['suspendu', 'Suspendus'], ['absent', 'Absents à la convocation'],
        ];
        const sansReponse = this.d.avecConvocation ? this.d.joueurs.filter((j) => j.convoque && j.etat === 'dispo' && j.reponse === null) : [];
        const blocs = groupes.map(([etat, titre]) => {
            const liste = this.d.joueurs.filter((j) => j.etat === etat);
            return liste.length ? `<h3>${titre} <span class="compteur">${liste.length}</span></h3><ul class="liste-simple">${liste.map((j) => `<li><b>${this.echapper(this.nomCourt(j))}</b> <span class="discret petit">${this.echapper(j.libelleEtat || '')}</span>${this.estTitulaire(j.id) ? ' <span class="pastille alerte">placé dans le onze</span>' : ''}</li>`).join('')}</ul>` : '';
        }).join('');
        const attente = sansReponse.length ? `<h3>Sans réponse à la convocation <span class="compteur">${sansReponse.length}</span></h3><ul class="liste-simple">${sansReponse.map((j) => `<li>${this.echapper(this.nomCourt(j))}</li>`).join('')}</ul>` : '';
        this.listeTarget.innerHTML = blocs + attente || '<p class="discret">Aucune absence connue : pas de blessé, de suspendu ni d\'absent à la convocation.</p>';
    }

    dessinerComparaison() {
        const onze = new Set(this.places.map((p) => p.joueur).filter((id) => id !== null));
        const prec = this.d.precedents;
        if (!prec.length) {
            this.comparaisonTarget.innerHTML = '<h2>Comparer les compositions</h2><p class="discret">Pas encore de match joué avec une feuille de match cette saison.</p>';
            return;
        }
        // Bilan par organisation.
        const parSysteme = {};
        prec.forEach((m) => {
            if (!parSysteme[m.systeme]) parSysteme[m.systeme] = { matchs: 0, V: 0, N: 0, D: 0 };
            const s = parSysteme[m.systeme];
            s.matchs += 1;
            if (m.resultat) s[m.resultat] += 1;
        });
        const lignesSysteme = Object.entries(parSysteme).sort((a, b) => b[1].matchs - a[1].matchs).map(([s, b]) => {
            const joues = b.V + b.N + b.D;
            const pts = joues ? ((3 * b.V + b.N) / joues).toFixed(1).replace('.', ',') : '—';
            return `<tr${s === this.systeme ? ' class="choisie"' : ''}><td class="principal">${this.echapper(s)}${s === this.systeme ? ' <span class="pastille">choisie</span>' : ''}</td><td>${b.matchs}</td><td><span class="score resultat-V">${b.V}</span> <span class="score resultat-N">${b.N}</span> <span class="score resultat-D">${b.D}</span></td><td>${pts}</td></tr>`;
        }).join('');
        // Stabilité : joueurs gardés d'un match au suivant.
        const communs = (a, b) => a.titulaires.filter((id) => b.titulaires.includes(id)).length;
        const suites = prec.slice(0, -1).map((m, i) => communs(m, prec[i + 1]));
        const stabilite = suites.length ? (suites.reduce((s, n) => s + n, 0) / suites.length).toFixed(1).replace('.', ',') : null;
        const gardes = prec[0] ? prec[0].titulaires.filter((id) => onze.has(id)).length : 0;
        const lignesMatchs = prec.slice(0, 10).map((m) => {
            const n = m.titulaires.filter((id) => onze.has(id)).length;
            return `<tr><td>${m.date}</td><td class="principal">${m.domicile ? '' : 'à '}${this.echapper(m.adversaire || '')}</td><td>${this.echapper(m.systeme)}</td>
                <td>${m.score ? `<span class="score resultat-${m.resultat}">${m.score}</span>` : '—'}</td>
                <td><span class="onze-commun"><span style="width:${Math.round((100 * n) / Math.max(1, m.titulaires.length))}%"></span></span> ${n} / ${m.titulaires.length}</td></tr>`;
        }).join('');
        this.comparaisonTarget.innerHTML = `<h2>Comparer les compositions</h2>
            <div class="tuiles bilan">
                <div class="tuile"><span class="tuile-valeur">${gardes} / ${onze.size || 0}</span><span class="tuile-libelle">joueurs de votre onze déjà titulaires au dernier match (${prec[0].date})</span></div>
                ${stabilite ? `<div class="tuile"><span class="tuile-valeur">${stabilite}</span><span class="tuile-libelle">titulaires gardés en moyenne d'un match au suivant (stabilité de l'équipe)</span></div>` : ''}
            </div>
            <h3>Résultats par organisation</h3>
            <div class="table-defile"><table class="liste"><thead><tr><th>Organisation</th><th>Matchs</th><th>V / N / D</th><th>Points par match</th></tr></thead><tbody>${lignesSysteme}</tbody></table></div>
            <h3>Derniers onze de départ</h3>
            <div class="table-defile"><table class="liste"><thead><tr><th>Date</th><th>Adversaire</th><th>Organisation</th><th>Score</th><th>En commun avec votre onze</th></tr></thead><tbody>${lignesMatchs}</tbody></table></div>`;
    }

    // ———————— Validation ————————

    async valider() {
        const titulaires = this.places.filter((p) => p.joueur !== null);
        if (!titulaires.length) return this.montrerErreur('Placez au moins un joueur sur le terrain.');
        const absents = [...titulaires.map((p) => p.joueur), ...this.banc].map((id) => this.joueurs.get(id)).filter((j) => ['absent', 'blesse', 'suspendu'].includes(j.etat));
        const manque = this.places.length - titulaires.length;
        const avertissements = [
            manque ? `${manque} place${manque > 1 ? 's' : ''} encore vide${manque > 1 ? 's' : ''}.` : null,
            absents.length ? `Choisi${absents.length > 1 ? 's' : ''} malgré l'absence : ${absents.map((j) => `${this.nomCourt(j)} (${j.libelleEtat})`).join(', ')}.` : null,
        ].filter(Boolean);
        if (avertissements.length && !(await confirmer(avertissements.join(' '), { titre: 'Valider ce onze ?', bouton: 'Valider quand même', style: 'principal' }))) return;
        this.boutonValiderTarget.disabled = true;
        try {
            const reponse = await fetch(this.configValue.adresse, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({
                    _token: this.configValue.jeton,
                    systeme: this.systeme,
                    titulaires: titulaires.map((p) => ({ joueur: p.joueur, x: p.x, y: p.y, poste: p.poste })),
                    remplacants: this.banc,
                }),
            });
            const resultat = await reponse.json().catch(() => ({}));
            if (!reponse.ok) throw new Error(resultat.erreur || 'Enregistrement impossible.');
            window.location.href = resultat.retour;
        } catch (e) {
            this.montrerErreur(e.message === 'Failed to fetch' ? 'Pas de réseau : réessayez dans un instant.' : e.message);
            this.boutonValiderTarget.disabled = false;
        }
    }

    montrerErreur(message) {
        this.erreurTarget.textContent = message;
        this.erreurTarget.hidden = false;
    }

    // ———————— Outils ————————

    ligneDe(poste) {
        if (!poste) return null;
        if (poste.startsWith('Gardien')) return 'G';
        if (poste.startsWith('Latéral') || poste.startsWith('Défenseur')) return 'D';
        if (poste.startsWith('Milieu')) return 'M';
        return poste.startsWith('Attaquant') ? 'A' : null;
    }

    /** Profondeur du poste, du but adverse au nôtre : gardien 0, défense 1, milieu 2, milieu offensif 2,5, attaque 3. */
    niveau(poste) {
        if (/^Milieu (offensif|excentré)/.test(poste)) return 2.5;
        return { G: 0, D: 1, M: 2, A: 3 }[this.ligneDe(poste)] ?? 2;
    }

    nomCourt(j) {
        return j ? `${j.prenom} ${j.nom}` : '';
    }

    echapper(texte) {
        return String(texte ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }
}
