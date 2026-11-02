<?php

namespace App\Command;

use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:utilisateur:mot-de-passe', description: 'Définit le mot de passe d\'un utilisateur')]
class MotDePasseCommand extends Command
{
    public function __construct(
        private readonly UtilisateurRepository $utilisateurs,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Adresse e-mail de connexion');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $utilisateur = $this->utilisateurs->findOneBy(['email' => mb_strtolower($input->getArgument('email'))]);
        if (!$utilisateur) {
            $io->error('Utilisateur introuvable.');

            return Command::FAILURE;
        }

        $motDePasse = $io->askHidden('Nouveau mot de passe (8 caractères minimum)', function (?string $v) {
            if (mb_strlen((string) $v) < 8) {
                throw new \RuntimeException('Le mot de passe doit faire au moins 8 caractères.');
            }

            return $v;
        });

        $utilisateur->setPassword($this->hasher->hashPassword($utilisateur, $motDePasse));
        $this->em->flush();

        $io->success("Mot de passe défini pour {$utilisateur->getIdentifiant()}.");

        return Command::SUCCESS;
    }
}
