import { Controller } from '@hotwired/stimulus';

/*
 * Page « Apparence du club » : applique le nom, les couleurs et le logo choisis à toute la page
 * avant l'enregistrement, et signale un texte peu lisible sur la couleur principale.
 */
export default class extends Controller {
    static targets = ['nom', 'apercuNom', 'couleur', 'texte', 'fichier', 'alerte', 'ratio'];

    connect() {
        this.apercu();
    }

    apercu() {
        const couleur = this.couleurTarget.value;
        // Sur le <body>, comme base.html.twig : Turbo le remplace à la page suivante, l'aperçu ne survit pas.
        const style = document.body.style;
        style.setProperty('--rouge', couleur);
        style.setProperty('--rouge-fonce', assombrir(couleur));
        style.setProperty('--sur-rouge', this.texteTarget.value);
        const ratio = contraste(couleur, this.texteTarget.value);
        this.ratioTarget.textContent = ratio.toFixed(1).replace('.', ',');
        this.alerteTarget.hidden = ratio >= 3;
    }

    // Blanc ou noir, selon ce qui se lit le mieux sur la couleur principale.
    texteAuto() {
        const couleur = this.couleurTarget.value;
        this.texteTarget.value = contraste(couleur, '#ffffff') >= contraste(couleur, '#1d1d1f') ? '#ffffff' : '#1d1d1f';
        this.apercu();
    }

    // Le nom dans l'en-tête de la page et dans l'aperçu.
    nom() {
        const nom = this.nomTarget.value.trim() || '…';
        this.apercuNomTarget.textContent = nom;
        const marque = document.querySelector('.entete .marque');
        if (marque) marque.lastChild.textContent = ` ${nom} `;
    }

    logo() {
        const fichier = this.fichierTarget.files[0];
        if (!fichier || !fichier.type.startsWith('image/')) return;
        const adresse = URL.createObjectURL(fichier);
        document.querySelectorAll('img.blason').forEach((img) => { img.src = adresse; });
    }
}

const canaux = (hex) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));

function assombrir(hex) {
    return '#' + canaux(hex).map((c) => Math.round(c * 0.78).toString(16).padStart(2, '0')).join('');
}

// Rapport de contraste WCAG entre deux couleurs (1 à 21 ; 4,5 conseillé pour du texte, 3 au minimum).
function contraste(a, b) {
    const luminance = (hex) => {
        const [r, v, bl] = canaux(hex).map((c) => {
            const s = c / 255;
            return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
        });
        return 0.2126 * r + 0.7152 * v + 0.0722 * bl;
    };
    const [l1, l2] = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return (l1 + 0.05) / (l2 + 0.05);
}
