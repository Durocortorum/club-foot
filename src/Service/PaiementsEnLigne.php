<?php

namespace App\Service;

use App\Entity\Club;
use App\Entity\CommandeBoutique;
use App\Entity\ConnexionHelloAsso;
use App\Entity\Cotisation;
use App\Entity\InscriptionStage;
use App\Entity\Joueur;
use App\Entity\Paiement;
use App\Entity\PaiementEnLigne;
use App\Entity\Rencontre;
use App\Entity\Utilisateur;
use App\Repository\ConnexionHelloAssoRepository;
use App\Repository\CotisationRepository;
use App\Repository\TarifCotisationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Paiement en ligne des familles (option du forfait, compte HelloAsso du club) : ce qui reste à payer,
 * lancement d'un paiement en une ou plusieurs fois, et enregistrement des échéances encaissées dans les Finances.
 * On ne se fie jamais au retour du navigateur ni au contenu d'une notification : l'état est toujours relu chez HelloAsso.
 */
class PaiementsEnLigne
{
    /** États HelloAsso d'un paiement encaissé (ou dont l'encaissement est garanti). */
    private const ENCAISSE = ['Authorized', 'Registered'];

    public function __construct(
        private readonly HelloAsso $helloAsso,
        private readonly ConnexionHelloAssoRepository $connexions,
        private readonly CotisationRepository $cotisations,
        private readonly TarifCotisationRepository $tarifs,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Compte HelloAsso utilisable par les familles : option du forfait active et compte vérifié. */
    public function connexion(?Club $club): ?ConnexionHelloAsso
    {
        if (!$club || !$club->isPaiementEnLigne()) {
            return null;
        }
        $c = $this->connexions->findOneBy(['club' => $club]);

        return $c && $c->isVerifiee() ? $c : null;
    }

    /**
     * Cotisation d'un joueur pour une saison : dû (montant fixé ou tarif de sa catégorie), payé, reste.
     *
     * @return array{saison: string, du: float, paye: float, reste: float, cotisation: ?Cotisation}
     */
    public function situationCotisation(Joueur $joueur, ?string $saison = null): array
    {
        $saison ??= Rencontre::saisonDe(new \DateTimeImmutable('today'));
        $cotisation = $this->cotisations->findOneBy(['joueur' => $joueur, 'saison' => $saison]);
        $tarif = $joueur->getCategorie() ? $this->tarifs->findOneBy(['categorie' => $joueur->getCategorie(), 'saison' => $saison]) : null;
        $du = $cotisation?->getMontant() ?? ($tarif ? (float) $tarif->getMontant() : 0.0);
        $paye = $cotisation?->getPaye() ?? 0.0;

        return ['saison' => $saison, 'du' => $du, 'paye' => $paye, 'reste' => round(max(0.0, $du - $paye), 2), 'cotisation' => $cotisation];
    }

    /**
     * Lance le paiement en ligne du reste de la cotisation, en $echeances fois (une par mois).
     *
     * @param callable(PaiementEnLigne): array{retour: string, erreur: string, abandon: string} $adresses
     *
     * @return array{paiement: PaiementEnLigne, redirection: string}
     *
     * @throws ErreurHelloAsso
     */
    public function payerCotisation(Joueur $joueur, Utilisateur $payeur, int $echeances, callable $adresses): array
    {
        $connexion = $this->connexion($joueur->getClub()) ?? throw new ErreurHelloAsso('Le paiement en ligne n\'est pas ouvert pour ce club.');
        $situation = $this->situationCotisation($joueur);
        $total = (int) round($situation['reste'] * 100);
        if ($total < 100) {
            throw new ErreurHelloAsso('Il n\'y a rien à payer en ligne pour cette cotisation.');
        }
        $echeances = max(1, min($echeances, $connexion->getEcheancesMax(), intdiv($total, 100)));
        $cotisation = $situation['cotisation'] ?? $this->creerCotisation($joueur, $situation['saison']);

        $paiement = (new PaiementEnLigne(PaiementEnLigne::COTISATION, $joueur,
            \sprintf('Cotisation %s – %s %s', $situation['saison'], $joueur->getPrenom(), $joueur->getNom()), $total, $echeances, $payeur))
            ->setCotisation($cotisation);

        // Échéances : la première aujourd'hui (elle garde les centimes en trop), les suivantes de mois en mois.
        $part = intdiv($total, $echeances);
        $suivantes = [];
        for ($i = 1; $i < $echeances; ++$i) {
            $suivantes[] = ['amount' => $part, 'date' => (new \DateTimeImmutable("today +$i month"))->format(\DATE_ATOM)];
        }

        return $this->lancer($connexion, $paiement, $payeur, $total - $part * ($echeances - 1), $suivantes, $adresses);
    }

    /**
     * Lance le paiement en ligne d'une inscription à un stage payant (en une fois).
     *
     * @param callable(PaiementEnLigne): array{retour: string, erreur: string, abandon: string} $adresses
     *
     * @return array{paiement: PaiementEnLigne, redirection: string}
     *
     * @throws ErreurHelloAsso
     */
    public function payerStage(InscriptionStage $inscription, Utilisateur $payeur, callable $adresses): array
    {
        $stage = $inscription->getStage();
        $joueur = $inscription->getJoueur();
        $connexion = $this->connexion($joueur->getClub()) ?? throw new ErreurHelloAsso('Le paiement en ligne n\'est pas ouvert pour ce club.');
        if ($inscription->isPaye() || !$stage->isPayant()) {
            throw new ErreurHelloAsso('Cette inscription n\'est pas à régler.');
        }
        $total = (int) round((float) $stage->getTarif() * 100);
        $paiement = (new PaiementEnLigne(PaiementEnLigne::STAGE, $joueur, \sprintf('Stage « %s » – %s %s', $stage->getTitre(), $joueur->getPrenom(), $joueur->getNom()), $total, 1, $payeur))
            ->setInscriptionStage($inscription);

        return $this->lancer($connexion, $paiement, $payeur, $total, [], $adresses);
    }

    /**
     * Lance le paiement en ligne d'une commande de la boutique (en une fois).
     *
     * @param callable(PaiementEnLigne): array{retour: string, erreur: string, abandon: string} $adresses
     *
     * @return array{paiement: PaiementEnLigne, redirection: string}
     *
     * @throws ErreurHelloAsso
     */
    public function payerCommande(CommandeBoutique $commande, Utilisateur $payeur, callable $adresses): array
    {
        $joueur = $commande->getJoueur() ?? $payeur->getJoueurs()->first() ?: throw new ErreurHelloAsso('Commande sans joueur.');
        $connexion = $this->connexion($commande->getClub()) ?? throw new ErreurHelloAsso('Le paiement en ligne n\'est pas ouvert pour ce club.');
        if (!$commande->isARegler() || $commande->getTotal() < 100) {
            throw new ErreurHelloAsso('Cette commande n\'est pas à régler en ligne.');
        }
        $paiement = (new PaiementEnLigne(PaiementEnLigne::BOUTIQUE, $joueur, \sprintf('Boutique – commande n° %s (%d article%s)', $commande->getNumero(), $commande->getNombreArticles(), $commande->getNombreArticles() > 1 ? 's' : ''), $commande->getTotal(), 1, $payeur))
            ->setCommande($commande);

        return $this->lancer($connexion, $paiement, $payeur, $commande->getTotal(), [], $adresses);
    }

    /**
     * Enregistre le paiement, crée l'intention chez HelloAsso et renvoie l'adresse de sa page de paiement.
     *
     * @param list<array{amount: int, date: string}> $suivantes
     *
     * @return array{paiement: PaiementEnLigne, redirection: string}
     */
    private function lancer(ConnexionHelloAsso $connexion, PaiementEnLigne $paiement, Utilisateur $payeur, int $premier, array $suivantes, callable $adresses): array
    {
        $this->em->persist($paiement);
        $this->em->flush();
        $urls = $adresses($paiement);
        $nomPayeur = $payeur->isFamille() && 'Famille' === $payeur->getPrenom() ? [] : ['firstName' => $payeur->getPrenom(), 'lastName' => $payeur->getNom()];
        try {
            $intention = $this->helloAsso->creerPaiement($connexion, $paiement->getLibelle(), $paiement->getMontant(), $premier, $suivantes,
                $nomPayeur + ['email' => $payeur->getEmail()], $urls['retour'], $urls['erreur'], $urls['abandon'],
                ['paiement' => $paiement->getId(), 'objet' => $paiement->getObjet(), 'joueur' => $paiement->getJoueur()->getId()]);
        } catch (ErreurHelloAsso $e) {
            $paiement->abandonner();
            $this->em->flush();
            throw $e;
        }
        $paiement->setCheckoutIntentId($intention['id']);
        $this->em->flush();

        return ['paiement' => $paiement, 'redirection' => $intention['redirectUrl']];
    }

    /**
     * Relit le paiement chez HelloAsso : commande créée → payé. Cotisation : chaque échéance encaissée est ajoutée
     * une seule fois aux paiements de la cotisation (Finances). Stage : l'inscription est marquée réglée.
     *
     * @return bool le paiement est (au moins en partie) encaissé
     */
    public function synchroniser(PaiementEnLigne $paiement): bool
    {
        $connexion = $this->connexions->findOneBy(['club' => $paiement->getClub()]);
        if (!$connexion || !$paiement->getCheckoutIntentId()) {
            return false;
        }
        try {
            $etat = $this->helloAsso->lirePaiement($connexion, $paiement->getCheckoutIntentId());
        } catch (ErreurHelloAsso $e) {
            $this->logger->warning('Paiement en ligne {id} non relu : {erreur}', ['id' => $paiement->getId(), 'erreur' => $e->getMessage()]);

            return $paiement->isPaye();
        }
        $commande = $etat['order'] ?? null;
        if (!\is_array($commande) || empty($commande['id'])) {
            return false;
        }
        $paiement->marquerPaye((int) $commande['id']);
        if (PaiementEnLigne::STAGE === $paiement->getObjet()) {
            $paiement->getInscriptionStage()?->setPaye(true);
            $this->em->flush();

            return true;
        }
        if (PaiementEnLigne::BOUTIQUE === $paiement->getObjet()) {
            $paiement->getCommande()?->marquerPayee(Cotisation::MODE_EN_LIGNE);
            $this->em->flush();

            return true;
        }
        $cotisation = $paiement->getCotisation();
        foreach ($commande['payments'] ?? [] as $p) {
            $id = (int) ($p['id'] ?? 0);
            if (!$id || !\in_array($p['state'] ?? '', self::ENCAISSE, true) || $paiement->dejaEnregistre($id) || !$cotisation) {
                continue;
            }
            $date = isset($p['date']) ? new \DateTimeImmutable($p['date']) : new \DateTimeImmutable();
            $versement = (new Paiement($cotisation, $date->setTime(0, 0), ((int) ($p['amount'] ?? 0)) / 100, Cotisation::MODE_EN_LIGNE,
                \sprintf('HelloAsso n° %d%s', $id, $paiement->getEcheances() > 1 ? \sprintf(' (paiement en %d fois)', $paiement->getEcheances()) : '')))
                ->setClub($cotisation->getClub());
            $cotisation->ajouterPaiement($versement);
            $this->em->persist($versement);
            $paiement->noterEnregistre($id);
        }
        $this->em->flush();

        return true;
    }

    private function creerCotisation(Joueur $joueur, string $saison): Cotisation
    {
        $cotisation = (new Cotisation($joueur, $saison))->setClub($joueur->getClub());
        $this->em->persist($cotisation);

        return $cotisation;
    }
}
