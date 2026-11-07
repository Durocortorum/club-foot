import { Controller } from '@hotwired/stimulus';

/*
 * Tableau des droits d'accès : un clic sur une case passe au niveau suivant
 * (droit simple : — / ✓ ; section : — / voir / modifier), enregistré sans recharger la page.
 * Droits médicaux : la durée choisie en haut du tableau est envoyée, l'échéance s'affiche sous la case.
 */
const TEXTES_SIMPLES = ['—', '✓'];
const TEXTES_NIVEAUX = ['—', 'voir', 'modifier'];
const MEDICALES = ['infirmerie', 'visite_medicale'];

export default class extends Controller {
    static targets = ['statut', 'duree'];

    async basculer(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const bouton = form.querySelector('button');
        if (bouton.disabled) {
            return;
        }
        bouton.disabled = true;

        try {
            const donnees = new FormData(form);
            if (this.hasDureeTarget && donnees.has('duree')) {
                donnees.set('duree', this.dureeTarget.value);
            }
            const reponse = await fetch(form.action, {
                method: 'POST',
                body: donnees,
                headers: { Accept: 'application/json' },
            });
            const resultat = await reponse.json();
            if (!reponse.ok) {
                throw new Error(resultat.erreur || 'Enregistrement impossible.');
            }
            this.afficher(form.closest('tr'), resultat.roles, resultat.echeances || {});
            this.annoncer(resultat.message, 'succes');
        } catch (erreur) {
            bouton.disabled = false;
            this.annoncer(erreur.message || 'Enregistrement impossible : vérifiez la connexion.', 'erreur');
        }
    }

    // Redessine toutes les cases d'une ligne (passer administrateur ou médecin change d'autres colonnes).
    afficher(ligne, roles, echeances) {
        const admin = roles.includes('ROLE_ADMIN');
        const medecin = roles.includes('ROLE_MEDECIN');
        ligne.querySelectorAll('button.interrupteur').forEach((bouton) => {
            const { cle, lecture, modif } = bouton.dataset;
            const section = lecture !== undefined;
            const max = section ? 2 : 1;
            const niveau = section
                ? (roles.includes(modif) ? 2 : (roles.includes(lecture) ? 1 : 0))
                : (roles.includes(cle) ? 1 : 0);
            const medicale = MEDICALES.includes(cle);
            const inclus = (admin && !['ROLE_ADMIN', 'ROLE_MEDECIN'].includes(cle)) || (medecin && medicale);

            bouton.classList.remove('oui', 'non', 'lecture', 'inclus');
            bouton.classList.add(inclus ? 'inclus' : (niveau === max ? 'oui' : (niveau ? 'lecture' : 'non')));
            bouton.textContent = inclus ? (admin ? 'inclus' : 'médecin') : (section ? TEXTES_NIVEAUX : TEXTES_SIMPLES)[niveau];
            bouton.disabled = inclus || 'verrou' in bouton.dataset;
            bouton.title = inclus
                ? (admin ? 'Inclus dans l\'administration' : 'Inclus : médecin du club')
                : bouton.getAttribute('aria-label').split(' pour ')[0];
            bouton.form.querySelector('input[name="niveau"]').value = niveau >= max ? 0 : niveau + 1;

            const echeance = bouton.form.querySelector('[data-echeance]');
            if (echeance) {
                const fin = echeances[cle]; // jj/mm/aaaa
                echeance.classList.remove('proche');
                echeance.textContent = !inclus && niveau && fin ? `jusqu'au ${fin.slice(0, 6)}${fin.slice(8)}` : '';
            }
        });
    }

    annoncer(message, type) {
        this.statutTarget.textContent = message;
        this.statutTarget.className = `droits-statut flash flash-${type}`;
    }
}
