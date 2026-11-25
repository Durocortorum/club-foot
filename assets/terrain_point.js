/*
 * Point précis d'une action sur le terrain (Match Live, saisie détaillée) : l'endroit touché, converti en mètres
 * dans le sens de notre attaque (x : 0 = notre ligne de but → 105 = but adverse ; y : 0 = notre gauche → 68 = notre
 * droite), quel que soit le côté où l'on attaque et l'orientation de l'écran ; et l'inverse pour dessiner le rond.
 * La zone (6 bandes × 3 couloirs) se déduit du point, pour les statistiques par zone.
 */
export const LONGUEUR = 105;
export const LARGEUR = 68;

/** Petit drapeau de corner, de la couleur du texte (le nombre de corners). */
const DRAPEAU = '<svg class="drapeau-mini" viewBox="0 0 10 12" aria-hidden="true"><path d="M1.5 0.5v11" stroke="currentColor" stroke-width="1.4"/><path d="M2 1h7l-2 2.5 2 2.5H2z" fill="currentColor"/></svg>';

const borne =(v, max) => Math.max(0, Math.min(max, v));

/** Mètres dans le sens de l'attaque à partir d'un toucher sur l'élément du terrain. */
export function pointTouche(event, element, vertical, sens) {
    const r = element.getBoundingClientRect();
    const fx = borne((event.clientX - r.left) / r.width, 1);
    const fy = borne((event.clientY - r.top) / r.height, 1);
    let x;
    let y;
    if (vertical) {
        x = sens > 0 ? 1 - fy : fy;
        y = sens > 0 ? fx : 1 - fx;
    } else {
        x = sens > 0 ? fx : 1 - fx;
        y = sens > 0 ? fy : 1 - fy;
    }
    return [Math.round(x * LONGUEUR * 10) / 10, Math.round(y * LARGEUR * 10) / 10];
}

/** Place à l'écran (en %, gauche et haut) d'un point en mètres dans le sens de l'attaque. */
export function placeEcran([x, y], vertical, sens) {
    const fx = x / LONGUEUR;
    const fy = y / LARGEUR;
    if (vertical) {
        return { gauche: (sens > 0 ? fy : 1 - fy) * 100, haut: (sens > 0 ? 1 - fx : fx) * 100 };
    }
    return { gauche: (sens > 0 ? fx : 1 - fx) * 100, haut: (sens > 0 ? fy : 1 - fy) * 100 };
}

/** Zone (bande zx de 0 à 5, couloir zy de 0 à 2) qui contient le point. */
export function zoneDuPoint([x, y]) {
    return { zx: Math.min(5, Math.max(0, Math.floor(x / 17.5))), zy: Math.min(2, Math.max(0, Math.floor(y / (LARGEUR / 3)))) };
}

/**
 * Ronds des actions déjà placées (les plus récentes en dernier, la dernière mise en valeur). Les corners, tirés au
 * ras du coin, sont regroupés par coin : un drapeau avec leur nombre, un peu à l'intérieur du terrain pour bien se voir.
 */
export function rondsHtml(actions, vertical, sens, echapper, campDe = null) {
    const corners = new Map();
    actions.filter((a) => a.type === 'CO' || a.type === 'CA').forEach((a) => {
        const [x, y] = a.details.point;
        const cle = `${a.type}|${x < 52.5 ? 0 : 105}|${y < 34 ? 0 : 68}`;
        corners.set(cle, (corners.get(cle) ?? 0) + 1);
    });
    const drapeaux = [...corners].map(([cle, n]) => {
        const [type, x, y] = cle.split('|');
        // Collée au point de corner, à l'intérieur du terrain (elle s'accroche au coin par son propre angle).
        const { gauche, haut } = placeEcran([Number(x), Number(y)], vertical, sens);
        const dx = gauche > 50 ? '-100%' : '0';
        const dy = haut > 50 ? '-100%' : '0';
        return `<span class="corner-terrain ${type === 'CO' ? 'nous' : 'eux'}" style="left:${gauche}%;top:${haut}%;transform:translate(${dx},${dy})" title="${n} corner${n > 1 ? 's' : ''} ${type === 'CO' ? 'pour nous' : 'pour l\'adversaire'}">${DRAPEAU}${n}</span>`;
    }).join('');
    // But sur corner : trajet en pointillés du drapeau (côté du tir) jusqu'au départ du tir.
    const trajets = actions.filter((a) => (a.type === 'B' || a.type === 'BA') && a.details?.situation === 'corner').map((a) => {
        const [, y] = a.details.point;
        const coin = placeEcran([a.type === 'B' ? LONGUEUR : 0, y < LARGEUR / 2 ? 0 : LARGEUR], vertical, sens);
        const tir = placeEcran(a.details.point, vertical, sens);
        return `<line class="${a.type === 'B' ? 'nous' : 'eux'}" x1="${coin.gauche}" y1="${coin.haut}" x2="${tir.gauche}" y2="${tir.haut}" vector-effect="non-scaling-stroke"/>`;
    }).join('')
        // But sur coup franc indirect : de l'endroit du coup franc jusqu'à la reprise.
        + actions.filter((a) => (a.type === 'B' || a.type === 'BA') && a.details?.situation === 'cfi' && a.details?.coupFranc).map((a) => {
            const cf = placeEcran(a.details.coupFranc, vertical, sens);
            const tir = placeEcran(a.details.point, vertical, sens);
            return `<line class="${a.type === 'B' ? 'nous' : 'eux'}" x1="${cf.gauche}" y1="${cf.haut}" x2="${tir.gauche}" y2="${tir.haut}" vector-effect="non-scaling-stroke"/>`;
        }).join('');
    // Endroit du coup franc indirect : petit rond « CF » à la couleur de l'équipe.
    const lieuxCf = actions.filter((a) => (a.type === 'B' || a.type === 'BA') && a.details?.situation === 'cfi' && a.details?.coupFranc).map((a) => {
        const { gauche, haut } = placeEcran(a.details.coupFranc, vertical, sens);
        return `<span class="cf-terrain ${a.type === 'B' ? 'nous' : 'eux'}" style="left:${gauche}%;top:${haut}%" title="${echapper(`${a.minute}' · coup franc indirect`)}">CF</span>`;
    }).join('');
    const svg = trajets ? `<svg class="trajets-cpa" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">${trajets}</svg>` : '';
    return svg + drapeaux + lieuxCf + actions.filter((a) => a.type !== 'CO' && a.type !== 'CA').map((a, i, liste) => {
        const { gauche, haut } = placeEcran(a.details.point, vertical, sens);
        const dernier = i === liste.length - 1 ? ' dernier' : '';
        // But (départ du tir) : un petit ballon, cerclé de rouge pour nous, de bleu pour l'adversaire ; coup de pied
        // arrêté : pastille (drapeau pour un corner, « CF » pour un coup franc, « P » pour un penalty).
        if (a.type === 'B' || a.type === 'BA') {
            const pastille = { corner: DRAPEAU, cod: DRAPEAU, cfd: 'CF', cfi: 'CF', penalty: 'P' }[a.details?.situation] ?? '';
            return `<span class="but-terrain ${a.type === 'B' ? 'nous' : 'eux'}" style="left:${gauche}%;top:${haut}%" title="${echapper(`${a.minute}' · ${a.libelle || ''}`)}">⚽${pastille ? `<span class="pastille-cpa">${pastille}</span>` : ''}</span>`;
        }
        // Tout le match : une couleur par équipe (campDe) au lieu d'une couleur par action.
        const couleur = campDe ? `camp-${campDe(a)}` : `action-${a.type}`;
        return `<span class="rond-action ${couleur}${dernier}" style="left:${gauche}%;top:${haut}%" title="${echapper(`${a.minute}' · ${a.libelle || ''}`)}"></span>`;
    }).join('');
}
