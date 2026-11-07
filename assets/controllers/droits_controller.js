import { Controller } from '@hotwired/stimulus';

/*
 * Tableau des droits d'accès : un clic sur une case donne ou retire le droit,
 * enregistré tout de suite sans recharger la page.
 */
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
            const role = bouton.dataset.role;
            const actif = roles.includes(role);
            const inclus = admin && role !== 'ROLE_ADMIN';
            bouton.classList.remove('oui', 'non', 'inclus');
            bouton.classList.add(inclus ? 'inclus' : (actif ? 'oui' : 'non'));
            bouton.textContent = inclus ? 'inclus' : (actif ? '✓' : '—');
            bouton.setAttribute('aria-pressed', actif || inclus ? 'true' : 'false');
            bouton.disabled = inclus || 'verrou' in bouton.dataset;
            bouton.title = inclus ? 'Inclus dans l\'administration' : bouton.getAttribute('aria-label').split(' pour ')[0];
            bouton.form.querySelector('input[name="actif"]').value = actif ? '0' : '1';
        });
    }

    annoncer(message, type) {
        this.statutTarget.textContent = message;
        this.statutTarget.className = `droits-statut flash flash-${type}`;
    }
}
