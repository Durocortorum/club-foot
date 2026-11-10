<?php

namespace App\Tests\Service;

use App\Entity\Categorie;
use App\Entity\Classement;
use App\Entity\Rencontre;
use App\Entity\ResultatPoule;
use App\Service\CalculClassement;
use PHPUnit\Framework\TestCase;

class CalculClassementTest extends TestCase
{
    private function ligne(string $equipe, int $points, int $joues, int $g, int $n, int $p, int $pour, int $contre): array
    {
        return ['rang' => 0, 'equipe' => $equipe, 'points' => $points, 'joues' => $joues, 'gagnes' => $g, 'nuls' => $n, 'perdus' => $p, 'pour' => $pour, 'contre' => $contre, 'diff' => $pour - $contre];
    }

    public function testOfficielPlusMatchsJouesDepuis(): void
    {
        Rencontre::reconnaitreClub('Stade de Reims');
        $u19 = (new Categorie())->setNom('U19 NAT')->setSecteur('formation');
        // Classement officiel importé aujourd'hui.
        $classement = (new Classement($u19, '2026/2027'))->setLignes([
            $this->ligne('ESTAC TROYES', 24, 10, 7, 3, 0, 25, 11),
            $this->ligne("FC D'ANNECY", 20, 10, 6, 2, 2, 19, 11),
            $this->ligne('CLERMONT FOOT 63', 17, 10, 5, 2, 3, 18, 9),
            $this->ligne('STADE DE REIMS', 9, 10, 2, 3, 5, 13, 22),
        ]);
        $demain = new \DateTimeImmutable('+1 day');
        $resultats = [
            new ResultatPoule($u19, '2026/2027', $demain, 'ESTAC TROYES', "FC D'ANNECY", 0, 2),
            // Joué avant l'officiel : déjà compté, ignoré.
            new ResultatPoule($u19, '2026/2027', new \DateTimeImmutable('-3 days'), 'ESTAC TROYES', 'CLERMONT FOOT 63', 5, 0),
        ];
        // Notre match de demain contre Clermont, gagné 2-1 à domicile (adversaire écrit sans « FOOT 63 »).
        $match = (new Rencontre())->setDate($demain)->setCategorie($u19)->setCompetition('Championnat')
            ->setDomicile('STADE DE REIMS')->setExterieur('CLERMONT')->setButsDomicile(2)->setButsExterieur(1);

        $r = (new CalculClassement())->calculer($classement, $resultats, [$match]);
        self::assertSame(2, $r['appliques']);
        self::assertSame(1, $r['deja_comptes']);
        $parEquipe = array_column($r['lignes'], null, 'equipe');
        self::assertSame([24, 11, 25, 13], [$parEquipe['ESTAC TROYES']['points'], $parEquipe['ESTAC TROYES']['joues'], $parEquipe['ESTAC TROYES']['pour'], $parEquipe['ESTAC TROYES']['contre']]);
        self::assertSame([23, 11, 7], [$parEquipe["FC D'ANNECY"]['points'], $parEquipe["FC D'ANNECY"]['joues'], $parEquipe["FC D'ANNECY"]['gagnes']]);
        self::assertSame([12, 11, 3, 15, 23], [$parEquipe['STADE DE REIMS']['points'], $parEquipe['STADE DE REIMS']['joues'], $parEquipe['STADE DE REIMS']['gagnes'], $parEquipe['STADE DE REIMS']['pour'], $parEquipe['STADE DE REIMS']['contre']]);
        self::assertSame([17, 4], [$parEquipe['CLERMONT FOOT 63']['points'], $parEquipe['CLERMONT FOOT 63']['perdus']]);
        self::assertSame(['ESTAC TROYES', "FC D'ANNECY", 'CLERMONT FOOT 63', 'STADE DE REIMS'], array_column($r['lignes'], 'equipe'));
        self::assertSame([1, 2, 3, 4], array_column($r['lignes'], 'rang'));

        // Pénalité : Troyes -3 passe derrière Annecy.
        $classement->ajuster('ESTAC TROYES', -3);
        $r = (new CalculClassement())->calculer($classement, $resultats, [$match]);
        self::assertSame(["FC D'ANNECY", 'ESTAC TROYES'], \array_slice(array_column($r['lignes'], 'equipe'), 0, 2));
        self::assertSame(-3, array_column($r['lignes'], null, 'equipe')['ESTAC TROYES']['ajustement']);
    }

    public function testRienARecalculer(): void
    {
        $u19 = (new Categorie())->setNom('U19 NAT')->setSecteur('formation');
        $classement = (new Classement($u19, '2026/2027'))->setLignes([$this->ligne('ESTAC TROYES', 24, 10, 7, 3, 0, 25, 11)]);
        self::assertNull((new CalculClassement())->calculer($classement, [], []), 'officiel affiché tel quel');
    }

    public function testEgaliteDePoints(): void
    {
        $u19 = (new Categorie())->setNom('U19')->setSecteur('formation');
        $classement = new Classement($u19, '2026/2027'); // aucun officiel : tout vient des scores saisis
        $d = new \DateTimeImmutable('today');
        $r = (new CalculClassement())->calculer($classement, [
            new ResultatPoule($u19, '2026/2027', $d, 'A', 'B', 3, 0),
            new ResultatPoule($u19, '2026/2027', $d, 'C', 'D', 1, 0),
        ], []);
        // A et C : 3 points ; A devant à la différence de buts.
        self::assertSame(['A', 'C', 'D', 'B'], array_column($r['lignes'], 'equipe'));
    }
}
