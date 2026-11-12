import { Controller } from '@hotwired/stimulus';

/*
 * Notifications sur ce téléphone : enregistre le service worker (/sw.js), demande l'autorisation, abonne l'appareil
 * auprès du serveur (clé publique VAPID du site) ; désactivation et notification d'essai.
 * Sur iPhone, les notifications ne marchent qu'une fois l'application ajoutée à l'écran d'accueil et ouverte depuis là.
 */
export default class extends Controller {
    static targets = ['etat', 'activer', 'desactiver', 'essai', 'aide'];
    static values = { cle: String, abonner: String, desabonner: String, essai: String, jeton: String };

    async connect() {
        if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
            const ios = /iPhone|iPad|iPod/.test(navigator.userAgent);
            const installee = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;
            this.afficher(ios && !installee
                ? 'Sur iPhone : touchez le bouton Partager puis « Sur l\'écran d\'accueil », ouvrez l\'application depuis cette icône et revenez ici.'
                : 'Ce navigateur ne reçoit pas les notifications.', []);
            return;
        }
        this.inscription = await navigator.serviceWorker.register('/sw.js');
        const abonnement = await this.inscription.pushManager.getSubscription();
        if (abonnement) {
            // Toujours renvoyé : rattache l'appareil au compte connecté (et met à jour ses clés).
            await this.envoyer(this.abonnerValue, abonnement.toJSON());
            this.actif();
        } else if (Notification.permission === 'denied') {
            this.afficher('Notifications bloquées pour ce site : autorisez-les dans les réglages du navigateur.', []);
        } else {
            this.afficher('Désactivées sur cet appareil.', ['activer']);
        }
    }

    async activer() {
        this.afficher('Activation…', []);
        try {
            if ((await Notification.requestPermission()) !== 'granted') {
                this.afficher('Vous n\'avez pas autorisé les notifications.', ['activer']);
                return;
            }
            const abonnement = await this.inscription.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: this.cleBinaire(this.cleValue),
            });
            await this.envoyer(this.abonnerValue, abonnement.toJSON());
            this.actif();
        } catch (e) {
            this.afficher('Activation impossible sur cet appareil.', ['activer']);
        }
    }

    async desactiver() {
        const abonnement = await this.inscription.pushManager.getSubscription();
        if (abonnement) {
            await this.envoyer(this.desabonnerValue, { endpoint: abonnement.endpoint });
            await abonnement.unsubscribe();
        }
        this.afficher('Désactivées sur cet appareil.', ['activer']);
    }

    async essai() {
        const reponse = await this.envoyer(this.essaiValue, {});
        this.afficher(reponse && reponse.ok ? 'Notification d\'essai envoyée : elle arrive dans quelques secondes.' : 'L\'essai n\'a pas abouti.', ['desactiver', 'essai']);
    }

    actif() {
        this.afficher('✓ Activées sur cet appareil.', ['desactiver', 'essai']);
    }

    afficher(texte, boutons) {
        this.etatTarget.textContent = texte;
        for (const nom of ['activer', 'desactiver', 'essai']) {
            if (this[`has${nom[0].toUpperCase()}${nom.slice(1)}Target`]) {
                this[`${nom}Target`].hidden = !boutons.includes(nom);
            }
        }
    }

    async envoyer(url, donnees) {
        const reponse = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ...donnees, _token: this.jetonValue }),
            credentials: 'same-origin',
        });
        return reponse.ok ? reponse.json() : null;
    }

    cleBinaire(base64) {
        const complete = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from(atob(complete), (c) => c.charCodeAt(0));
    }
}
