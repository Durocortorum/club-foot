<?php

namespace App\Controller;

use App\Entity\CombinaisonCpa;
use App\Entity\Exercice;
use App\Entity\Rencontre;
use App\Entity\Seance;
use App\Entity\TableauTactique;
use App\Entity\Utilisateur;
use App\Repository\CombinaisonCpaRepository;
use App\Repository\RencontreRepository;
use App\Repository\SeanceRepository;
use App\Repository\TableauTactiqueRepository;
use App\Security\ActiviteVoter;
use App\Security\JoueurVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Bibliothèque de coups de pied arrêtés du club : corners, coups francs, touches (offensifs ou défensifs), avec
 * schéma animé, rôle de chaque joueur et consignes ; copiés dans le tableau tactique d'un match ou dans une séance.
 */
#[Route('/cpa')]
class CpaController extends AbstractController
{
    public function __construct(
        private readonly CombinaisonCpaRepository $combinaisons,
        private readonly EntityManagerInterface $em,
        private readonly JoueurVoter $voter,
    ) {
    }

    #[Route('', name: 'app_cpa', methods: ['GET'])]
    public function index(Request $request, SeanceRepository $seances, RencontreRepository $rencontres): Response
    {
        $this->verifierAcces();
        $type = $request->query->getString('type');
        $type = isset(CombinaisonCpa::TYPES[$type]) ? $type : 'corner';
        $cote = $request->query->getString('cote');
        $cote = isset(CombinaisonCpa::COTES[$cote]) ? $cote : null;
        // Arrivée depuis un match (tableau tactique) ou une séance : un bouton « Ajouter » direct.
        $match = $request->query->getInt('match') ? $rencontres->find($request->query->getInt('match')) : null;
        $match = $match && $this->isGranted(ActiviteVoter::MODIFIER_MATCH, $match) ? $match : null;
        $seance = $request->query->getInt('seance') ? $seances->find($request->query->getInt('seance')) : null;
        $seance = $seance && $this->isGranted(ActiviteVoter::GERER, $seance) ? $seance : null;

        return $this->render('cpa/index.html.twig', [
            'type' => $type,
            'cote' => $cote,
            'types' => CombinaisonCpa::TYPES,
            'cotes' => CombinaisonCpa::COTES,
            'nombres' => $this->combinaisons->compterParType(),
            'combinaisons' => $this->combinaisons->duType($type, $cote),
            'match' => $match,
            'seance' => $seance,
            'matchs' => $match || $seance ? [] : $this->matchsAVenir($rencontres),
            'seances' => $match || $seance ? [] : $seances->destinations($this->voter->secteursModifiables()),
        ]);
    }

    #[Route('/nouvelle', name: 'app_cpa_nouvelle', methods: ['GET', 'POST'])]
    public function nouvelle(Request $request): Response
    {
        $this->verifierAcces();
        $type = $request->query->getString('type');
        $combinaison = (new CombinaisonCpa())->setType($type)->setSchema(self::schemaDeDepart($type))->setRoles(self::rolesDeDepart($type));

        return $this->formulaire($request, $combinaison, 'Nouvelle combinaison');
    }

    #[Route('/{id}/modifier', name: 'app_cpa_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Request $request, CombinaisonCpa $combinaison): Response
    {
        $this->verifierAcces();

        return $this->formulaire($request, $combinaison, 'Modifier « ' . $combinaison->getTitre() . ' »');
    }

    #[Route('/{id}/dupliquer', name: 'app_cpa_dupliquer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function dupliquer(Request $request, CombinaisonCpa $combinaison): Response
    {
        $this->verifierAcces();
        if (!$this->isCsrfTokenValid('cpa' . $combinaison->getId(), $request->getPayload()->getString('_token'))) {
            return $this->redirectToRoute('app_cpa', ['type' => $combinaison->getType()]);
        }
        $copie = (new CombinaisonCpa())->setTitre(mb_substr($combinaison->getTitre() . ' (variante)', 0, 100))->setType($combinaison->getType())
            ->setCote($combinaison->getCote())->setConsignes($combinaison->getConsignes())->setSchema($combinaison->getSchema())->setRoles($combinaison->getRoles());
        $this->tracer($copie);
        $this->em->persist($copie);
        $this->em->flush();

        return $this->redirectToRoute('app_cpa_modifier', ['id' => $copie->getId()]);
    }

    #[Route('/{id}/supprimer', name: 'app_cpa_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimer(Request $request, CombinaisonCpa $combinaison): Response
    {
        $this->verifierAcces();
        if ($this->isCsrfTokenValid('supprimer-cpa' . $combinaison->getId(), $request->getPayload()->getString('_token'))) {
            $this->em->remove($combinaison);
            $this->em->flush();
            $this->addFlash('succes', 'Combinaison supprimée de la bibliothèque.');
        }

        return $this->redirectToRoute('app_cpa', ['type' => $combinaison->getType()]);
    }

    /** Copie la combinaison en nouveau tableau du tableau tactique d'un match. */
    #[Route('/{id}/match', name: 'app_cpa_vers_match', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function versMatch(Request $request, CombinaisonCpa $combinaison, RencontreRepository $rencontres, TableauTactiqueRepository $tableaux): Response
    {
        $this->verifierAcces();
        $match = $rencontres->find($request->getPayload()->getInt('match'));
        if (!$match || !$this->isGranted(ActiviteVoter::MODIFIER_MATCH, $match) || !$this->isCsrfTokenValid('cpa' . $combinaison->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('erreur', 'Choisissez un match de vos équipes.');

            return $this->redirectToRoute('app_cpa', ['type' => $combinaison->getType()]);
        }
        $tableau = (new TableauTactique($match))->setTitre($combinaison->getIcone() . ' ' . $combinaison->getTitre())->setConsignes($combinaison->texteComplet())
            ->setSchema($combinaison->getSchema())->setOrdre($tableaux->ordreSuivant($match));
        if (($moi = $this->getUser()) instanceof Utilisateur) {
            $tableau->marquerModifie($moi);
        }
        $this->em->persist($tableau);
        $this->em->flush();
        $this->addFlash('succes', '« ' . $combinaison->getTitre() . ' » ajouté au tableau tactique du match. Modifiez-le pour l\'adapter (numéros de vos joueurs).');

        return $this->redirectToRoute('app_tactique', ['id' => $match->getId()]);
    }

    /** Copie la combinaison en exercice d'une séance d'entraînement (mise en place des coups de pied arrêtés). */
    #[Route('/{id}/seance', name: 'app_cpa_vers_seance', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function versSeance(Request $request, CombinaisonCpa $combinaison, SeanceRepository $seances): Response
    {
        $this->verifierAcces();
        $seance = $seances->find($request->getPayload()->getInt('seance'));
        if (!$seance instanceof Seance || !$this->isGranted(ActiviteVoter::GERER, $seance) || !$this->isCsrfTokenValid('cpa' . $combinaison->getId(), $request->getPayload()->getString('_token'))) {
            $this->addFlash('erreur', 'Choisissez une séance de vos équipes.');

            return $this->redirectToRoute('app_cpa', ['type' => $combinaison->getType()]);
        }
        $exercice = (new Exercice())->setTitre(mb_substr($combinaison->getTypeLibelle() . ' : ' . $combinaison->getTitre(), 0, 150))->setDuree(15)
            ->setConsignes($combinaison->texteComplet())->setSchema($combinaison->getSchema());
        $seance->addExercice($exercice);
        $this->em->flush();
        $this->addFlash('succes', '« ' . $combinaison->getTitre() . ' » ajouté à la séance du ' . $seance->getDate()->format('d/m/Y') . '.');

        return $this->redirectToRoute('app_seance_voir', ['id' => $seance->getId(), '_fragment' => 'exercice-' . $exercice->getId()]);
    }

    private function formulaire(Request $request, CombinaisonCpa $combinaison, string $titre): Response
    {
        $erreur = null;
        if ($request->isMethod('POST')) {
            $d = $request->getPayload();
            if (!$this->isCsrfTokenValid('combinaison-cpa', $d->getString('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $schema = json_decode($d->getString('schema'), true);
            $roles = [];
            foreach ($d->all('roles') as $r) {
                if (\is_array($r)) {
                    $roles[] = $r;
                }
            }
            $combinaison->setTitre($d->getString('titre'))->setType($d->getString('type'))->setCote($d->getString('cote'))
                ->setConsignes($d->getString('consignes'))->setSchema(\is_array($schema) ? $schema : [])->setRoles($roles);
            if ('' === $combinaison->getTitre()) {
                $erreur = 'Donnez un nom à la combinaison (ex. : Corner rentrant au 2e poteau).';
            } else {
                $this->tracer($combinaison);
                $this->em->persist($combinaison);
                $this->em->flush();
                $this->addFlash('succes', 'Combinaison « ' . $combinaison->getTitre() . ' » enregistrée dans la bibliothèque.');

                return $this->redirectToRoute('app_cpa', ['type' => $combinaison->getType(), '_fragment' => 'cpa-' . $combinaison->getId()]);
            }
        }

        return $this->render('cpa/formulaire.html.twig', [
            'titre' => $titre,
            'combinaison' => $combinaison,
            'erreur' => $erreur,
        ]);
    }

    /** Matchs à venir des équipes que l'on tient (pour y copier une combinaison). @return list<Rencontre> */
    private function matchsAVenir(RencontreRepository $rencontres): array
    {
        $matchs = $rencontres->createQueryBuilder('r')->andWhere('r.date >= :d')->setParameter('d', new \DateTimeImmutable('today'))
            ->orderBy('r.date', 'ASC')->setMaxResults(60)->getQuery()->getResult();

        return \array_slice(array_values(array_filter($matchs, fn (Rencontre $r) => !$r->isPlateau() && $this->isGranted(ActiviteVoter::MODIFIER_MATCH, $r))), 0, 25);
    }

    /**
     * Schéma de départ selon le type, sur un demi-terrain (but en haut) : corner (ballon au point de corner, tireur,
     * attaquants, gardien et défenseurs), coup franc (ballon à 22 m, mur), touche (ballon sur la ligne de touche).
     *
     * @return array<string, mixed>
     */
    public static function schemaDeDepart(?string $type): array
    {
        $j = fn (float $x, float $y, string $n, string $c = 'rouge') => ['t' => 'joueur', 'x' => $x, 'y' => $y, 'c' => $c, 'n' => $n];
        // Le demi-terrain dessine déjà le but (en haut).
        $gardien = $j(37, 5, '1', 'bleu');
        $objets = match ($type) {
            'coup_franc' => [$gardien, ['t' => 'ballon', 'x' => 30, 'y' => 26], $j(29, 28, '10'), $j(33, 30, '8'),
                $j(31, 19, '', 'bleu'), $j(32.6, 19, '', 'bleu'), $j(34.2, 19, '', 'bleu'), $j(35.8, 19, '', 'bleu'),
                $j(40, 14, '9'), $j(45, 15, '5'), $j(43, 12, '', 'bleu')],
            'touche' => [$gardien, ['t' => 'ballon', 'x' => 2, 'y' => 28], $j(3, 28, '2'), $j(10, 24, '7'), $j(12, 32, '8'), $j(18, 26, '9'),
                $j(11, 27, '', 'bleu'), $j(16, 30, '', 'bleu')],
            // Surface de réparation : y de 3 à 19,5 ; point de penalty à y = 14.
            default => [$gardien, ['t' => 'ballon', 'x' => 4, 'y' => 4], $j(2.5, 6, '10'),
                $j(31, 13, '5'), $j(37, 15, '9'), $j(44, 13, '4'), $j(37, 23, '8'), $j(37, 36, '7'),
                $j(32, 9, '', 'bleu'), $j(40, 9, '', 'bleu'), $j(44, 10, '', 'bleu'), $j(36, 12, '', 'bleu')],
        };

        return ['terrain' => 'demi', 'objets' => $objets];
    }

    /**
     * Rôles proposés avec le schéma de départ (mêmes numéros), à adapter.
     *
     * @return list<array{n: string, role: string, detail: ?string}>
     */
    public static function rolesDeDepart(?string $type): array
    {
        $r = fn (string $n, string $role, ?string $detail = null) => ['n' => $n, 'role' => $role, 'detail' => $detail];

        return match ($type) {
            'coup_franc' => [$r('10', 'Tireur', 'frappe directe ou centre'), $r('8', 'Leurre', 'feinte de frappe'), $r('9', 'Finition au point de penalty'), $r('5', 'Finition 2e poteau')],
            'touche' => [$r('2', 'Tireur', 'touche longue ou courte'), $r('7', 'Appel', 'vient au ballon'), $r('9', 'Appel en profondeur', 'dans le dos'), $r('8', 'Soutien (zone de reprise)')],
            default => [$r('10', 'Tireur', 'corner'), $r('5', 'Finition 1er poteau', 'attaque le 1er poteau'), $r('9', 'Finition au point de penalty'), $r('4', 'Finition 2e poteau'),
                $r('8', 'Soutien (zone de reprise)', 'entrée de surface'), $r('7', 'Repli', 'couvre la contre-attaque')],
        };
    }

    private function verifierAcces(): void
    {
        // Encadrement qui gère au moins un secteur (comme la bibliothèque d'exercices).
        if (!$this->voter->secteursModifiables()) {
            throw $this->createAccessDeniedException();
        }
    }

    private function tracer(CombinaisonCpa $combinaison): void
    {
        if (($moi = $this->getUser()) instanceof Utilisateur) {
            $combinaison->marquerModifie($moi);
        }
    }
}
