<?php

namespace App\Service;

use App\Entity\Club;
use App\Entity\ResultatTest;
use App\Entity\TestPhysique;
use App\Entity\TypeTest;
use App\Repository\TestPhysiqueRepository;
use App\Repository\TypeTestRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Catalogue des tests connus du football, pour tous les âges : endurance (VAMEVAL, 45-15, Léger, demi-Cooper,
 * Yo-Yo…), vitesse, agilité (Illinois, T-test, 5-0-5), puissance, technique (jonglerie, conduite, passes,
 * frappe), souplesse et gainage. Installé dans le club à la première visite, avec la reprise des anciens
 * tests physiques (tableau à colonnes repris d'Access) en résultats.
 */
class CatalogueTests
{
    /**
     * code => [nom, famille, unité, plus bas mieux, âge min, âge max, objectif, protocole, matériel].
     * Les codes des 9 anciennes épreuves (TestPhysique::EPREUVES) sont repris tels quels.
     */
    public const CATALOGUE = [
        // ——— Endurance ———
        'demiCooper' => ['Demi-Cooper (6 min)', 'Endurance', 'm', false, 8, 13,
            'Endurance et estimation de la VMA des plus jeunes.',
            "Courir la plus grande distance possible en 6 minutes autour d'un terrain balisé (plots tous les 10 m). Donner le temps restant chaque minute. À la fin, noter la distance au plot le plus proche.\nVMA estimée (km/h) ≈ distance ÷ 100.",
            'Plots, chronomètre, sifflet.'],
        'leger' => ['Luc Léger (navette 20 m)', 'Endurance', 'km/h', false, 11, null,
            'VMA par course navette.',
            "Allers-retours entre deux lignes à 20 m, au rythme d'une bande sonore : départ à 8,5 km/h, +0,5 km/h chaque minute (palier). Le joueur s'arrête quand il ne tient plus le rythme deux fois de suite.\nNoter la vitesse du dernier palier terminé.",
            'Enceinte, bande sonore Luc Léger (application), plots, 20 m de terrain plat.'],
        'vameval' => ['VAMEVAL', 'Endurance', 'km/h', false, 13, null,
            'Vitesse maximale aérobie (VMA) sur piste.',
            "Plots tous les 20 m autour du terrain. Départ à 8 km/h, puis +0,5 km/h chaque minute : le joueur doit être à hauteur d'un plot à chaque bip. Arrêt quand il a deux plots de retard ou s'arrête.\nNoter la vitesse du dernier palier terminé : c'est sa VMA.",
            'Bande sonore VAMEVAL (application), plots tous les 20 m, enceinte.'],
        'vma' => ['VMA (autre test)', 'Endurance', 'km/h', false, 11, null,
            'VMA mesurée par un autre test (repris des anciens tests).',
            'Vitesse maximale aérobie, quel que soit le test utilisé.',
            null],
        'test4515' => ['45-15 de Gacon', 'Endurance', 'km/h', false, 15, null,
            'VMA intermittente, proche de l\'effort du match.',
            "Courses de 45 s suivies de 15 s de récupération. Départ à 8 km/h, +0,5 km/h à chaque course (la distance à parcourir en 45 s augmente). Arrêt quand le joueur n'atteint pas son plot dans les 45 s.\nNoter la vitesse de la dernière course réussie.",
            'Plots (distances précalculées par palier), sifflet, chronomètre ou application.'],
        'yoyoIR1' => ['Yo-Yo Intermittent Recovery 1', 'Endurance', 'm', false, 15, null,
            'Capacité à répéter des courses intenses avec peu de récupération.',
            "Allers-retours de 2 × 20 m au rythme des bips, de plus en plus vite, avec 10 s de récupération active (5 m aller-retour) entre chaque. Deux échecs à atteindre la ligne à temps : fin.\nNoter la distance totale parcourue.",
            'Bande sonore Yo-Yo IR1 (application), plots à 0, 20 m et -5 m.'],
        'cooper' => ['Test de Cooper (12 min)', 'Endurance', 'm', false, 16, null,
            'Endurance aérobie.',
            "Courir la plus grande distance possible en 12 minutes sur une piste ou un terrain balisé.\nVMA estimée (km/h) ≈ distance ÷ 200.",
            'Plots tous les 10 m, chronomètre.'],

        // ——— Vitesse ———
        'sprint10' => ['Sprint 10 m', 'Vitesse', 's', true, 8, null,
            'Démarrage, accélération.',
            "Départ debout, pied avant sur la ligne, sans élan. Deux essais, 2 minutes de récupération, garder le meilleur.\nChronométrer avec des cellules ou une application vidéo plutôt qu'à la main.",
            'Plots, chronomètre ou cellules / application de chronométrage.'],
        'sprint20' => ['Sprint 20 m', 'Vitesse', 's', true, 8, null,
            'Accélération et vitesse.',
            'Départ debout, sans élan. Deux essais, garder le meilleur.',
            'Plots, chronomètre ou cellules.'],
        'sprint30' => ['Sprint 30 m', 'Vitesse', 's', true, 11, null,
            'Vitesse maximale de course.',
            'Départ debout, sans élan. Deux essais, 3 minutes de récupération, garder le meilleur.',
            'Plots, chronomètre ou cellules.'],
        'sprint40' => ['Sprint 40 m', 'Vitesse', 's', true, 13, null,
            'Vitesse maximale et maintien de la vitesse.',
            'Départ debout, sans élan. Deux essais, 3 minutes de récupération, garder le meilleur.',
            'Plots, chronomètre ou cellules.'],
        'frequence20' => ['20 m fréquence', 'Vitesse', 's', true, 9, null,
            'Fréquence des appuis.',
            '20 m en posant un pied dans chaque intervalle d\'une échelle de rythme (ou de cerceaux rapprochés), le plus vite possible.',
            'Échelle de rythme ou cerceaux, chronomètre.'],
        'amplitude20' => ['20 m amplitude', 'Vitesse', 's', true, 11, null,
            'Amplitude de la foulée.',
            '20 m en grandes foulées bondissantes, le plus vite possible.',
            'Plots, chronomètre.'],

        // ——— Agilité ———
        'navette4x10' => ['Navette 4 × 10 m', 'Agilité', 's', true, 8, null,
            'Vitesse avec changements de direction.',
            'Quatre longueurs de 10 m (aller-retour deux fois), en touchant la ligne au sol à chaque demi-tour. Deux essais.',
            'Plots, chronomètre.'],
        'illinois' => ['Test d\'Illinois', 'Agilité', 's', true, 12, null,
            'Agilité : accélérations, slalom et changements de direction.',
            "Parcours de 10 m × 5 m : départ allongé sur le ventre, course de 10 m, demi-tour, slalom aller-retour entre 4 plots centraux espacés de 3,3 m, puis 10 m aller-retour jusqu'à l'arrivée. Deux essais.",
            '8 plots, chronomètre, mètre ruban.'],
        'tTest' => ['T-test', 'Agilité', 's', true, 12, null,
            'Déplacements avant, latéraux et arrière.',
            "Plots en T : 10 m vers l'avant, puis 5 m pas chassés à gauche, 10 m pas chassés à droite, 5 m à gauche, et retour en courant à reculons. Toucher chaque plot. Deux essais.",
            '4 plots, chronomètre.'],
        'test505' => ['5-0-5', 'Agilité', 's', true, 14, null,
            'Demi-tour à pleine vitesse.',
            "Élan de 10 m, puis chronométrage sur 5 m aller, demi-tour sur la ligne, 5 m retour. Un essai sur chaque pied d'appui, noter le meilleur.",
            'Plots, chronomètre ou cellules.'],
        'slalomBallon' => ['Conduite de balle en slalom', 'Agilité', 's', true, 7, null,
            'Agilité balle au pied.',
            '6 plots en ligne espacés de 2 m : slalom aller et retour en conduite de balle. Plot renversé ou oublié : +1 s. Deux essais.',
            '6 plots, ballon, chronomètre.'],

        // ——— Puissance ———
        'longueurSansElan' => ['Saut en longueur sans élan', 'Puissance', 'cm', false, 8, null,
            'Explosivité des jambes.',
            'Pieds joints derrière la ligne, saut vers l\'avant à deux pieds, réception stable. Mesurer jusqu\'au talon le plus proche. Trois essais.',
            'Mètre ruban.'],
        'detente' => ['Détente verticale', 'Puissance', 'cm', false, 11, null,
            'Détente des jambes (test de Sargent).',
            'De côté contre un mur, bras tendu : marquer la hauteur atteinte. Puis sauter sans élan et marquer le point le plus haut. Différence entre les deux marques. Trois essais.',
            'Mur, craie ou ruban adhésif, mètre.'],
        'cmj' => ['Saut avec contre-mouvement (CMJ)', 'Puissance', 'cm', false, 14, null,
            'Puissance des jambes, mesure précise.',
            'Mains sur les hanches, flexion rapide puis saut vertical maximal, réception sur place. Trois essais, garder le meilleur.',
            'Tapis de saut ou application vidéo (My Jump…).'],
        'cinqFoulees' => ['5 foulées bondissantes', 'Puissance', 'm', false, 12, null,
            'Puissance et coordination des appuis.',
            'Départ pieds joints, cinq foulées bondissantes alternées, réception à deux pieds. Mesurer la distance totale. Deux essais.',
            'Mètre ruban, terrain plat.'],

        // ——— Technique ———
        'jonglePied' => ['Jonglerie pied fort', 'Technique', 'nb', false, 7, null,
            'Maîtrise du ballon.',
            'Jongler le plus longtemps possible avec le pied fort, sans que le ballon touche le sol. Deux essais, garder le meilleur (plafonner à 100 si besoin).',
            'Ballon à la taille de la catégorie.'],
        'jongleAlterne' => ['Jonglerie pied droit – pied gauche', 'Technique', 'nb', false, 9, null,
            'Maîtrise des deux pieds.',
            'Jongler en alternant obligatoirement pied droit et pied gauche. Une erreur d\'alternance arrête le compte. Deux essais.',
            'Ballon.'],
        'jongleTete' => ['Jonglerie de la tête', 'Technique', 'nb', false, 11, null,
            'Jeu de tête, coordination.',
            'Jongler uniquement de la tête, sans que le ballon touche le sol. Deux essais.',
            'Ballon.'],
        'passesMur' => ['Passes contre un mur (30 s)', 'Technique', 'nb', false, 8, null,
            'Qualité et vitesse de passe, contrôle.',
            'Face à un mur ou un banc renversé, derrière une ligne à 3 m (5 m dès U15) : nombre de passes en 30 secondes, ballon contrôlé ou joué en une touche, des deux pieds.',
            'Mur ou banc, ballon, chronomètre, plots.'],
        'frappePrecision' => ['Précision de frappe', 'Technique', 'points', false, 10, null,
            'Précision du tir.',
            "10 frappes de l'entrée de la surface (16 m) vers un but partagé en zones : coin (3 points), côté (2 points), centre (1 point), à côté (0). Cinq du pied droit, cinq du pied gauche.",
            'But, cordes ou plots pour délimiter les zones, 10 ballons.'],
        'lspt' => ['Loughborough Soccer Passing Test', 'Technique', 's', true, 14, null,
            'Passes rapides et précises sous pression du temps.',
            '16 passes vers des cibles de couleur annoncées au hasard, dans un carré de jeu. Temps total + pénalités (cible manquée +5 s, ballon hors zone +3 s…).',
            'Bancs avec cibles de couleur, ballon, chronomètre, un annonceur.'],

        // ——— Souplesse et gainage ———
        'souplesse' => ['Flexion avant assis (sit and reach)', 'Souplesse et gainage', 'cm', false, 8, null,
            'Souplesse des ischio-jambiers et du dos.',
            'Assis jambes tendues, pieds contre une boîte : glisser les mains le plus loin possible vers l\'avant, sans à-coup, et tenir 2 secondes. Zéro au niveau des pieds (négatif avant, positif au-delà).',
            'Boîte de souplesse ou règle posée sur une marche.'],
        'gainageVentral' => ['Gainage ventral (planche)', 'Souplesse et gainage', 's', false, 10, null,
            'Gainage et stabilité du tronc.',
            'En appui sur les avant-bras et les pointes de pieds, corps aligné. Tenir le plus longtemps possible : arrêt quand le bassin monte ou descend.',
            'Tapis, chronomètre.'],
        'gainageLateral' => ['Gainage latéral', 'Souplesse et gainage', 's', false, 10, null,
            'Gainage des obliques.',
            'Sur le côté, appui sur un avant-bras et le bord du pied, corps aligné. Tenir le plus longtemps possible, de chaque côté ; noter le moins bon des deux.',
            'Tapis, chronomètre.'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TypeTestRepository $types,
        private readonly TestPhysiqueRepository $anciens,
    ) {
    }

    /**
     * Catalogue du club ; à la première visite, il est créé et les anciens tests sont repris en résultats.
     *
     * @return list<TypeTest>
     */
    public function pour(Club $club): array
    {
        $existants = $this->types->findBy(['club' => $club], ['ordre' => 'ASC', 'nom' => 'ASC']);
        if ($existants) {
            return $existants;
        }
        $parCode = [];
        $ordre = 0;
        foreach (self::CATALOGUE as $code => [$nom, $famille, $unite, $plusBas, $min, $max, $objectif, $protocole, $materiel]) {
            $t = (new TypeTest())->setClub($club)->setCode($code)->setNom($nom)->setFamille($famille)->setUnite($unite)->setPlusBasMieux($plusBas)
                ->setAgeMin($min)->setAgeMax($max)->setObjectif($objectif)->setProtocole($protocole)->setMateriel($materiel)->setOrdre($ordre += 10);
            $this->em->persist($t);
            $parCode[$code] = $t;
        }
        // Anciens tests (une ligne par date, une colonne par épreuve) : un résultat par valeur.
        foreach ($this->anciens->findBy(['club' => $club]) as $ancien) {
            foreach (array_keys(TestPhysique::EPREUVES) as $code) {
                $v = $ancien->{'get' . ucfirst($code)}();
                if (null !== $v && isset($parCode[$code])) {
                    $this->em->persist((new ResultatTest($ancien->getJoueur(), $parCode[$code], $ancien->getDate(), (float) $v))->setClub($club));
                }
            }
        }
        $this->em->flush();

        return $this->types->findBy(['club' => $club], ['ordre' => 'ASC', 'nom' => 'ASC']);
    }

    /** Âge d'une catégorie : « U13 » → 13 ; seniors, vétérans… → 20. */
    public static function ageDe(?string $categorie): int
    {
        return preg_match('/U\s?(\d{1,2})/i', (string) $categorie, $m) ? (int) $m[1] : 20;
    }
}
