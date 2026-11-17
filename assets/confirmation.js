/*
 * Boîte de confirmation aux couleurs du site, à la place du confirm() du navigateur.
 * - en JavaScript : if (await confirmer('Effacer ?')) { … }
 * - sur un formulaire : <form data-confirmation="Supprimer cet exercice ?" data-confirmation-bouton="Supprimer">
 */

/**
 * Options : bouton (texte du bouton de validation), titre, style du bouton (« danger-plein » rouge par défaut,
 * « principal » bleu pour ce qui n'efface rien), detail (petite ligne sous la question), classe (de la boîte).
 */
export function confirmer(question, { bouton = 'Confirmer', titre = 'Confirmation', style = 'danger-plein', detail = '', classe = '', score = null } = {}) {
    return new Promise((resoudre) => {
        const dialogue = document.createElement('dialog');
        dialogue.className = `dialogue ${classe}`.trim();
        dialogue.innerHTML = `
            <form method="dialog">
                <h2 class="dialogue-titre"></h2>
                <p class="dialogue-question"></p>
                <p class="dialogue-detail discret petit" hidden></p>
                <div class="dialogue-actions">
                    <button value="non" class="bouton">Annuler</button>
                    <button value="oui" class="bouton ${style}"></button>
                </div>
            </form>`;
        dialogue.querySelector('.dialogue-titre').textContent = titre;
        dialogue.querySelector('.dialogue-question').textContent = question;
        // Tableau de score (fin de période du match en direct) : [équipe, score, équipe].
        if (Array.isArray(score)) {
            const tableau = document.createElement('div');
            tableau.className = 'dialogue-score';
            score.forEach((texte, i) => {
                const morceau = document.createElement('span');
                morceau.className = ['equipe', 'score', 'equipe'][i] ?? '';
                morceau.textContent = texte;
                tableau.append(morceau);
            });
            dialogue.querySelector('.dialogue-question').after(tableau);
        }
        if (detail) {
            dialogue.querySelector('.dialogue-detail').textContent = detail;
            dialogue.querySelector('.dialogue-detail').hidden = false;
        }
        dialogue.querySelector('[value=oui]').textContent = bouton;
        // Un clic à côté de la boîte vaut « Annuler ».
        dialogue.addEventListener('click', (event) => {
            if (event.target === dialogue) dialogue.close('non');
        });
        dialogue.addEventListener('close', () => {
            dialogue.remove();
            resoudre(dialogue.returnValue === 'oui');
        });
        document.body.append(dialogue);
        dialogue.showModal();
        dialogue.querySelector('[value=non]').focus();
    });
}

document.addEventListener('submit', async (event) => {
    const formulaire = event.target;
    // La question peut aussi venir du bouton qui envoie le formulaire (un seul bouton à confirmer parmi plusieurs).
    const source = event.submitter?.dataset?.confirmation ? event.submitter : formulaire;
    const question = source.dataset?.confirmation;
    if (!question || formulaire.dataset.confirme) return;
    event.preventDefault();
    if (await confirmer(question, { bouton: source.dataset.confirmationBouton || 'Confirmer' })) {
        formulaire.dataset.confirme = '1';
        formulaire.requestSubmit(event.submitter);
    }
});
