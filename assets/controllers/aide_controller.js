import { Controller } from '@hotwired/stimulus';

/*
 * Assistant d'aide : fenêtre de discussion ouverte par le bouton « ? » ; chaque question reçoit les passages du guide
 * de l'application qui y répondent (AideController). La conversation est gardée le temps de l'onglet (sessionStorage)
 * pour suivre d'une page à l'autre.
 */
const CLE = 'aide-conversation';

export default class extends Controller {
    static values = { url: String, jeton: String };
    static targets = ['bouton', 'fenetre', 'fil', 'question', 'envoyer', 'suggestions'];

    connect() {
        this.historique = this.lire();
        this.historique.forEach((m) => this.ajouter(m.role, m.content, false));
        if (this.historique.length) this.suggestionsTarget.hidden = true;
        if (this.lireOuvert()) this.ouvrir(false);
    }

    basculer() {
        if (this.fenetreTarget.hidden) this.ouvrir(true);
        else this.fermer();
    }

    ouvrir(focus) {
        this.fenetreTarget.hidden = false;
        this.boutonTarget.setAttribute('aria-expanded', 'true');
        this.element.classList.add('ouverte');
        this.garderOuvert(true);
        this.descendre();
        if (focus) this.questionTarget.focus();
    }

    fermer() {
        this.fenetreTarget.hidden = true;
        this.boutonTarget.setAttribute('aria-expanded', 'false');
        this.element.classList.remove('ouverte');
        this.garderOuvert(false);
    }

    suggerer(event) {
        this.questionTarget.value = event.currentTarget.textContent.trim();
        this.envoyer();
    }

    /** Entrée envoie, Maj + Entrée va à la ligne. */
    touche(event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            this.envoyer();
        }
    }

    async envoyer(event) {
        event?.preventDefault();
        const question = this.questionTarget.value.trim();
        if (!question || this.enCours) return;
        this.enCours = true;
        this.envoyerTarget.disabled = true;
        this.suggestionsTarget.hidden = true;
        this.questionTarget.value = '';
        this.ajouter('user', question);
        const attente = this.ajouter('assistant', '…', false);
        attente.classList.add('attente');
        try {
            const reponse = await fetch(this.urlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ jeton: this.jetonValue, question }),
            });
            const donnees = await reponse.json().catch(() => ({}));
            attente.remove();
            if (!reponse.ok) throw new Error(donnees.erreur || 'L\'assistant n\'a pas pu répondre. Réessayez dans un instant.');
            this.ajouter('assistant', donnees.reponse);
        } catch (e) {
            attente.remove();
            // La question non traitée sort de l'historique : on peut la reposer.
            this.historique.pop();
            this.garder();
            const p = this.ajouter('erreur', e instanceof TypeError ? 'Pas de réseau : réessayez quand la connexion revient.' : e.message, false);
            p.classList.add('erreur');
        } finally {
            this.enCours = false;
            this.envoyerTarget.disabled = false;
            this.questionTarget.focus();
        }
    }

    effacer() {
        this.historique = [];
        this.garder();
        this.filTarget.querySelectorAll('.aide-message:not(:first-child)').forEach((m) => m.remove());
        this.suggestionsTarget.hidden = false;
    }

    /** Ajoute un message au fil (et à l'historique sauf « garder » faux). Les réponses gardent gras et listes. */
    ajouter(role, texte, garder = true) {
        const p = document.createElement('div');
        p.className = `aide-message ${role}`;
        p.innerHTML = role === 'assistant' ? this.miseEnForme(texte) : this.echapper(texte).replace(/\n/g, '<br>');
        this.filTarget.appendChild(p);
        if (garder) {
            this.historique.push({ role, content: texte });
            this.garder();
        }
        // Nouvelle réponse : on la montre depuis son début (elle peut être longue) ; sinon, en bas du fil.
        if (role === 'assistant' && garder) this.filTarget.scrollTop = p.offsetTop - 8;
        else this.descendre();
        return p;
    }

    miseEnForme(texte) {
        const lignes = this.echapper(texte).replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').split('\n');
        let html = '';
        let liste = null;
        for (const ligne of lignes) {
            const puce = ligne.match(/^\s*[-•]\s+(.*)$/);
            const numero = ligne.match(/^\s*\d+[.)]\s+(.*)$/);
            const type = puce ? 'ul' : (numero ? 'ol' : null);
            if (type !== liste) {
                if (liste) html += `</${liste}>`;
                if (type) html += `<${type}>`;
                liste = type;
            }
            if (type) html += `<li>${(puce || numero)[1]}</li>`;
            else if (ligne.trim()) html += `<p>${ligne}</p>`;
        }
        if (liste) html += `</${liste}>`;
        return html;
    }

    descendre() {
        this.filTarget.scrollTop = this.filTarget.scrollHeight;
    }

    lire() {
        try {
            const l = JSON.parse(sessionStorage.getItem(CLE) || '[]');
            return Array.isArray(l) ? l.slice(-20) : [];
        } catch (e) {
            return [];
        }
    }

    garder() {
        try {
            sessionStorage.setItem(CLE, JSON.stringify(this.historique.slice(-20)));
        } catch (e) { /* stockage indisponible : la conversation vaut pour cette page */ }
    }

    lireOuvert() {
        try {
            return sessionStorage.getItem(`${CLE}-ouverte`) === '1';
        } catch (e) {
            return false;
        }
    }

    garderOuvert(ouvert) {
        try {
            sessionStorage.setItem(`${CLE}-ouverte`, ouvert ? '1' : '0');
        } catch (e) { /* rien */ }
    }

    echapper(texte) {
        return String(texte ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }
}
