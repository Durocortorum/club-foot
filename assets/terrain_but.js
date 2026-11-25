/*
 * Fenêtre « But ! » : demi-terrain vu de dessus (en mètres, terrain de 105 × 68) avec le but et ses poteaux,
 * le ballon au départ du tir et la flèche jusqu'au point d'entrée dans le but, déplaçables au doigt ou à la souris.
 * Utilisée par le match en direct (direct_controller.js) et la feuille de match (trace_but_controller.js).
 *
 * but : { droite (vrai : nos buts, vers le but adverse à droite), depart: [x, y], arrivee: [x, y], vue }.
 */

/** Départ par défaut (milieu d'une zone du direct, ou devant le but) et point d'entrée au centre du but. */
export function preparerBut(droite, depart = null, arrivee = null, situation = null, coupFranc = null) {
    const but = {
        droite,
        situation,
        depart: depart ? [...depart] : [droite ? 94 : 11, 34],
        arrivee: arrivee ? [...arrivee] : [droite ? 105 : 0, 34],
    };
    // Coup franc indirect : endroit d'où il est tiré (déplaçable), relié à la reprise du buteur.
    if (situation === 'cfi') but.coupFranc = coupFranc ? [...coupFranc] : [droite ? 80 : 25, 18];
    // Vue resserrée devant le but (le but paraît plus grand), élargie juste assez pour montrer le départ du tir
    // (et le drapeau d'où part un corner, l'endroit du coup franc).
    const points = [but.depart, ...(but.coupFranc ? [but.coupFranc] : [])];
    const xs = points.map((p) => p[0]);
    const ys = points.map((p) => p[1]);
    const coin = situation === 'corner' || situation === 'cod';
    const y0 = coin ? -2 : Math.max(-2, Math.min(10, Math.min(...ys) - 6));
    const y1 = coin ? 70 : Math.min(70, Math.max(58, Math.max(...ys) + 6));
    but.vue = droite
        ? [Math.max(50.5, Math.min(78, Math.min(...xs) - 6)), y0, 108.5, y1]
        : [-3.5, y0, Math.min(54.5, Math.max(27, Math.max(...xs) + 6)), y1];
    return but;
}

export function dessinerBut(svg, b) {
    const droite = b.droite;
    const bx = droite ? 105 : 0;
    const s = droite ? -1 : 1;
    const couleur = droite ? '#d62839' : '#1f5fd1';
    const [vx0, vy0, vx1, vy1] = b.vue;
    svg.setAttribute('viewBox', `${vx0} ${vy0} ${vx1 - vx0} ${vy1 - vy0}`);
    const ligne = 'fill="none" stroke="rgba(255,255,255,.85)" stroke-width="0.3"';
    svg.innerHTML = `
        <defs><marker id="pointe-saisie-but" viewBox="0 0 4 4" refX="3.2" refY="2" markerWidth="3" markerHeight="3" orient="auto"><path d="M0,0 L4,2 L0,4 z" fill="${couleur}"/></marker>
        <pattern id="filet-saisie" width="0.8" height="0.8" patternUnits="userSpaceOnUse"><rect width="0.8" height="0.8" fill="#e6e9ee"/><path d="M0,0 L0.8,0.8 M0.8,0 L0,0.8" stroke="#8d939c" stroke-width="0.08"/></pattern></defs>
        <rect x="${vx0}" y="${vy0}" width="${vx1 - vx0}" height="${vy1 - vy0}" fill="#3a8a46"/>
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
        ${b.situation === 'corner' || b.situation === 'cod' ? coinDuCorner(b, bx, s, couleur) : ''}
        ${b.coupFranc ? `<line x1="${b.coupFranc[0]}" y1="${b.coupFranc[1]}" x2="${b.depart[0]}" y2="${b.depart[1]}" stroke="#fff" stroke-width="0.35" stroke-dasharray="1 0.8" opacity="0.9"/>
        <g class="poignee-but" data-poignee="coupFranc">
            <circle cx="${b.coupFranc[0]}" cy="${b.coupFranc[1]}" r="2.4" fill="transparent"/>
            <circle cx="${b.coupFranc[0]}" cy="${b.coupFranc[1]}" r="1.5" fill="${couleur}" stroke="#fff" stroke-width="0.25"/>
            <text x="${b.coupFranc[0]}" y="${b.coupFranc[1] + 0.55}" text-anchor="middle" font-size="1.5" font-weight="800" fill="#fff">CF</text>
        </g>` : ''}
        <line x1="${b.depart[0]}" y1="${b.depart[1]}" x2="${b.arrivee[0]}" y2="${b.arrivee[1]}" stroke="${couleur}" stroke-width="0.5" marker-end="url(#pointe-saisie-but)"/>
        <circle class="poignee-but" data-poignee="arrivee" cx="${b.arrivee[0]}" cy="${b.arrivee[1]}" r="1.6" fill="rgba(255,255,255,.35)" stroke="${couleur}" stroke-width="0.25"/>
        <g class="poignee-but" data-poignee="depart">
            <circle cx="${b.depart[0]}" cy="${b.depart[1]}" r="2.2" fill="transparent"/>
            <circle cx="${b.depart[0]}" cy="${b.depart[1]}" r="1.1" fill="#fff" stroke="#1d1d1f" stroke-width="0.2"/>
            <path d="M${b.depart[0]},${b.depart[1] - 0.45} l0.43,0.31 l-0.16,0.5 h-0.54 l-0.16,-0.5 z" fill="#1d1d1f"/>
        </g>`;
}

/** Corner : drapeau au coin le plus proche du ballon et centre en pointillés jusqu'au départ du tir. */
function coinDuCorner(b, bx, s, couleur) {
    const cy = b.depart[1] < 34 ? 0 : 68;
    const haut = cy ? cy - 3.2 : 3.2;
    const pente = cy ? 0.8 : -0.8;
    // Corner direct : le ballon part du drapeau, pas de centre à dessiner.
    return `${b.situation === 'corner' ? `<line x1="${bx}" y1="${cy}" x2="${b.depart[0]}" y2="${b.depart[1]}" stroke="#fff" stroke-width="0.35" stroke-dasharray="1 0.8" opacity="0.9"/>` : ''}
        <path d="M${bx},${cy} V${haut}" stroke="#1d1d1f" stroke-width="0.3"/>
        <path d="M${bx},${haut} l${s * 2.4},${pente} l${-s * 2.4},${pente} z" fill="${couleur}" stroke="#fff" stroke-width="0.15"/>`;
}

/** Départ du tir par défaut selon la situation du but (dans le sens où l'on attaque ce but). */
export function departDeSituation(droite, situation) {
    const [x, y] = { corner: [5.5, 30], cod: [0.6, 0.8], cfd: [22, 28], cfi: [8, 37], penalty: [11, 34] }[situation] ?? [13, 29];
    return [droite ? 105 - x : x, y];
}

/** Ballon et pointe de la flèche déplaçables ; à appeler une fois par terrain. lire() renvoie le but en cours. */
export function ecouterBut(svg, lire) {
    let prise = null;
    svg.addEventListener('pointerdown', (e) => {
        const p = e.target.closest('[data-poignee]');
        if (!p || !lire()) return;
        e.preventDefault();
        prise = p.dataset.poignee;
        svg.setPointerCapture?.(e.pointerId);
    });
    svg.addEventListener('pointermove', (e) => {
        const b = lire();
        if (!prise || !b) return;
        const q = new DOMPoint(e.clientX, e.clientY).matrixTransform(svg.getScreenCTM().inverse());
        // Le coup franc se tire sur le terrain (pas dans le but).
        b[prise] = prise === 'coupFranc' ? [Math.min(105, Math.max(0, q.x)), Math.min(68, Math.max(0, q.y))]
            : [Math.min(108, Math.max(-3, q.x)), Math.min(68, Math.max(0, q.y))];
        dessinerBut(svg, b);
    });
    const lacher = () => { prise = null; };
    svg.addEventListener('pointerup', lacher);
    svg.addEventListener('pointercancel', lacher);
}

/** Points arrondis au décimètre, pour l'enregistrement. */
export function arrondir(point) {
    return point.map((v) => Math.round(v * 10) / 10);
}
