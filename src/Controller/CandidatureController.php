<?php

namespace App\Controller;

use App\Entity\Candidature;
use App\Entity\ContactMedical;
use App\Entity\Intervenant;
use App\Form\CandidatureType;
use App\Repository\CandidatureRepository;
use App\Service\Documents;
use App\Service\Encadrement;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Recrutement de l'encadrement : candidatures (CV) d'éducateurs, dirigeants, administratifs,
 * bénévoles et du corps médical. Données personnelles : réservé à l'administration.
 */
#[Route('/recrutement/candidatures')]
#[IsGranted('ROLE_ADMIN')]
class CandidatureController extends AbstractController
{
    /** Durée de conservation conseillée par la CNIL pour une candidature non retenue. */
    public const CONSERVATION = '-2 years';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Documents $documents,
    ) {
    }

    #[Route('', name: 'app_candidature_index', methods: ['GET'])]
    public function index(Request $request, CandidatureRepository $candidatures): Response
    {
        $filtres = [
            'q' => trim($request->query->getString('q')) ?: null,
            'poste' => \array_key_exists($p = $request->query->getString('poste'), Candidature::POSTES) ? $p : null,
            'statut' => \array_key_exists($s = $request->query->getString('statut'), Candidature::STATUTS) ? $s : null,
        ];
        $qb = $candidatures->createQueryBuilder('c')
            ->orderBy('c.dateReception', 'DESC')->addOrderBy('c.nom');
        if ($filtres['q']) {
            $qb->andWhere('c.nom LIKE :t OR c.prenom LIKE :t OR c.fonction LIKE :t OR c.diplomes LIKE :t OR c.ville LIKE :t')
                ->setParameter('t', '%' . $filtres['q'] . '%');
        }
        if ($filtres['poste']) {
            $qb->andWhere('c.posteVise = :poste')->setParameter('poste', $filtres['poste']);
        }
        if ($filtres['statut']) {
            $qb->andWhere('c.statut = :statut')->setParameter('statut', $filtres['statut']);
        }

        return $this->render('recrutement/candidatures.html.twig', [
            'candidatures' => $qb->getQuery()->getResult(),
            'filtres' => $filtres,
            'postes' => Candidature::POSTES,
            'statuts' => Candidature::STATUTS,
            'limite_conservation' => new \DateTimeImmutable(self::CONSERVATION),
        ]);
    }

    #[Route('/nouvelle', name: 'app_candidature_nouvelle', methods: ['GET', 'POST'])]
    public function nouvelle(Request $request): Response
    {
        $candidature = (new Candidature())->setDateReception(new \DateTimeImmutable('today'));
        if (\array_key_exists($p = $request->query->getString('poste'), Candidature::POSTES)) {
            $candidature->setPosteVise($p);
        }

        return $this->formulaire($request, $candidature, 'Nouvelle candidature');
    }

    #[Route('/{id}', name: 'app_candidature_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(Candidature $candidature): Response
    {
        return $this->render('recrutement/candidature.html.twig', [
            'c' => $candidature,
            'fiche' => $this->lienFiche($candidature),
            'a_supprimer' => $this->aSupprimer($candidature),
            'deja_connu' => $candidature->getFiche() ? [] : $this->dejaConnu($candidature),
            'meme_type' => null !== $this->ficheExistante($candidature, $candidature->getPosteVise()),
        ]);
    }

    #[Route('/{id}/modifier', name: 'app_candidature_modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Request $request, Candidature $candidature): Response
    {
        return $this->formulaire($request, $candidature, 'Modifier la candidature de ' . $candidature->getNomComplet());
    }

    #[Route('/{id}/cv', name: 'app_candidature_cv', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function cv(Candidature $candidature): Response
    {
        $chemin = $candidature->getCv() ? $this->documents->chemin($candidature->getCv()) : null;
        if (!$chemin) {
            throw $this->createNotFoundException('Aucun CV joint à cette candidature.');
        }

        return (new BinaryFileResponse($chemin))
            ->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, sprintf('CV %s %s.pdf', $candidature->getNom(), $candidature->getPrenom()), 'cv.pdf')
            ->setPrivate();
    }

    /**
     * La personne rejoint le club : sa fiche est créée dans l'encadrement (ou parmi les contacts
     * médicaux de l'infirmerie) avec ses coordonnées, ses diplômes et son parcours.
     */
    #[Route('/{id}/integrer', name: 'app_candidature_integrer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function integrer(Request $request, Candidature $candidature): Response
    {
        if (!$this->isCsrfTokenValid('integrer-candidature' . $candidature->getId(), $request->getPayload()->getString('_token'))) {
            return $this->redirectToRoute('app_candidature_voir', ['id' => $candidature->getId()]);
        }
        if ($this->lienFiche($candidature)) {
            $this->addFlash('erreur', $candidature->getNomComplet() . ' a déjà une fiche au club.');

            return $this->redirectToRoute('app_candidature_voir', ['id' => $candidature->getId()]);
        }

        $type = $candidature->getPosteVise();
        // Pas de doublon : une fiche du même type au même nom (ancien compris) est reprise telle quelle.
        $fiche = $this->ficheExistante($candidature, $type);
        if ($fiche) {
            if ($fiche instanceof Intervenant && $fiche->isAncien()) {
                $fiche->setAncien(false);
            }
            $message = '%s avait déjà une fiche au club : la candidature y est rattachée, aucune fiche en double.';
        } else {
            $fiche = 'medical' === $type ? $this->contactMedical($candidature) : $this->intervenant($candidature, $type);
            $this->em->persist($fiche);
            $message = '%s a rejoint le club : sa fiche a été créée.';
        }
        $this->em->flush();
        $candidature->setStatut('retenu')->setFiche($type . ':' . $fiche->getId());
        $this->em->flush();
        $this->addFlash('succes', sprintf($message, $candidature->getNomComplet()));

        $lien = $this->lienFiche($candidature);

        return $this->redirect($lien['url'] ?? $this->generateUrl('app_candidature_voir', ['id' => $candidature->getId()]));
    }

    #[Route('/{id}/supprimer', name: 'app_candidature_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimer(Request $request, Candidature $candidature): Response
    {
        if ($this->isCsrfTokenValid('supprimer-candidature' . $candidature->getId(), $request->getPayload()->getString('_token'))) {
            $cv = $candidature->getCv();
            $this->em->remove($candidature);
            $this->em->flush();
            $this->documents->supprimer($cv);
            $this->addFlash('succes', 'Candidature et CV supprimés.');
        }

        return $this->redirectToRoute('app_candidature_index');
    }

    private function formulaire(Request $request, Candidature $candidature, string $titre): Response
    {
        $form = $this->createForm(CandidatureType::class, $candidature);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($pdf = $form->get('document')->getData()) {
                $candidature->setCv($this->documents->enregistrer($pdf, 'cv', $candidature->getCv()));
            }
            $this->em->persist($candidature);
            $this->em->flush();
            $this->addFlash('succes', 'Candidature enregistrée.');

            return $this->redirectToRoute('app_candidature_voir', ['id' => $candidature->getId()]);
        }

        return $this->render('recrutement/candidature_formulaire.html.twig', [
            'form' => $form,
            'c' => $candidature,
            'titre' => $titre,
        ]);
    }

    private function intervenant(Candidature $c, string $type): Intervenant
    {
        $classe = Encadrement::TYPES[$type]['classe'];
        $memo = array_filter([
            $c->getExperience(),
            $c->getDisponibilites() ? 'Disponibilités : ' . $c->getDisponibilites() : null,
            $c->getClubActuel() ? 'Club précédent : ' . $c->getClubActuel() : null,
        ]);

        /** @var Intervenant $fiche */
        $fiche = new $classe();

        return $fiche->setNom((string) $c->getNom())->setPrenom((string) $c->getPrenom())
            ->setDateNaissance($c->getDateNaissance())
            ->setAdresse($c->getAdresse())->setCodePostal($c->getCodePostal())->setVille($c->getVille())
            ->setTelPortable1($c->getTelephone())->setEmail($c->getEmail())
            ->setFonction($c->getFonction())->setDiplome($c->getDiplomes())
            ->setMemo($memo ? implode("\n\n", $memo) : null);
    }

    private function contactMedical(Candidature $c): ContactMedical
    {
        return (new ContactMedical())->setNom($c->getNom())->setPrenom($c->getPrenom())
            ->setProfession($c->getFonction() ? mb_substr($c->getFonction(), 0, 60) : null)
            ->setTelephone($c->getTelephone())
            ->setAdresse($c->getAdresse())->setCodePostal($c->getCodePostal())
            ->setVille($c->getVille() ? mb_substr($c->getVille(), 0, 60) : null);
    }

    /**
     * Fiche créée au club, si elle existe encore : adresse et libellé du lien.
     *
     * @return array{url: string, libelle: string}|null
     */
    private function lienFiche(Candidature $c): ?array
    {
        if (!$c->getFiche() || !preg_match('/^([a-z]+):(\d+)$/', $c->getFiche(), $m)) {
            return null;
        }
        [, $type, $id] = $m;
        if ('medical' === $type) {
            return $this->em->find(ContactMedical::class, (int) $id)
                ? ['url' => $this->generateUrl('app_contact_modifier', ['id' => $id]), 'libelle' => 'contact médical de l\'infirmerie']
                : null;
        }
        if (!isset(Encadrement::TYPES[$type]) || !$this->em->find(Encadrement::TYPES[$type]['classe'], (int) $id)) {
            return null;
        }

        return [
            'url' => $this->generateUrl('app_intervenant_voir', ['type' => $type, 'id' => $id]),
            'libelle' => 'fiche ' . mb_strtolower($c->getPosteViseLibelle()),
        ];
    }

    /**
     * Fiche du type donné au même nom et prénom (la base ignore majuscules et accents), anciens compris.
     */
    private function ficheExistante(Candidature $c, string $type): Intervenant|ContactMedical|null
    {
        $classe = 'medical' === $type ? ContactMedical::class : (Encadrement::TYPES[$type]['classe'] ?? null);
        if (!$classe) {
            return null;
        }

        return $this->em->getRepository($classe)->createQueryBuilder('f')
            ->where('f.nom = :nom AND f.prenom = :prenom')
            ->setParameter('nom', $c->getNom())->setParameter('prenom', trim((string) $c->getPrenom()))
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /**
     * Fiches au même nom dans tout le club (encadrement, arbitres, contacts médicaux), pour prévenir un doublon.
     *
     * @return list<array{url: string, libelle: string}>
     */
    private function dejaConnu(Candidature $c): array
    {
        $connu = [];
        foreach (Encadrement::TYPES as $type => $t) {
            if ($f = $this->ficheExistante($c, $type)) {
                $connu[] = [
                    'url' => $this->generateUrl('app_intervenant_voir', ['type' => $type, 'id' => $f->getId()]),
                    'libelle' => $t['libelle'] . ($f->isAncien() ? ' (ancien)' : '') . ($f->getFonction() ? ' – ' . $f->getFonction() : ''),
                ];
            }
        }
        if ($f = $this->ficheExistante($c, 'medical')) {
            $connu[] = ['url' => $this->generateUrl('app_contact_modifier', ['id' => $f->getId()]), 'libelle' => 'Contact médical' . ($f->getProfession() ? ' – ' . $f->getProfession() : '')];
        }

        return $connu;
    }

    /** Candidature non retenue reçue il y a plus de deux ans : à supprimer. */
    private function aSupprimer(Candidature $c): bool
    {
        return 'retenu' !== $c->getStatut() && $c->getDateReception() && $c->getDateReception() < new \DateTimeImmutable(self::CONSERVATION);
    }
}
