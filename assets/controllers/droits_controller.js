import { Controller } from '@hotwired/stimulus';

/*
 * Tableau des droits d'accès : un clic sur une case passe au niveau suivant
 * (droit simple : — / ✓ ; section : — / voir / modifier), enregistré sans recharger la page.
 */
const TEXTES_SIMPLES = ['—', '✓'];
const TEXTES_NIVEAUX = ['—', 'voir', 'modifier'];

export default class extends Controller {
    static targets = ['statut'];

    async basculer(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const bouton = form.querySelector('button');
        if (bouton.disabled) {
            return;
        }
        bouton.disabled = true;

        try {
            const reponse = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: { Accept: 'application/json' },
            });
            const donnees = await reponse.json();
            if (!reponse.ok) {
                throw new Error(donnees.erreur || 'Enregistrement impossible.');
            }
            this.afficher(form.closest('tr'), donnees.roles);
            this.annoncer(donnees.message, 'succes');
        } catch (erreur) {
            bouton.disabled = false;
            this.annoncer(erreur.message || 'Enregistrement impossible : vérifiez la connexion.', 'erreur');
        }
    }

    // Redessine toutes les cases d'une ligne (passer administrateur change les autres colonnes).
    afficher(ligne, roles) {
        const admin = roles.includes('ROLE_ADMIN');
        ligne.querySelectorAll('button.interrupteur').forEach((bouton) => {
            const { cle, lecture, modif } = bouton.dataset;
            const section = lecture !== undefined;
            const max = section ? 2 : 1;
            const niveau = section
                ? (roles.includes(modif) ? 2 : (roles.includes(lecture) ? 1 : 0))
                : (roles.includes(cle) ? 1 : 0);
            const inclus = admin && cle !== 'ROLE_ADMIN';

            bouton.classList.remove('oui', 'non', 'lecture', 'inclus');
            bouton.classList.add(inclus ? 'inclus' : (niveau === max ? 'oui' : (niveau ? 'lecture' : 'non')));
            bouton.textContent = inclus ? 'inclus' : (section ? TEXTES_NIVEAUX : TEXTES_SIMPLES)[niveau];
            bouton.disabled = inclus || 'verrou' in bouton.dataset;
            bouton.title = inclus ? 'Inclus dans l\'administration' : bouton.getAttribute('aria-label').split(' pour ')[0];
            bouton.form.querySelector('input[name="niveau"]').value = niveau >= max ? 0 : niveau + 1;
        });
    }

    annoncer(message, type) {
        this.statutTarget.textContent = message;
        this.statutTarget.className = `droits-statut flash flash-${type}`;
    }
}
