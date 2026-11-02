/*
 * Boîte de confirmation aux couleurs du site, à la place du confirm() du navigateur.
 * - en JavaScript : if (await confirmer('Effacer ?')) { … }
 * - sur un formulaire : <form data-confirmation="Supprimer cet exercice ?" data-confirmation-bouton="Supprimer">
 */

export function confirmer(question, { bouton = 'Confirmer', titre = 'Confirmation' } = {}) {
    return new Promise((resoudre) => {
        const dialogue = document.createElement('dialog');
        dialogue.className = 'dialogue';
        dialogue.innerHTML = `
            <form method="dialog">
                <h2 class="dialogue-titre"></h2>
                <p class="dialogue-question"></p>
                <div class="dialogue-actions">
                    <button value="non" class="bouton">Annuler</button>
                    <button value="oui" class="bouton danger-plein"></button>
                </div>
            </form>`;
        dialogue.querySelector('.dialogue-titre').textContent = titre;
        dialogue.querySelector('.dialogue-question').textContent = question;
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
    const question = formulaire.dataset?.confirmation;
    if (!question || formulaire.dataset.confirme) return;
    event.preventDefault();
    if (await confirmer(question, { bouton: formulaire.dataset.confirmationBouton || 'Confirmer' })) {
        formulaire.dataset.confirme = '1';
        formulaire.requestSubmit(event.submitter);
    }
});
