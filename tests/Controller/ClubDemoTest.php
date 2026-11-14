<?php

namespace App\Tests\Controller;

use App\Command\ClubDemoCommand;
use App\Entity\Club;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Club de démonstration (app:club-demo) : créé sans erreur, ses pages principales s'affichent pour ses trois comptes
 * (président, éducateur, parent), puis il est supprimé sans toucher aux autres clubs.
 */
class ClubDemoTest extends WebTestCase
{
    public function testPagesDuClubDemo(): void
    {
        $client = static::createClient();
        $commande = (new Application(static::$kernel))->find('app:club-demo');
        $testeur = new CommandTester($commande);
        $testeur->execute(['--reinitialiser' => true, '--mot-de-passe' => 'Demo-essai-2026']);
        if (str_contains($testeur->getDisplay(), 'existe déjà')) {
            $testeur->execute(['--reinitialiser' => true, '--mot-de-passe' => 'Demo-essai-2026']);
        }
        self::assertStringContainsString('créé', $testeur->getDisplay());
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $compte = fn (string $e) => $em->getRepository(Utilisateur::class)->findOneBy(['email' => "$e@demo.example.org"]);

        $erreurs = [];
        $client->loginUser($compte('president'));
        foreach (['/', '/joueurs', '/joueurs/effectifs', '/joueurs/listing?qui=tous', '/encadrement/educateurs', '/organigramme', '/numeros-utiles',
            '/calendrier', '/matchs', '/statistiques', '/planning', '/messages', '/taches', '/stages', '/tournois', '/evenements', '/finances', '/finances/cotisations',
            '/boutique', '/boutique/articles', '/transport', '/sponsors', '/discipline', '/infirmerie', '/tests', '/ressentis', '/club/paiement-en-ligne', '/utilisateurs/droits'] as $url) {
            $client->request('GET', $url);
            $code = $client->getResponse()->getStatusCode();
            if (200 !== $code && !($code >= 300 && $code < 400 && str_contains((string) $client->getResponse()->headers->get('Location'), '/medical/code'))) {
                $erreurs[] = "président $url → $code";
            }
        }
        $client->loginUser($compte('educateur'));
        foreach (['/', '/joueurs', '/calendrier', '/matchs'] as $url) {
            $client->request('GET', $url);
            if (200 !== $client->getResponse()->getStatusCode()) {
                $erreurs[] = "éducateur $url → " . $client->getResponse()->getStatusCode();
            }
        }
        $client->loginUser($compte('parent'));
        foreach (['/espace', '/espace/calendrier', '/espace/boutique', '/espace/messages'] as $url) {
            $client->request('GET', $url);
            if (200 !== $client->getResponse()->getStatusCode()) {
                $erreurs[] = "parent $url → " . $client->getResponse()->getStatusCode();
            }
        }
        self::assertSame([], $erreurs);

        // Suppression : le club de démonstration disparaît, les autres clubs restent.
        $autres = $em->getConnection()->fetchOne('SELECT COUNT(*) FROM club') - 1;
        $testeur->execute(['--supprimer' => true]);
        $em->clear();
        self::assertNull($em->getRepository(Club::class)->findOneBy(['nom' => ClubDemoCommand::NOM]));
        self::assertSame($autres, (int) $em->getConnection()->fetchOne('SELECT COUNT(*) FROM club'));
        self::assertSame(0, (int) $em->getConnection()->fetchOne("SELECT COUNT(*) FROM utilisateur WHERE email LIKE '%@demo.example.org'"));
    }
}
