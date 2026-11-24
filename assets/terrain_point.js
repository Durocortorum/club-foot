/*
 * Point précis d'une action sur le terrain (Match Live, saisie détaillée) : l'endroit touché, converti en mètres
 * dans le sens de notre attaque (x : 0 = notre ligne de but → 105 = but adverse ; y : 0 = notre gauche → 68 = notre
 * droite), quel que soit le côté où l'on attaque et l'orientation de l'écran ; et l'inverse pour dessiner le rond.
 * La zone (6 bandes × 3 couloirs) se déduit du point, pour les statistiques par zone.
 */
export const LONGUEUR = 105;
export const LARGEUR = 68;

const borne = (v, max) => Math.max(0, Math.min(max, v));

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
export function rondsHtml(actions, vertical, sens, echapper) {
    const corners = new Map();
    actions.filter((a) => a.type === 'CO' || a.type === 'CA').forEach((a) => {
        const [x, y] = a.details.point;
        const cle = `${a.type}|${x < 52.5 ? 0 : 105}|${y < 34 ? 0 : 68}`;
        corners.set(cle, (corners.get(cle) ?? 0) + 1);
    });
    const drapeaux = [...corners].map(([cle, n]) => {
        const [type, x, y] = cle.split('|');
        // Décalé vers l'intérieur : 5 m de la ligne de but, 6 m de la ligne de touche.
        const { gauche, haut } = placeEcran([Number(x) === 0 ? 5 : 100, Number(y) === 0 ? 6 : 62], vertical, sens);
        return `<span class="corner-terrain ${type === 'CO' ? 'nous' : 'eux'}" style="left:${gauche}%;top:${haut}%" title="${n} corner${n > 1 ? 's' : ''} ${type === 'CO' ? 'pour nous' : 'pour l\'adversaire'}">🚩${n > 1 ? ` ${n}` : ''}</span>`;
    }).join('');
    return drapeaux + actions.filter((a) => a.type !== 'CO' && a.type !== 'CA').map((a, i, liste) => {
        const { gauche, haut } = placeEcran(a.details.point, vertical, sens);
        const dernier = i === liste.length - 1 ? ' dernier' : '';
        return `<span class="rond-action action-${a.type}${dernier}" style="left:${gauche}%;top:${haut}%" title="${echapper(`${a.minute}' · ${a.libelle || ''}`)}"></span>`;
    }).join('');
}
