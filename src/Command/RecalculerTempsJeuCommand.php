<?php

namespace App\Command;

use App\Entity\ActionMatch;
use App\Entity\Rencontre;
use App\Service\ClubCourant;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Recalcule les temps de jeu des feuilles de match complétées depuis le match en direct (remplacements, cartons
 * et joueurs reportés sans passer par le formulaire de la feuille), avec la règle de la feuille de match.
 * Seuls les matchs avec des changements ou cartons blancs notés en direct sont concernés ; --essai affiche sans enregistrer.
 */
#[AsCommand(name: 'app:recalculer-temps-jeu', description: 'Recalcule les temps de jeu des matchs notés en direct')]
class RecalculerTempsJeuCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly ClubCourant $clubCourant)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('essai', null, InputOption::VALUE_NONE, 'Afficher les changements sans les enregistrer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        // Tous les clubs.
        $this->clubCourant->desactiver();
        // Matchs avec des changements ou cartons blancs notés en direct : ceux dont le report a complété les remplacements.
        // Les autres feuilles gardent leurs temps (saisis à la main sur d'anciens matchs, par exemple).
        $ids = array_column($this->em->createQueryBuilder()->select('DISTINCT IDENTITY(a.rencontre) AS id')->from(ActionMatch::class, 'a')
            ->andWhere('a.type IN (:types)')->setParameter('types', array_keys(ActionMatch::HORS_JEU))
            ->getQuery()->getArrayResult(), 'id');
        $changes = 0;
        foreach ($ids as $id) {
            $rencontre = $this->em->find(Rencontre::class, $id);
            if (!$rencontre || $rencontre->getParticipations()->isEmpty()) {
                continue;
            }
            $avant = [];
            foreach ($rencontre->getParticipations() as $p) {
                $avant[spl_object_id($p)] = $p->getTempsJeu();
            }
            $rencontre->calculerTempsJeu();
            $lignes = [];
            foreach ($rencontre->getParticipations() as $p) {
                if ($avant[spl_object_id($p)] !== $p->getTempsJeu()) {
                    $lignes[] = \sprintf('%s %s : %s → %d min', $p->getJoueur()?->getPrenom(), $p->getJoueur()?->getNom(), $avant[spl_object_id($p)] ?? '—', $p->getTempsJeu());
                }
            }
            if ($lignes) {
                ++$changes;
                $io->section(\sprintf('Match %d du %s contre %s', $rencontre->getId(), $rencontre->getDate()?->format('d/m/Y'), $rencontre->getAdversaire()));
                $io->listing($lignes);
            }
        }
        if (!$input->getOption('essai')) {
            $this->em->flush();
        }
        $io->success(\sprintf('%d match(s) à corriger%s.', $changes, $input->getOption('essai') ? ' (essai : rien enregistré)' : ', corrigé(s)'));

        return Command::SUCCESS;
    }
}
