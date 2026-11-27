<?php
/*
 * Génère config/exercices-complements.json : 60 exercices types de plus (15 par niveau), avec rôle (e/c/j),
 * thèmes, consignes et schéma. L'animation est déduite des flèches, dans l'ordre : passe = le ballon va au bout ;
 * course = le joueur le plus proche du départ y va ; conduite = joueur et ballon y vont ensemble.
 * Usage : php exercices_complements.php <racine du projet>
 */

function J($x, $y, $c = 'rouge', $n = '') { return ['t' => 'joueur', 'x' => $x, 'y' => $y, 'c' => $c, 'n' => (string) $n]; }
function B($x, $y) { return ['t' => 'ballon', 'x' => $x, 'y' => $y]; }
function C($x, $y, $c = 'orange') { return ['t' => 'cone', 'x' => $x, 'y' => $y, 'c' => $c]; }
function P($x, $y, $c = 'jaune') { return ['t' => 'plot', 'x' => $x, 'y' => $y, 'c' => $c]; }
function F($x1, $y1, $x2, $y2, $s = 'passe', $c = 'noir') { return ['t' => 'fleche', 'x1' => $x1, 'y1' => $y1, 'x2' => $x2, 'y2' => $y2, 's' => $s, 'c' => $c]; }
function Z($x, $y, $w, $h, $c = 'jaune') { return ['t' => 'zone', 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'c' => $c]; }
function T($x, $y, $v, $c = 'blanc') { return ['t' => 'texte', 'x' => $x, 'y' => $y, 'v' => $v, 'c' => $c]; }
function MB($x, $y, $r) { return ['t' => 'minibut', 'x' => $x, 'y' => $y, 'r' => $r]; }
function BUT($x, $y, $r) { return ['t' => 'but', 'x' => $x, 'y' => $y, 'r' => $r]; }
function M($x, $y) { return ['t' => 'mannequin', 'x' => $x, 'y' => $y, 'r' => 0]; }

/** Étapes d'animation déduites des flèches. */
function etapes(array $objets): array
{
    $pos = [];
    foreach ($objets as $i => $o) {
        if (in_array($o['t'], ['joueur', 'ballon'], true)) {
            $pos[$i] = [$o['x'], $o['y']];
        }
    }
    $ballon = null;
    foreach ($objets as $i => $o) {
        if ('ballon' === $o['t']) { $ballon = $i; break; }
    }
    $proche = function (float $x, float $y) use (&$pos, $objets): ?int {
        $meilleur = null; $d = 1e9;
        foreach ($pos as $i => [$px, $py]) {
            if ('joueur' !== $objets[$i]['t']) { continue; }
            $dd = hypot($px - $x, $py - $y);
            if ($dd < $d) { $d = $dd; $meilleur = $i; }
        }
        return $d < 6 ? $meilleur : null;
    };
    $etapes = [];
    foreach ($objets as $o) {
        if ('fleche' !== $o['t']) { continue; }
        $e = array_fill(0, count($objets), null);
        $fin = [round($o['x2'], 1), round($o['y2'], 1)];
        if ('passe' === $o['s'] && null !== $ballon) {
            $e[$ballon] = $fin; $pos[$ballon] = $fin;
        } elseif ('course' === $o['s'] && null !== ($j = $proche($o['x1'], $o['y1']))) {
            $e[$j] = $fin; $pos[$j] = $fin;
        } elseif ('conduite' === $o['s'] && null !== ($j = $proche($o['x1'], $o['y1']))) {
            $e[$j] = $fin; $pos[$j] = $fin;
            if (null !== $ballon) { $b = [round($o['x2'] + 1.4, 1), round($o['y2'] + .6, 1)]; $e[$ballon] = $b; $pos[$ballon] = $b; }
        }
        if (array_filter($e, fn ($v) => null !== $v)) { $etapes[] = $e; }
    }
    return $etapes;
}

function ex(string $secteur, string $role, array $themes, string $titre, int $duree, array $c, string $terrain, array $objets): array
{
    return [
        'secteur' => $secteur, 'role' => $role, 'themes' => $themes, 'titre' => $titre, 'duree' => $duree,
        'consignes' => implode("\n", ['Organisation : ' . $c[0], 'Déroulement : ' . $c[1], 'Variantes : ' . $c[2], 'Points clés : ' . $c[3]]),
        'schema' => ['terrain' => $terrain, 'objets' => $objets, 'etapes' => etapes($objets)],
    ];
}

$E = [];

// ———————————————— École de foot ————————————————
$s = 'ecole_foot';
$E['eco_chat_ballon'] = ex($s, 'e', ['dribble', 'physique'], 'Le chat et les souris avec ballon', 10,
    ['carré de 20 × 20 m, un ballon par « souris », 2 « chats » sans ballon (chasubles).', 'les souris conduisent leur ballon dans le carré ; un chat qui touche le ballon d\'une souris la transforme en chat.', 'les chats doivent sortir le ballon du carré ; jouer seulement du pied faible.', 'tête levée, changer de direction et de vitesse, protéger le ballon avec le corps.'],
    'libre', [Z(15, 5, 30, 30), J(20, 12, 'rouge', '1'), B(21.5, 12.8), J(38, 15, 'rouge', '2'), J(25, 28, 'rouge', '3'), J(30, 20, 'bleu', 'C'), F(22, 12, 32, 9, 'conduite'), F(30, 19, 31, 11, 'course', 'blanc'), T(30, 38, 'Le chat touche le ballon : la souris devient chat')]);
$E['eco_feu_rouge'] = ex($s, 'e', ['dribble'], 'Feu rouge, feu vert', 10,
    ['carré de 20 × 20 m, un ballon par joueur, l\'éducateur au bord avec 3 cônes de couleur.', 'vert : conduite libre ; orange : conduite lente, semelle ; rouge : arrêt du ballon sous la semelle.', 'ajouter « bleu » = demi-tour ; « jaune » = changer de pied.', 'petites touches, regarder l\'éducateur et pas le ballon, réagir vite.'],
    'libre', [Z(15, 5, 30, 30, 'blanc'), J(20, 10, 'rouge', '1'), B(21.5, 10.8), J(35, 25, 'rouge', '2'), J(28, 18, 'rouge', '3'), J(50, 20, 'jaune', 'E'), C(52, 15, 'rouge'), C(52, 20, 'orange'), C(52, 25, 'jaune'), F(21, 11, 30, 14, 'conduite')]);
$E['eco_passes_cercle'] = ex($s, 'e', ['passes'], 'Passes en cercle : appeler et passer', 10,
    ['cercle de 8 à 10 joueurs, diamètre 15 m, 2 ballons.', 'on appelle un camarade par son prénom, on lui passe le ballon et on court prendre sa place.', 'contrôle obligatoire avant la passe ; une touche ; 3 ballons en même temps.', 'passe de l\'intérieur du pied, regarder avant de passer, se déplacer après la passe.'],
    'libre', [J(30, 8, 'rouge', '1'), B(31.5, 8.8), J(42, 14, 'rouge', '2'), J(42, 26, 'rouge', '3'), J(30, 32, 'rouge', '4'), J(18, 26, 'rouge', '5'), J(18, 14, 'rouge', '6'), F(31, 9.5, 40.5, 14, 'passe'), F(29, 9, 40, 25, 'course', 'blanc')]);
$E['eco_tir_passe'] = ex($s, 'c', ['finition', 'passes'], 'Passe, contrôle et tir au but', 12,
    ['un but avec gardien, deux colonnes à 15 m : passeurs à droite avec les ballons, tireurs au centre.', 'le passeur donne au tireur qui contrôle vers l\'avant et frappe ; on change de colonne.', 'tir sans contrôle ; passe en retrait depuis le côté ; défenseur passif qui revient.', 'contrôle orienté vers le but, frapper avec le coup de pied, viser les côtés.'],
    'libre', [BUT(56, 20, 90), J(54, 20, 'jaune', 'G'), J(30, 20, 'rouge', '1'), J(36, 30, 'rouge', '2'), B(37.5, 30.8), C(28, 18), C(28, 22), F(36.5, 29, 32, 21.5, 'passe'), F(31, 20, 38, 20, 'course'), F(38, 20.5, 53.5, 18, 'passe')]);
$E['eco_duel_couloir'] = ex($s, 'c', ['duels', 'dribble'], 'Duel en couloir vers le mini-but', 12,
    ['couloirs de 8 × 20 m, un mini-but au bout de chaque couloir, un attaquant et un défenseur par couloir.', 'l\'attaquant part avec le ballon et doit marquer ; le défenseur part du mini-but et vient au contact.', 'défenseur qui part de derrière l\'attaquant ; but uniquement en conduite.', 'feinter puis accélérer, protéger le ballon, défenseur : se mettre de profil et attendre.'],
    'libre', [Z(10, 14, 40, 12, 'blanc'), MB(52, 20, 90), J(12, 20, 'rouge', 'A'), B(13.5, 20.8), J(46, 20, 'bleu', 'D'), F(14, 20, 30, 22, 'conduite'), F(45, 20, 33, 21, 'course', 'blanc'), F(31, 22, 50, 20, 'passe')]);
$E['eco_defendre_porte'] = ex($s, 'c', ['defense', 'duels'], 'Défendre sa porte', 12,
    ['carré de 15 × 15 m, une porte de 3 m (2 plots) au milieu de chaque côté, un défenseur, un attaquant.', 'l\'attaquant doit traverser une des portes en conduite ; le défenseur l\'en empêche en restant entre lui et la porte.', 'deux attaquants contre un défenseur ; le défenseur marque en récupérant et passant une porte.', 'se placer entre le ballon et la porte, freiner, ne pas se jeter, garder les appuis.'],
    'libre', [Z(22, 12, 16, 16, 'blanc'), P(28.5, 12), P(31.5, 12), P(28.5, 28), P(31.5, 28), P(22, 18.5), P(22, 21.5), P(38, 18.5), P(38, 21.5), J(30, 24, 'rouge', 'A'), B(31.5, 24.8), J(30, 17, 'bleu', 'D'), F(31, 24, 36, 21, 'conduite'), F(30, 17.5, 35, 19.5, 'course', 'blanc')]);
$E['eco_relais_conduite'] = ex($s, 'c', ['dribble', 'passes', 'physique'], 'Relais : conduite, passe et sprint', 10,
    ['2 ou 3 équipes en colonne, un cône à 15 m devant chaque colonne.', 'le premier conduit jusqu\'au cône, le contourne, revient et fait la passe au suivant, puis sprinte au bout de la colonne.', 'conduite en slalom ; passe obligatoire du pied faible ; retour en marche arrière.', 'ballon près du pied, passe précise au camarade, encourager son équipe.'],
    'libre', [J(10, 14, 'rouge', '1'), B(11.5, 14.8), J(7, 14, 'rouge', '2'), J(4, 14, 'rouge', '3'), C(30, 14), J(10, 26, 'bleu', '1'), J(7, 26, 'bleu', '2'), C(30, 26), F(12, 14, 28.5, 13, 'conduite'), F(29, 15.5, 8.5, 14.5, 'passe')]);
$E['eco_frappe_zones'] = ex($s, 'c', ['finition'], 'Frappe dans les zones du but', 12,
    ['un but avec gardien, 3 zones marquées dans le but (gauche, milieu, droite), une colonne à 14 m avec les ballons.', 'l\'éducateur annonce une zone au dernier moment ; le joueur conduit 3 m et frappe dans la zone annoncée.', 'frappe après une passe du côté ; points : 2 dans les côtés, 1 au milieu.', 'regarder le but avant de frapper, pied d\'appui à côté du ballon, frapper en suivant le geste.'],
    'libre', [BUT(56, 20, 90), J(54, 20, 'jaune', 'G'), C(56, 16.5, 'jaune'), C(56, 23.5, 'jaune'), J(36, 20, 'rouge', '1'), B(37.5, 20.8), J(33, 20, 'rouge', '2'), F(38, 20.5, 42, 20.5, 'conduite'), F(43.5, 21, 55, 17, 'passe')]);
$E['eco_transition_3c2'] = ex($s, 'c', ['transition', 'duels'], '3 contre 2 puis retour à 2 contre 1', 12,
    ['terrain de 30 × 20 m, un mini-but à chaque bout, 3 rouges contre 2 bleus.', 'les rouges attaquent à 3 contre 2 ; dès que l\'action est finie, les 2 bleus repartent à 2 contre 1 vers l\'autre but contre le dernier rouge.', 'temps limité à 10 secondes par attaque ; le défenseur qui récupère peut marquer tout de suite.', 'attaquer vite l\'espace, se démarquer, à la perte : réagir tout de suite.'],
    'libre', [MB(4, 20, 270), MB(56, 20, 90), J(14, 12, 'rouge', '1'), J(14, 20, 'rouge', '2'), B(15.5, 20.8), J(14, 28, 'rouge', '3'), J(40, 15, 'bleu', '4'), J(40, 25, 'bleu', '5'), F(16, 20, 26, 20, 'conduite'), F(27.5, 20.5, 33, 13, 'passe'), F(15, 12, 32, 12.5, 'course'), F(33, 13, 54, 19, 'passe')]);
$E['eco_chasse_ballon'] = ex($s, 'c', ['pressing', 'defense'], 'Chasse au ballon : récupérer à deux', 10,
    ['carré de 15 × 15 m, 4 attaquants qui se passent le ballon, 2 chasseurs.', 'les attaquants font circuler ; les deux chasseurs pressent ensemble et doivent toucher le ballon : ils échangent alors avec ceux qui l\'ont perdu.', 'attaquants à 2 touches ; chasseurs qui comptent leurs récupérations en 1 minute.', 'presser à deux : un va au ballon, l\'autre ferme la passe ; courir vite vers le porteur.'],
    'libre', [Z(22, 12, 16, 16), J(23.5, 13.5, 'rouge', '1'), B(25, 14.5), J(36.5, 13.5, 'rouge', '2'), J(36.5, 26.5, 'rouge', '3'), J(23.5, 26.5, 'rouge', '4'), J(29, 22, 'bleu', 'C'), J(31, 18, 'bleu', 'C'), F(25, 15, 35, 14, 'passe'), F(31, 18, 35, 15, 'course', 'blanc'), F(29, 22, 33, 23, 'course', 'blanc')]);
$E['eco_corner_poteau'] = ex($s, 'c', ['cpa', 'finition'], 'Corner : frapper au deuxième poteau', 12,
    ['demi-terrain réduit, un but avec gardien, un tireur au coin, 2 attaquants à l\'entrée de la surface.', 'le tireur frappe le corner au sol ou à mi-hauteur ; un attaquant attaque le premier poteau, l\'autre le second, et ils reprennent.', 'corner joué à deux (passe courte puis centre) ; un défenseur dans la surface.', 'tireur : frapper fort et devant le but ; attaquants : partir au moment de la frappe, reprendre du pied ou du genou.'],
    'demi', [J(37, 4.8, 'jaune', 'G'), J(70, 4, 'rouge', 'T'), B(69, 5), J(30, 18, 'rouge', '1'), J(42, 18, 'rouge', '2'), F(68.5, 5.5, 42, 7, 'passe'), F(30, 17, 33, 8, 'course'), F(42, 17, 41, 8, 'course'), F(41.5, 7.5, 37, 3.5, 'passe')]);
$E['eco_parcours_motricite'] = ex($s, 'c', ['physique', 'dribble'], 'Parcours de motricité avec ballon', 12,
    ['parcours : 4 cerceaux (pas chassés), 3 haies basses, slalom de 5 cônes, mini-but au bout.', 'le joueur fait les cerceaux et les haies sans ballon, prend un ballon, fait le slalom et tire dans le mini-but.', 'chrono par équipe ; slalom du pied faible ; deux parcours côte à côte en course.', 'appuis rapides, bras qui aident, garder l\'équilibre, ballon près du pied dans le slalom.'],
    'libre', [J(5, 20, 'rouge', '1'), P(10, 20, 'jaune'), P(13, 20, 'jaune'), P(16, 20, 'jaune'), C(24, 18), C(24, 22), B(30, 20.8), C(34, 18), C(38, 22), C(42, 18), C(46, 22), MB(56, 20, 90), F(6, 20, 29, 20, 'course'), F(30, 20, 47, 20, 'conduite'), F(48.5, 20.5, 55, 20, 'passe')]);
$E['eco_passe_sprint'] = ex($s, 'c', ['passes', 'physique'], 'Passe dans la porte et sprint', 10,
    ['deux colonnes face à face à 12 m, une porte de 2 m au milieu.', 'le joueur passe le ballon à travers la porte, puis sprinte derrière la colonne d\'en face.', 'passe à une touche ; contrôle avec le pied opposé ; course en arrière jusqu\'à la porte puis sprint.', 'passe précise et appuyée, regarder la porte, enchaîner vite.'],
    'libre', [J(16, 20, 'rouge', '1'), B(17.5, 20.8), J(13, 20, 'rouge', '2'), J(44, 20, 'bleu', '3'), J(47, 20, 'bleu', '4'), P(30, 19), P(30, 21), F(17.5, 20.5, 43, 20, 'passe'), F(16, 22, 46, 23, 'course', 'blanc')]);
$E['eco_eperviers'] = ex($s, 'c', ['pressing', 'defense', 'physique'], 'Les éperviers avec ballon', 10,
    ['couloir de 25 × 15 m, les joueurs avec un ballon sur une ligne, 2 éperviers sans ballon au milieu.', 'au signal, les joueurs traversent en conduite ; les éperviers chassent ensemble et sortent les ballons : ceux qui perdent deviennent éperviers.', 'éperviers qui se tiennent par la main ; traversée en 10 secondes maximum.', 'éperviers : courir ensemble, fermer le passage, attaquer le ballon ; porteurs : tête levée, changer de direction.'],
    'libre', [Z(15, 12, 30, 16, 'blanc'), J(16, 15, 'rouge', '1'), B(17.5, 15.8), J(16, 20, 'rouge', '2'), J(16, 25, 'rouge', '3'), J(29, 18, 'bleu', 'E'), J(31, 23, 'bleu', 'E'), F(17.5, 16, 42, 14, 'conduite'), F(29, 18, 34, 15.5, 'course', 'blanc'), F(31, 23, 35, 19, 'course', 'blanc')]);
$E['eco_recupere_marque'] = ex($s, 'c', ['pressing', 'transition'], 'Récupère et marque vite', 12,
    ['terrain de 25 × 20 m, 2 mini-buts, 3 contre 3, l\'éducateur au bord avec les ballons.', 'l\'éducateur lance le ballon à une équipe ; l\'autre doit le récupérer le plus vite possible et marquer dans les 5 secondes : but compté double.', 'compte à voix haute des 5 secondes ; joueur en plus pour l\'équipe qui récupère.', 'aller tout de suite au ballon, à plusieurs, puis attaquer vite le but.'],
    'libre', [MB(10, 20, 270), MB(50, 20, 90), J(30, 36, 'jaune', 'E'), B(31, 34.5), J(25, 18, 'rouge', '1'), J(32, 24, 'rouge', '2'), J(30, 15, 'bleu', '3'), J(36, 21, 'bleu', '4'), F(31, 34, 32, 25.5, 'passe'), F(36, 21, 33.5, 24.5, 'course', 'blanc'), F(33.5, 25, 48.5, 20, 'passe')]);
$E['eco_touche_pieds'] = ex($s, 'c', ['cpa', 'passes'], 'Touche : bien lancer et jouer', 10,
    ['par 3 le long d\'une ligne : un lanceur, un receveur, un défenseur passif, des plots à 5 et 10 m.', 'apprendre la touche (deux mains derrière la tête, deux pieds au sol) ; le receveur fait un appel, contrôle et redonne au lanceur.', 'lancer dans la course ; défenseur actif ; le lanceur rentre et reçoit une passe.', 'pieds au sol, ballon derrière la tête, lancer vers les pieds du partenaire, appel avant le lancer.'],
    'libre', [J(30, 6, 'rouge', 'L'), B(30, 5), J(26, 16, 'rouge', '1'), J(30, 15, 'bleu', 'D'), P(24, 14), P(36, 14), F(26, 16, 23, 12, 'course'), F(30, 5.5, 23.5, 11, 'passe'), F(23, 12.5, 29, 7, 'passe')]);
$E['eco_match_4portes'] = ex($s, 'j', ['passes', 'dribble'], 'Match à 4 contre 4 sur 4 portes', 15,
    ['terrain de 30 × 20 m, 2 portes de 2 m par équipe (une dans chaque coin du côté à défendre).', 'match libre : on marque en conduisant le ballon à travers une des deux portes adverses.', 'but par une passe à un partenaire qui reçoit derrière la porte ; touches limitées.', 'regarder où est la porte libre, changer de côté, jouer ensemble.'],
    'libre', [P(10, 9, 'bleu'), P(10, 12, 'bleu'), P(10, 28, 'bleu'), P(10, 31, 'bleu'), P(50, 9, 'jaune'), P(50, 12, 'jaune'), P(50, 28, 'jaune'), P(50, 31, 'jaune'), J(24, 15, 'rouge', '1'), B(25.5, 15.8), J(28, 26, 'rouge', '2'), J(36, 18, 'bleu', '3'), J(34, 28, 'bleu', '4'), F(25.5, 16.5, 27.5, 24.5, 'passe'), F(28, 26, 49, 29.5, 'conduite')]);
$E['eco_match_joker'] = ex($s, 'j', ['finition', 'passes', 'transition'], 'Match avec un joker attaquant', 15,
    ['terrain de 30 × 20 m, 2 buts avec gardiens, 3 contre 3 et un joker (chasuble jaune) qui joue avec l\'équipe qui a le ballon.', 'match libre : l\'équipe qui attaque joue à 4 contre 3 ; à la perte du ballon, le joker change d\'équipe.', 'but du joker interdit ; but compté double après une passe du joker.', 'utiliser le joueur en plus, attaquer vite, se replacer vite à la perte.'],
    'libre', [BUT(4, 20, 270), BUT(56, 20, 90), J(6, 20, 'jaune', 'G'), J(54, 20, 'jaune', 'G'), J(22, 14, 'rouge', '1'), B(23.5, 14.8), J(26, 26, 'rouge', '2'), J(30, 20, 'jaune', 'J'), J(38, 16, 'bleu', '3'), J(40, 26, 'bleu', '4'), F(23.5, 15.5, 29, 19.5, 'passe'), F(31, 20.5, 37, 25, 'passe'), F(26, 26, 38, 23, 'course')]);

// ———————————————— Préformation ————————————————
$s = 'preformation';
$E['pre_toro_deplacement'] = ex($s, 'e', ['passes', 'pressing'], 'Toro en déplacement 5 contre 2', 12,
    ['carré de 12 × 12 m, 5 joueurs à l\'extérieur, 2 au milieu.', 'les extérieurs se déplacent le long des côtés et conservent ; 10 passes = 1 point ; le milieu qui récupère sort avec celui qui a perdu.', '2 touches puis 1 touche ; passe entre les deux défenseurs = 2 passes.', 'se déplacer pour offrir un angle, passe au pied opposé au défenseur, défenseurs : presser à deux.'],
    'libre', [Z(24, 14, 12, 12), J(24, 15, 'rouge', '1'), B(25.5, 15.8), J(36, 16, 'rouge', '2'), J(36, 24, 'rouge', '3'), J(30, 26, 'rouge', '4'), J(24, 24, 'rouge', '5'), J(29, 19, 'bleu', '6'), J(31, 22, 'bleu', '7'), F(25.5, 16.5, 35.5, 23.5, 'passe'), F(36, 24, 30, 25.5, 'passe'), F(29, 19, 34, 22, 'course', 'blanc')]);
$E['pre_activation_rythme'] = ex($s, 'e', ['physique', 'passes'], 'Activation : échelle de rythme et passe', 12,
    ['2 échelles de rythme (ou 8 lattes), un passeur à 8 m au bout de chaque échelle.', 'le joueur passe l\'échelle (appuis variés), reçoit du passeur, remet en une touche puis sprinte 10 m.', 'appuis : deux pieds par case, pas chassés, montées de genoux ; remise du pied faible.', 'appuis courts et rapides, rester sur l\'avant du pied, remise précise.'],
    'libre', [J(6, 20, 'rouge', '1'), P(10, 20), P(12, 20), P(14, 20), P(16, 20), P(18, 20), J(28, 20, 'bleu', 'P'), B(26.5, 20.8), C(40, 20), F(7, 20, 22, 20, 'course'), F(26.5, 20.5, 23, 20.5, 'passe'), F(23, 21, 26.5, 22, 'passe'), F(22, 19, 39, 19, 'course')]);
$E['pre_feintes_plot'] = ex($s, 'e', ['dribble', 'duels'], 'Conduite et feintes devant le plot', 10,
    ['3 colonnes, un mannequin (ou un plot) à 10 m devant chaque colonne, un cône d\'arrivée 6 m après.', 'conduite vers le mannequin, feinte (passement, crochet, feinte de frappe) puis accélération jusqu\'au cône.', 'feinte imposée par l\'éducateur ; course en duel contre la colonne voisine.', 'ralentir avant la feinte, feinte large et crédible, accélérer après.'],
    'libre', [J(8, 12, 'rouge', '1'), B(9.5, 12.8), M(22, 12), C(32, 12), J(8, 20, 'rouge', '2'), M(22, 20), C(32, 20), J(8, 28, 'rouge', '3'), M(22, 28), C(32, 28), F(10, 12, 19, 12, 'conduite'), F(20, 13, 31, 13.5, 'conduite')]);
$E['pre_2c2_appui'] = ex($s, 'c', ['duels', 'passes', 'transition'], '2 contre 2 avec un appui', 15,
    ['terrain de 25 × 18 m, un mini-but à chaque bout, 2 contre 2 et un appui de chaque côté sur la ligne de touche.', 'match à 2 contre 2 ; les appuis jouent avec l\'équipe qui a le ballon (une touche) ; on change de rôle toutes les 2 minutes.', 'appuis à 2 touches ; but compté double après un une-deux avec l\'appui.', 'se démarquer, utiliser l\'appui pour éliminer, à la perte : presser tout de suite.'],
    'libre', [MB(10, 20, 270), MB(50, 20, 90), J(30, 9, 'jaune', 'A'), J(30, 31, 'jaune', 'A'), J(22, 17, 'rouge', '1'), B(23.5, 17.8), J(24, 24, 'rouge', '2'), J(36, 18, 'bleu', '3'), J(38, 24, 'bleu', '4'), F(23.5, 17, 29, 10, 'passe'), F(22, 18, 34, 13, 'course'), F(30.5, 10, 35, 13, 'passe'), F(35.5, 13.5, 48.5, 19, 'passe')]);
$E['pre_frappe_loin'] = ex($s, 'c', ['finition', 'passes'], 'Frappe de loin après une remise', 15,
    ['un but avec gardien, un joueur en appui dos au but à 22 m, une colonne de tireurs à 30 m.', 'le tireur passe à l\'appui, qui remet dans la course ; frappe à l\'entrée de la zone (20 m).', 'remise de côté puis frappe enroulée ; un défenseur qui vient fermer.', 'frapper du coup de pied, pied d\'appui à côté du ballon, buste au-dessus du ballon, viser bas.'],
    'demi', [J(37, 4.8, 'jaune', 'G'), J(37, 25, 'rouge', 'A'), J(37, 36, 'rouge', '1'), B(38.5, 36.8), F(38.5, 36, 38, 26.5, 'passe'), F(36, 36, 35, 29, 'course'), F(37.5, 26.5, 35.5, 28.5, 'passe'), F(35.5, 28, 40, 3.5, 'passe')]);
$E['pre_pressing_trois'] = ex($s, 'c', ['pressing', 'defense'], 'Presser à trois : fermer les lignes de passe', 15,
    ['zone de 25 × 20 m, 5 attaquants (dont 2 en appui au bout de la zone) contre 3 défenseurs.', 'les attaquants doivent trouver l\'appui opposé ; les 3 défenseurs pressent : un sur le porteur, deux ferment les passes.', 'les défenseurs marquent en récupérant et passant dans un mini-but ; touches limitées pour les attaquants.', 'presser au signal (mauvais contrôle, passe en retrait), courir en courbe pour fermer la passe, ne pas laisser passer dans l\'axe.'],
    'libre', [Z(17, 10, 26, 20), J(17, 20, 'jaune', 'A'), J(43, 20, 'jaune', 'A'), J(22, 13, 'rouge', '1'), B(23.5, 13.8), J(30, 26, 'rouge', '2'), J(36, 13, 'rouge', '3'), J(27, 16, 'bleu', '4'), J(30, 21, 'bleu', '5'), J(34, 17, 'bleu', '6'), F(23.5, 14.5, 29, 25, 'passe'), F(30, 21, 30, 24, 'course', 'blanc'), F(34, 17, 33, 22, 'course', 'blanc')]);
$E['pre_defendre_surface'] = ex($s, 'c', ['defense'], 'Défendre la surface à 4 contre 4', 15,
    ['demi-terrain, un but avec gardien, 4 défenseurs dans la surface, 4 attaquants et 2 centreurs sur les côtés.', 'le ballon part d\'un centreur ; les défenseurs s\'organisent (premier poteau, point de penalty, second poteau) et dégagent.', 'centre en retrait ; un défenseur de plus ; contre-attaque des défenseurs vers deux mini-buts.', 'voir le ballon et l\'adversaire, être devant lui, dégager loin et sur le côté.'],
    'demi', [J(37, 4.8, 'jaune', 'G'), J(31, 9, 'bleu', '2'), J(37, 12, 'bleu', '4'), J(43, 9, 'bleu', '5'), J(37, 17, 'bleu', '6'), J(30, 20, 'rouge', '9'), J(44, 21, 'rouge', '10'), J(64, 14, 'rouge', '7'), B(63, 15), F(62.5, 15.5, 40, 9, 'passe'), F(44, 20, 41, 11, 'course'), F(43, 9.5, 41, 10, 'course', 'blanc')]);
$E['pre_vagues'] = ex($s, 'c', ['transition', 'finition'], 'Vagues : 3 contre 2 puis 2 contre 1', 15,
    ['demi-terrain, un but avec gardien, 3 attaquants à 35 m, 2 défenseurs, puis un défenseur qui attend.', 'les 3 attaquent à 3 contre 2 ; si le défenseur récupère, il relance et deux attaquants deviennent défenseurs : 2 contre 1 vers la ligne médiane.', 'temps limité (8 secondes) ; un défenseur qui revient de derrière.', 'attaquer vite, fixer le défenseur, passer au bon moment, se replacer tout de suite à la perte.'],
    'demi', [J(37, 4.8, 'jaune', 'G'), J(28, 38, 'rouge', '7'), J(37, 40, 'rouge', '9'), B(38.5, 40.8), J(46, 38, 'rouge', '11'), J(33, 22, 'bleu', '4'), J(42, 22, 'bleu', '5'), F(38.5, 40, 38, 30, 'conduite'), F(39.5, 30, 46, 22, 'passe'), F(46, 37, 47, 24, 'course'), F(47, 23, 42, 4, 'passe')]);
$E['pre_coup_franc_mur'] = ex($s, 'c', ['cpa', 'finition'], 'Coup franc : par-dessus le mur', 15,
    ['un but avec gardien, un mur de 3 mannequins à 9 m, ballons à 20 m dans l\'axe et légèrement décalés.', 'chaque joueur tire 5 coups francs : enroulé par-dessus le mur côté gardien, ou à ras du sol côté opposé.', 'un joueur qui feinte et un qui frappe ; frappe tendue au-dessus d\'un mur sauteur.', 'pied d\'appui orienté, frapper avec l\'intérieur du pied, regarder le gardien avant de prendre l\'élan.'],
    'demi', [J(37, 4.8, 'jaune', 'G'), M(34, 14), M(36, 14), M(38, 14), J(36, 25, 'rouge', '10'), B(37, 23.5), F(37, 23, 41, 4, 'passe')]);
$E['pre_touche_vite'] = ex($s, 'c', ['cpa', 'passes'], 'Touches : jouer vite et conserver', 12,
    ['zone de 20 × 15 m le long de la touche, 1 lanceur, 3 attaquants contre 2 défenseurs.', 'le lanceur remet en jeu dans les pieds ou dans la course ; l\'équipe doit garder le ballon 6 passes ou sortir par la ligne opposée.', 'lancer long vers un appel en profondeur ; remise immédiate au lanceur.', 'jouer vite, un appel court et un appel long, le lanceur rentre tout de suite dans le jeu.'],
    'libre', [Z(18, 10, 24, 20, 'blanc'), J(30, 9, 'rouge', 'L'), B(30, 8), J(24, 16, 'rouge', '1'), J(36, 18, 'rouge', '2'), J(30, 26, 'rouge', '3'), J(28, 18, 'bleu', '4'), J(33, 22, 'bleu', '5'), F(30, 8.5, 24.5, 15, 'passe'), F(36, 18, 37, 13, 'course'), F(25, 16, 36.5, 13, 'passe')]);
$E['pre_1c1_couloir_centre'] = ex($s, 'c', ['dribble', 'duels', 'finition'], '1 contre 1 dans le couloir puis centre', 15,
    ['demi-terrain, couloirs de 12 m sur chaque côté, un ailier contre un défenseur dans le couloir, 2 attaquants dans l\'axe.', 'l\'ailier élimine son défenseur dans le couloir et centre ; les attaquants attaquent les deux poteaux.', 'centre en retrait ; un défenseur central contre les attaquants ; but compté double sur centre au sol.', 'ailier : provoquer, accélérer, centrer sans regarder trop longtemps ; attaquants : appels croisés.'],
    'demi', [J(37, 4.8, 'jaune', 'G'), Z(59, 4, 12, 36, 'bleu'), J(65, 34, 'rouge', '7'), B(66.5, 34.8), J(65, 24, 'bleu', '3'), J(34, 24, 'rouge', '9'), J(44, 26, 'rouge', '10'), F(66, 34, 67, 16, 'conduite'), F(67.5, 15, 41, 8, 'passe'), F(34, 23, 34, 9, 'course'), F(44, 25, 41, 9, 'course')]);
$E['pre_navettes_frappe'] = ex($s, 'c', ['physique', 'finition'], 'Navettes avec ballon et frappe', 12,
    ['un but avec gardien, des cônes à 5, 10 et 15 m de la ligne de départ (25 m du but).', 'aller-retour au cône de 5 m, puis 10 m, puis on reçoit un ballon et on frappe au but : 6 répétitions, récupération 45 secondes.', 'frappe après conduite ; départ allongé ou assis.', 'changements de direction en abaissant le centre de gravité, frappe malgré la fatigue : rester lucide.'],
    'demi', [J(37, 4.8, 'jaune', 'G'), J(37, 45, 'rouge', '1'), C(37, 40), C(37, 35), J(48, 30, 'bleu', 'P'), B(46.5, 30.8), F(37, 44, 37, 36, 'course'), F(46.5, 30.5, 38.5, 31, 'passe'), F(38.5, 31, 35, 3.5, 'passe')]);
$E['pre_jeu_tete'] = ex($s, 'c', ['duels', 'finition'], 'Jeu de tête : remise et reprise', 12,
    ['par 3 : un lanceur, un joueur de tête, un défenseur passif puis actif ; un but avec gardien.', 'le lanceur envoie à la main ; le joueur saute et remet de la tête vers le but ou dévie pour un partenaire.', 'centre au pied ; défenseur qui saute au contact ; tête plongeante.', 'yeux ouverts, frapper avec le front, s\'élever avec les bras, timing de la course.'],
    'demi', [J(37, 4.8, 'jaune', 'G'), J(52, 12, 'rouge', 'L'), B(51, 13), J(38, 13, 'rouge', '9'), J(36, 15, 'bleu', '4'), F(51, 13, 38.5, 12, 'passe'), F(38.5, 12, 33, 3.5, 'passe')]);
$E['pre_match_3zones'] = ex($s, 'j', ['passes', 'finition', 'transition'], 'Match à 3 zones : construire, déséquilibrer, finir', 20,
    ['terrain de 50 × 35 m en 3 zones, 2 buts avec gardiens, 6 contre 6.', 'match : le ballon doit passer par la zone du milieu avant de pouvoir marquer ; un joueur de plus autorisé dans la zone de finition.', 'but compté double si moins de 6 passes ; limite de touches dans la zone de construction.', 'construire calmement, accélérer au milieu, nombreux dans la zone de finition.'],
    'entier', [J(5.5, 37, 'jaune', 'G'), J(105.5, 37, 'jaune', 'G'), Z(20, 15, 25, 44, 'blanc'), Z(45, 15, 21, 44, 'jaune'), Z(66, 15, 25, 44, 'blanc'), J(30, 30, 'rouge', '4'), B(31.5, 30.8), J(52, 40, 'rouge', '8'), J(75, 30, 'rouge', '9'), J(55, 32, 'bleu', '6'), J(80, 40, 'bleu', '4'), F(31.5, 31, 51.5, 39, 'passe'), F(53, 40, 74, 31, 'passe'), F(76, 30.5, 104, 35, 'passe')]);
$E['pre_match_pressing'] = ex($s, 'j', ['pressing', 'transition'], 'Match : récupérer haut et marquer', 20,
    ['terrain de 50 × 35 m, 2 buts avec gardiens, 7 contre 7, une ligne à 15 m de chaque but.', 'match libre ; un but marqué dans les 8 secondes après une récupération dans le camp adverse compte triple.', 'relance obligatoire au sol depuis le gardien ; pressing interdit avant la passe du gardien.', 'presser ensemble, couper les passes vers l\'axe, finir vite après la récupération.'],
    'entier', [J(5.5, 37, 'jaune', 'G'), J(105.5, 37, 'jaune', 'G'), J(88, 30, 'bleu', '4'), B(89.5, 30.8), J(86, 44, 'bleu', '5'), J(80, 31, 'rouge', '9'), J(78, 42, 'rouge', '10'), F(80, 31, 87, 31, 'course'), F(78, 42, 84, 42, 'course'), F(89.5, 31.5, 85.5, 43, 'passe')]);

// ———————————————— Formation ————————————————
$s = 'formation';
$E['for_rondo_couleurs'] = ex($s, 'e', ['passes', 'pressing'], 'Rondo à deux couleurs 4+4 contre 2', 12,
    ['carré de 15 × 15 m, 4 rouges et 4 bleus à l\'extérieur et à l\'intérieur, 2 jaunes au milieu.', 'on doit alterner les couleurs (rouge → bleu → rouge) ; un jaune qui récupère prend la place de celui qui a perdu.', 'une touche pour les extérieurs ; passe entre les deux jaunes obligatoire toutes les 4 passes.', 'orientation du corps, prise d\'information avant de recevoir, passe forte au bon pied.'],
    'libre', [Z(22, 12, 16, 16), J(22, 14, 'rouge', '1'), B(23.5, 14.8), J(38, 17, 'bleu', '2'), J(38, 26, 'rouge', '3'), J(26, 28, 'bleu', '4'), J(26, 20, 'bleu', '5'), J(34, 21, 'rouge', '6'), J(29, 17, 'jaune', 'J'), J(31, 24, 'jaune', 'J'), F(23.5, 15.5, 37.5, 17, 'passe'), F(38, 17.5, 34.5, 20.5, 'passe'), F(34, 21.5, 27, 27, 'passe')]);
$E['for_activation_athletique'] = ex($s, 'e', ['physique'], 'Activation athlétique avec ballon', 12,
    ['2 colonnes, parcours : montées de genoux 10 m, talons-fesses 10 m, pas chassés, puis jonglage et passe.', 'chaque joueur enchaîne les gammes puis reçoit un ballon, fait 5 jongles et passe au suivant de la colonne d\'en face.', 'accélérations progressives sur 20 m ; ajout de sauts et de changements de direction.', 'gainage, bras actifs, augmenter progressivement l\'intensité.'],
    'libre', [J(5, 15, 'rouge', '1'), J(5, 25, 'rouge', '2'), C(15, 15), C(25, 15), C(35, 15), C(15, 25), C(25, 25), C(35, 25), J(50, 20, 'bleu', 'P'), B(48.5, 20.8), F(6, 15, 36, 15, 'course'), F(48.5, 20.5, 37, 16, 'passe')]);
$E['for_triangle_une_touche'] = ex($s, 'e', ['passes'], 'Passes en triangle à une touche', 12,
    ['triangle de 12 m de côté, un cône par sommet, 2 joueurs par cône, 1 ballon.', 'passe et suit à une touche dans un sens, puis dans l\'autre au signal ; remise puis passe longue.', '2 ballons en même temps ; contrôle orienté obligatoire du pied opposé.', 'ouvrir le corps, communiquer, qualité et rythme de la passe.'],
    'libre', [C(20, 30), C(40, 30), C(30, 12), J(18, 31, 'rouge', '1'), B(19.5, 31.8), J(16, 33, 'rouge', '2'), J(42, 31, 'rouge', '3'), J(30, 10, 'rouge', '4'), F(19.5, 31.5, 39.5, 31, 'passe'), F(18, 30, 39, 29, 'course'), F(41.5, 30, 31, 12, 'passe')]);
$E['for_declencheur_pressing'] = ex($s, 'c', ['pressing', 'defense'], 'Pressing : le déclencheur', 20,
    ['moitié de terrain, 6 défenseurs + gardien qui relancent contre 5 attaquants qui pressent, 2 mini-buts à la ligne médiane.', 'les relanceurs doivent passer une ligne ou marquer dans un mini-but ; les presseurs déclenchent sur une passe arrière, un mauvais contrôle ou une passe vers le côté.', 'les presseurs marquent dans le grand but après récupération ; un joueur de plus pour les relanceurs.', 'le premier presse en courbe pour fermer l\'autre côté, les autres suivent et ferment les passes proches.'],
    'demi', [J(37, 4.8, 'jaune', 'G'), J(25, 14, 'bleu', '4'), B(26.5, 14.8), J(49, 14, 'bleu', '5'), J(14, 24, 'bleu', '2'), J(60, 24, 'bleu', '3'), J(37, 26, 'bleu', '6'), J(30, 22, 'rouge', '9'), J(44, 24, 'rouge', '10'), J(22, 32, 'rouge', '7'), MB(20, 55, 0), MB(54, 55, 0), F(26.5, 15.5, 15, 23, 'passe'), F(30, 22, 18, 22, 'course'), F(22, 32, 15, 27, 'course')]);
$E['for_bloc_median'] = ex($s, 'c', ['defense', 'pressing'], 'Bloc médian : coulisser et fermer l\'axe', 20,
    ['zone de 40 × 35 m, 6 défenseurs en deux lignes de 3 contre 6 attaquants qui font circuler, 3 mini-buts côté défenseurs.', 'les attaquants cherchent à passer dans l\'axe ; le bloc coulisse vers le ballon, reste compact (15 m entre les lignes) et récupère.', 'récupération = contre-attaque vers 2 mini-buts adverses ; un attaquant de plus.', 'distances courtes, coulisser ensemble, fermer l\'intérieur, presser quand le ballon va sur le côté.'],
    'demi', [Z(10, 18, 54, 30, 'blanc'), J(25, 26, 'bleu', '6'), J(37, 26, 'bleu', '8'), J(49, 26, 'bleu', '10'), J(25, 36, 'bleu', '2'), J(37, 36, 'bleu', '4'), J(49, 36, 'bleu', '3'), J(20, 46, 'rouge', '1'), B(21.5, 46.8), J(54, 46, 'rouge', '2'), MB(26, 14, 180), MB(37, 14, 180), MB(48, 14, 180), F(21.5, 47, 53, 46.5, 'passe'), F(49, 36, 53, 40, 'course', 'blanc'), F(37, 26, 44, 28, 'course', 'blanc')]);
$E['for_fixer_renverser'] = ex($s, 'c', ['passes', 'finition'], 'Attaque placée : fixer et renverser', 20,
    ['demi-terrain, un but avec gardien, 7 attaquants contre 5 défenseurs + gardien.', 'les attaquants attirent le bloc d\'un côté puis renversent vite de l\'autre côté pour centrer ou frapper.', 'but compté double après un renversement ; deux touches maximum.', 'faire circuler vite, appel du côté opposé avant le renversement, finir l\'action.'],
    'demi', [J(37, 4.8, 'jaune', 'G'), J(18, 30, 'rouge', '3'), B(19.5, 30.8), J(30, 34, 'rouge', '6'), J(44, 34, 'rouge', '8'), J(60, 26, 'rouge', '7'), J(37, 20, 'rouge', '9'), J(24, 24, 'bleu', '4'), J(33, 22, 'bleu', '5'), F(19.5, 31, 29.5, 33.5, 'passe'), F(31.5, 34.5, 43.5, 34, 'passe'), F(45.5, 34, 59.5, 27, 'passe'), F(61, 26, 40, 9, 'passe')]);
$E['for_contre_4c3'] = ex($s, 'c', ['transition', 'finition'], 'Contre-attaque à 4 contre 3 en 8 secondes', 18,
    ['terrain de 60 × 45 m, 2 buts avec gardiens, 4 attaquants contre 3 défenseurs + un qui revient.', 'à la récupération (ballon de l\'éducateur), les 4 attaquent en moins de 8 secondes ; un défenseur part de la ligne médiane pour revenir.', 'les défenseurs qui récupèrent contre-attaquent à leur tour ; finir en moins de 3 passes.', 'jouer vers l\'avant, courses dans les espaces, passe dans la course, finition rapide.'],
    'entier', [J(5.5, 37, 'jaune', 'G'), J(105.5, 37, 'jaune', 'G'), J(50, 37, 'rouge', '8'), B(51.5, 37.8), J(52, 22, 'rouge', '7'), J(52, 52, 'rouge', '11'), J(46, 37, 'rouge', '9'), J(75, 30, 'bleu', '4'), J(75, 44, 'bleu', '5'), J(68, 37, 'bleu', '6'), F(51.5, 37.5, 62, 37, 'conduite'), F(63.5, 37.5, 80, 22, 'passe'), F(52, 22, 80, 21, 'course'), F(81, 22, 104, 35, 'passe')]);
$E['for_duels_aeriens'] = ex($s, 'c', ['duels', 'defense'], 'Duels aériens et deuxième ballon', 15,
    ['zone de 30 × 20 m, un lanceur à chaque bout, 2 contre 2 au milieu.', 'le lanceur envoie un ballon long ; duel aérien, puis l\'équipe qui gagne le deuxième ballon doit le donner au lanceur opposé.', 'duel au pied après la tête ; 3 contre 3 ; points pour chaque duel gagné.', 'prendre l\'avantage avant le saut, s\'élever avec les bras sans faute, anticiper la chute du ballon.'],
    'libre', [Z(15, 10, 30, 20), J(10, 20, 'rouge', 'L'), B(11.5, 20.8), J(50, 20, 'bleu', 'L'), J(30, 17, 'rouge', '9'), J(31, 19, 'bleu', '4'), J(26, 24, 'rouge', '10'), J(33, 25, 'bleu', '6'), F(11.5, 20.5, 30, 17.5, 'passe'), F(30.5, 18, 27, 23.5, 'passe'), F(26, 24, 48.5, 20.5, 'passe')]);
$E['for_1c1_defensif'] = ex($s, 'c', ['duels', 'defense'], '1 contre 1 défensif : orienter vers l\'extérieur', 15,
    ['couloir de 15 × 25 m, 2 mini-buts côté défenseur (un dans l\'axe, un sur le côté), un attaquant contre un défenseur.', 'le défenseur passe le ballon à l\'attaquant puis sort ; il doit l\'orienter vers le côté et l\'empêcher de marquer dans le but de l\'axe.', 'but dans l\'axe = 3 points, sur le côté = 1 point ; défenseur qui récupère marque dans un but adverse.', 'sortir vite puis freiner, corps de biais, forcer vers l\'extérieur, intervenir au bon moment.'],
    'libre', [Z(15, 8, 30, 24, 'blanc'), MB(46, 14, 90), MB(46, 28, 90), J(18, 20, 'rouge', 'A'), J(40, 20, 'bleu', 'D'), B(39, 21), F(39, 21, 19.5, 20.5, 'passe'), F(40, 20, 28, 19, 'course', 'blanc'), F(19.5, 20.5, 34, 26, 'conduite')]);
$E['for_eliminer_frapper'] = ex($s, 'c', ['dribble', 'finition'], 'Éliminer dans la zone et frapper', 15,
    ['devant la surface, une zone de 15 × 12 m avec un défenseur, un but avec gardien, une colonne d\'attaquants.', 'l\'attaquant reçoit, entre dans la zone, élimine le défenseur par un dribble et frappe avant la surface.', 'deux défenseurs et un partenaire en appui ; frappe en moins de 5 secondes.', 'attaquer le défenseur de face, changement de rythme, frapper dès que la porte s\'ouvre.'],
    'demi', [J(37, 4.8, 'jaune', 'G'), Z(29, 20, 16, 12, 'bleu'), J(37, 25, 'bleu', '4'), J(37, 42, 'rouge', '10'), B(38.5, 42.8), F(38.5, 42.5, 38, 33, 'conduite'), F(39.5, 33, 44, 24, 'conduite'), F(45.5, 24, 40, 4, 'passe')]);
$E['for_corner_defensif'] = ex($s, 'c', ['cpa', 'defense'], 'Corner défensif : zone et marquage', 15,
    ['surface de réparation, gardien, 6 défenseurs, 6 attaquants, un tireur de corner.', 'organisation mixte : 3 défenseurs en zone (premier poteau, point de penalty, deuxième poteau), 3 au marquage individuel ; on dégage et on sort.', 'corner rentrant puis sortant ; corner court ; contre-attaque après le dégagement.', 'être placé avant la frappe, regarder ballon et adversaire, attaquer le ballon, sortir ensemble.'],
    'demi', [J(37, 4.8, 'jaune', 'G'), J(32, 7, 'bleu', '2'), J(37, 12, 'bleu', '4'), J(44, 8, 'bleu', '5'), J(34, 15, 'bleu', '6'), J(42, 15, 'bleu', '8'), J(35, 16, 'rouge', '9'), J(43, 17, 'rouge', '10'), J(70, 4, 'rouge', 'T'), B(69, 5), F(68.5, 5.5, 37, 11, 'passe'), F(37, 12, 40, 26, 'passe')]);
$E['for_touche_combinaison'] = ex($s, 'c', ['cpa', 'passes'], 'Combinaisons sur touche', 12,
    ['zone de 25 × 20 m le long de la touche, un lanceur, 3 attaquants contre 3 défenseurs.', '2 combinaisons répétées : remise au lanceur qui centre ; appel croisé et lancer dans la course.', 'défense active ; gagner le côté opposé en moins de 4 passes.', 'appels synchronisés avec le lancer, contrôle orienté vers le jeu, le lanceur rentre vite.'],
    'libre', [Z(18, 10, 24, 20, 'blanc'), J(30, 9, 'rouge', 'L'), B(30, 8), J(25, 16, 'rouge', '7'), J(35, 15, 'rouge', '8'), J(30, 24, 'rouge', '9'), J(27, 19, 'bleu', '2'), J(34, 19, 'bleu', '6'), F(25, 16, 34, 13, 'course'), F(35, 15, 26, 13, 'course'), F(30, 8.5, 33.5, 12.5, 'passe')]);
$E['for_intermittent_2c2'] = ex($s, 'c', ['physique', 'duels'], 'Intermittent en jeu réduit 2 contre 2', 15,
    ['terrains de 20 × 15 m avec 2 mini-buts, 2 contre 2, ballons tout autour.', '6 séquences de 1 minute à intensité maximale, 1 minute de récupération active (passes) ; on change d\'adversaire.', '1 contre 1 de 45 secondes ; 3 contre 3 de 2 minutes.', 'intensité, duels gagnés, rester lucide techniquement malgré la fatigue.'],
    'libre', [MB(16, 20, 270), MB(44, 20, 90), J(25, 16, 'rouge', '1'), B(26.5, 16.8), J(27, 25, 'rouge', '2'), J(33, 17, 'bleu', '3'), J(34, 24, 'bleu', '4'), F(26.5, 17.5, 27, 24, 'passe'), F(28, 25, 42, 21, 'conduite')]);
$E['for_dribble_1c1_2buts'] = ex($s, 'c', ['dribble', 'duels'], '1 contre 1 vers deux portes', 15,
    ['zone de 15 × 12 m, 2 portes de 3 m au fond, un attaquant face à un défenseur, colonnes de chaque côté.', 'l\'attaquant doit passer une des deux portes en conduite ; le défenseur qui récupère marque en passant la ligne de départ.', 'porte annoncée au dernier moment ; 2 contre 1 puis 2 contre 2.', 'feinter pour déséquilibrer, changer de direction et de rythme, protéger le ballon après le dribble.'],
    'libre', [Z(20, 12, 20, 16, 'blanc'), P(40, 13), P(40, 16), P(40, 24), P(40, 27), J(22, 20, 'rouge', 'A'), B(23.5, 20.8), J(34, 20, 'bleu', 'D'), F(24, 20, 31, 17, 'conduite'), F(33, 17, 39.5, 14.5, 'conduite')]);
$E['for_conduite_vitesse'] = ex($s, 'c', ['dribble', 'physique'], 'Conduite rapide et changements de direction', 12,
    ['parcours de 4 portes de cônes en zigzag sur 30 m, 2 parcours côte à côte, un mini-but au bout.', 'conduite à vitesse maximale, changement de direction à chaque porte (intérieur, extérieur, semelle), frappe dans le mini-but ; course en duel avec le voisin.', 'imposer la surface de contact ; défenseur qui part 2 m derrière.', 'touches courtes à pleine vitesse, regard devant, accélérer après chaque changement de direction.'],
    'libre', [J(5, 14, 'rouge', '1'), B(6.5, 14.8), C(14, 11), C(14, 17), C(24, 16), C(24, 22), C(34, 11), C(34, 17), MB(55, 14, 90), J(5, 28, 'bleu', '2'), F(7, 15, 13, 14, 'conduite'), F(15, 14, 23, 19, 'conduite'), F(25, 19, 33, 14, 'conduite'), F(34.5, 14.5, 54, 14, 'passe')]);
$E['for_physique_circuit'] = ex($s, 'c', ['physique'], 'Circuit puissance et vitesse avec ballon', 15,
    ['4 ateliers de 2 minutes : sauts de haies + tête, sprints 15 m + passe, gainage dynamique + jonglage, duel d\'épaule + frappe.', 'par deux, 45 secondes de travail, 15 secondes de récupération, rotation vers l\'atelier suivant ; 2 tours.', 'durée de travail selon la période ; atelier de vitesse de réaction au signal.', 'qualité d\'exécution avant la vitesse, gainage, s\'hydrater entre les tours.'],
    'libre', [Z(4, 4, 24, 14, 'blanc'), Z(32, 4, 24, 14, 'blanc'), Z(4, 22, 24, 14, 'blanc'), Z(32, 22, 24, 14, 'blanc'), T(16, 3, 'Sauts + tête'), T(44, 3, 'Sprint + passe'), T(16, 39, 'Gainage + jongles'), T(44, 39, 'Duel + frappe'), J(10, 11, 'rouge', '1'), J(38, 11, 'rouge', '2'), F(10, 12, 20, 12, 'course'), F(38, 12, 50, 12, 'course')]);
$E['sen_physique_repetition'] = ex($s, 'c', ['physique'], 'Répétition de sprints avec finition', 15,
    ['demi-terrain, un but avec gardien, départs à 35 m, un passeur à l\'entrée de la surface.', '6 à 8 sprints de 30 m terminés par une frappe sur passe ; récupération 30 secondes en marchant ; 2 séries.', 'sprint avec changement de direction ; duel en course à deux.', 'départ explosif, rester lucide pour la finition, respecter les temps de récupération.'],
    'demi', [J(37, 4.8, 'jaune', 'G'), J(37, 45, 'rouge', '9'), C(37, 42), J(52, 22, 'bleu', 'P'), B(50.5, 22.8), F(37, 44, 38, 20, 'course'), F(50.5, 22.5, 39.5, 19.5, 'passe'), F(39.5, 19.5, 34, 3.5, 'passe')]);
$E['sen_physique_jeu_long'] = ex($s, 'c', ['physique', 'transition'], 'Endurance de puissance : allers-retours en jeu', 18,
    ['terrain de 60 × 45 m, 2 buts avec gardiens, 6 contre 6, l\'éducateur au milieu avec les ballons.', 'dès qu\'une action se termine, l\'éducateur relance aussitôt dans l\'autre sens : 3 séquences de 4 minutes, 2 minutes de récupération.', 'touches limitées ; tous les joueurs doivent avoir passé le milieu pour que le but compte.', 'intensité continue, replis et projections rapides, communication.'],
    'entier', [J(5.5, 37, 'jaune', 'G'), J(105.5, 37, 'jaune', 'G'), J(55.5, 70, 'jaune', 'E'), B(55.5, 68.5), J(45, 30, 'rouge', '8'), J(66, 42, 'bleu', '6'), F(55.5, 68, 46, 31.5, 'passe'), F(46.5, 31, 90, 34, 'conduite'), F(66, 42, 85, 38, 'course', 'blanc')]);
$E['for_match_3_equipes'] = ex($s, 'j', ['transition', 'physique'], 'Match à 3 équipes : transitions', 20,
    ['terrain de 50 × 40 m, 2 buts avec gardiens, 3 équipes de 5 : 2 jouent, la 3e attend derrière un but.', 'l\'équipe qui marque reste et défend ; l\'équipe qui encaisse sort ; celle qui attendait entre tout de suite et attaque.', 'match de 2 minutes ; but compté double dans les 6 secondes après une récupération.', 'entrer vite, attaquer avant que l\'adversaire soit replacé, se replier dès la perte.'],
    'entier', [J(5.5, 37, 'jaune', 'G'), J(105.5, 37, 'jaune', 'G'), J(40, 30, 'rouge', '1'), B(41.5, 30.8), J(45, 45, 'rouge', '2'), J(60, 32, 'bleu', '3'), J(62, 44, 'bleu', '4'), J(100, 20, 'jaune', 'E'), J(100, 54, 'jaune', 'E'), F(41.5, 31.5, 44.5, 44, 'passe'), F(45, 45, 90, 42, 'conduite'), F(91.5, 42, 104, 37, 'passe')]);
$E['for_match_couloirs'] = ex($s, 'j', ['finition', 'passes'], 'Match avec couloirs : centrer pour marquer', 20,
    ['terrain de 60 × 45 m, 2 couloirs latéraux de 8 m, 2 buts avec gardiens, 6 contre 6 + un ailier libre dans chaque couloir.', 'match : les ailiers jouent avec l\'équipe qui a le ballon ; but compté double sur un centre.', 'ailiers à 2 touches ; défenseurs autorisés dans le couloir.', 'écarter le jeu, attaquer les deux poteaux, centrer tôt ou en retrait.'],
    'entier', [J(5.5, 37, 'jaune', 'G'), J(105.5, 37, 'jaune', 'G'), Z(20, 3, 70, 8, 'jaune'), Z(20, 63, 70, 8, 'jaune'), J(50, 7, 'jaune', 'A'), J(60, 67, 'jaune', 'A'), J(45, 30, 'rouge', '6'), B(46.5, 30.8), J(80, 34, 'rouge', '9'), J(84, 40, 'bleu', '4'), F(46.5, 30.5, 50, 9, 'passe'), F(51, 7, 80, 7, 'conduite'), F(81.5, 8, 92, 35, 'passe'), F(80, 34, 92, 36, 'course')]);

// ———————————————— Seniors ————————————————
$s = 'seniors';
$E['sen_rondo_6c3'] = ex($s, 'e', ['passes', 'pressing'], 'Rondo 6 contre 3 à deux touches', 12,
    ['rectangle de 18 × 14 m, 6 joueurs autour, 3 au milieu.', 'conservation à 2 touches ; les 3 du milieu sortent après 3 récupérations ou 90 secondes.', 'une touche ; passe entre deux défenseurs = point ; 2 ballons.', 'jouer loin du pressing, déplacement permanent, défenseurs : presser à 3 en triangle.'],
    'libre', [Z(21, 13, 18, 14), J(21, 15, 'rouge', '1'), B(22.5, 15.8), J(30, 13, 'rouge', '2'), J(39, 16, 'rouge', '3'), J(39, 25, 'rouge', '4'), J(30, 27, 'rouge', '5'), J(21, 24, 'rouge', '6'), J(27, 19, 'bleu', 'D'), J(32, 21, 'bleu', 'D'), J(29, 23, 'bleu', 'D'), F(22.5, 16.5, 38.5, 24.5, 'passe'), F(39, 25.5, 30.5, 26.5, 'passe'), F(27, 19, 34, 23, 'course', 'blanc')]);
$E['sen_activation_vitesse'] = ex($s, 'e', ['physique', 'finition'], 'Activation : appuis, vitesse et frappe', 12,
    ['un but avec gardien, des lattes et cônes à 30 m, un passeur à 20 m.', 'appuis rapides dans les lattes, sprint de 10 m, remise au passeur puis frappe sur sa passe.', 'départ au signal sonore ou visuel ; frappe du pied faible ; duel en course avec un partenaire.', 'qualité d\'appuis, réactivité, frappe cadrée.'],
    'demi', [J(37, 4.8, 'jaune', 'G'), J(37, 48, 'rouge', '1'), P(37, 44), P(37, 42), P(37, 40), C(37, 32), J(50, 26, 'bleu', 'P'), B(48.5, 26.8), F(37, 47, 37, 33, 'course'), F(48.5, 26.5, 38.5, 28, 'passe'), F(38.5, 28, 34, 3.5, 'passe')]);
$E['sen_carre_dynamique'] = ex($s, 'e', ['passes'], 'Carré dynamique : passe, appel, rotation', 12,
    ['carré de 15 m, un joueur à chaque coin (2 au départ), un joueur au centre.', 'passe au centre, remise, passe longue vers le coin suivant ; chacun suit sa passe ; rotation dans un sens puis dans l\'autre.', '2 ballons ; appel en profondeur à la place du coin suivant.', 'prendre l\'information, ouvrir le corps, passe tendue, communication.'],
    'libre', [C(22, 12), C(38, 12), C(38, 28), C(22, 28), J(20, 29, 'rouge', '1'), B(21.5, 29.8), J(30, 20, 'rouge', '2'), J(40, 29, 'rouge', '3'), J(40, 11, 'rouge', '4'), F(21.5, 29.5, 29, 21, 'passe'), F(29.5, 21, 23, 28, 'passe'), F(23, 28.5, 39, 28.5, 'passe'), F(20, 28, 29, 21.5, 'course')]);
$E['sen_pressing_442'] = ex($s, 'c', ['pressing', 'defense'], 'Pressing en 4-4-2 : piéger le côté', 20,
    ['trois quarts de terrain, 8 joueurs en 4-4 (+ 2 attaquants) qui pressent contre 6 relanceurs + gardien.', 'les deux attaquants orientent la relance vers un côté ; dès la passe vers le latéral, tout le bloc coulisse et enferme le ballon contre la touche.', 'récupération = attaque en moins de 10 secondes ; relanceurs qui marquent en passant une ligne.', 'courses en courbe des attaquants, fermer l\'axe, déclencher ensemble sur la passe vers le côté.'],
    'entier', [J(5.5, 37, 'jaune', 'G'), J(18, 28, 'bleu', '4'), B(19.5, 28.8), J(18, 46, 'bleu', '5'), J(26, 12, 'bleu', '2'), J(26, 62, 'bleu', '3'), J(32, 37, 'bleu', '6'), J(30, 32, 'rouge', '9'), J(30, 44, 'rouge', '10'), J(38, 14, 'rouge', '7'), J(42, 30, 'rouge', '8'), F(19.5, 29, 25.5, 13, 'passe'), F(30, 32, 23, 22, 'course'), F(38, 14, 29, 12, 'course'), F(42, 30, 32, 20, 'course')]);
$E['sen_bloc_bas'] = ex($s, 'c', ['defense'], 'Bloc bas : défendre la surface à 6 contre 7', 20,
    ['demi-terrain, gardien et 6 défenseurs contre 7 attaquants, 2 mini-buts de contre-attaque à la ligne médiane.', 'les attaquants font circuler autour de la surface, centrent et frappent ; le bloc reste compact, ferme les frappes et dégage.', 'défenseurs qui marquent dans un mini-but après récupération ; temps limité pour les attaquants.', 'compacité, se resserrer vers le ballon, bloquer les frappes, sortir ensemble après le dégagement.'],
    'demi', [J(37, 4.8, 'jaune', 'G'), J(28, 12, 'bleu', '2'), J(34, 11, 'bleu', '4'), J(40, 11, 'bleu', '5'), J(46, 12, 'bleu', '3'), J(32, 19, 'bleu', '6'), J(42, 19, 'bleu', '8'), J(20, 22, 'rouge', '7'), B(21.5, 22.8), J(37, 26, 'rouge', '10'), J(54, 22, 'rouge', '11'), MB(20, 55, 0), MB(54, 55, 0), F(21.5, 23.5, 36, 25.5, 'passe'), F(32, 19, 35, 24, 'course', 'blanc'), F(37.5, 26, 53, 22.5, 'passe')]);
$E['sen_attaque_rapide'] = ex($s, 'c', ['transition', 'finition'], 'Attaque rapide après récupération 5 contre 4', 20,
    ['deux tiers de terrain, 5 attaquants contre 4 défenseurs + gardien ; l\'éducateur donne le ballon au milieu.', 'récupération simulée : les attaquants doivent frapper en moins de 10 secondes ; ensuite les défenseurs relancent vers deux mini-buts.', 'moins de 4 passes ; un défenseur de plus qui revient de derrière.', 'premier regard vers l\'avant, courses en profondeur, passe dans la course, finir.'],
    'entier', [J(105.5, 37, 'jaune', 'G'), J(55, 37, 'rouge', '8'), B(56.5, 37.8), J(60, 18, 'rouge', '7'), J(60, 56, 'rouge', '11'), J(65, 37, 'rouge', '9'), J(54, 28, 'rouge', '10'), J(80, 26, 'bleu', '4'), J(80, 48, 'bleu', '5'), J(74, 37, 'bleu', '6'), F(56.5, 38.5, 59.5, 55, 'passe'), F(60, 56, 84, 52, 'conduite'), F(65, 37, 92, 34, 'course'), F(85.5, 52, 93, 34, 'passe'), F(93, 34, 104, 37, 'passe')]);
$E['sen_finition_variee'] = ex($s, 'c', ['finition'], 'Finition variée : centre, retrait, frappe de loin', 20,
    ['demi-terrain, un but avec gardien, 3 ateliers enchaînés : centre aérien, centre en retrait, frappe à 20 m.', 'les attaquants tournent sur les 3 ateliers : attaque des poteaux sur centre, reprise sur retrait, frappe après remise.', 'défenseur actif sur chaque atelier ; points par but et par frappe cadrée.', 'timing des courses, surface de contact adaptée, cadrer en priorité.'],
    'demi', [J(37, 4.8, 'jaune', 'G'), J(66, 18, 'rouge', '7'), B(65, 19), J(32, 18, 'rouge', '9'), J(44, 20, 'rouge', '10'), J(37, 28, 'rouge', '8'), F(64.5, 19.5, 44, 12, 'passe'), F(32, 17, 33, 9, 'course'), F(44, 19, 43, 11, 'course'), F(43.5, 11.5, 34, 3.5, 'passe')]);
$E['sen_1c1_aile'] = ex($s, 'c', ['dribble', 'duels', 'finition'], '1 contre 1 sur l\'aile et centre', 15,
    ['demi-terrain, un couloir de 12 m sur chaque côté, ailier contre latéral, 2 attaquants contre 1 défenseur dans la surface.', 'l\'ailier reçoit, provoque le latéral, déborde ou repique ; centre ou frappe ; les attaquants attaquent la surface.', 'le latéral qui récupère joue vers deux mini-buts ; centre obligatoire en moins de 6 secondes.', 'provoquer de face, changement de rythme, centre tendu au deuxième poteau ou en retrait.'],
    'demi', [J(37, 4.8, 'jaune', 'G'), Z(3, 4, 12, 40, 'bleu'), J(9, 36, 'rouge', '11'), B(10.5, 36.8), J(9, 26, 'bleu', '2'), J(34, 22, 'rouge', '9'), J(42, 24, 'rouge', '10'), J(38, 14, 'bleu', '5'), F(10.5, 36.5, 12, 16, 'conduite'), F(13.5, 16, 40, 9, 'passe'), F(42, 23, 40, 10, 'course'), F(34, 21, 32, 9, 'course')]);
$E['sen_coup_franc_indirect'] = ex($s, 'c', ['cpa', 'finition'], 'Coup franc au bord de la surface : combinaisons', 15,
    ['ballon à 22 m, mur de 4 mannequins, gardien, 3 tireurs et 2 attaquants.', '3 combinaisons répétées : frappe directe ; décalage court puis frappe ; feinte de frappe et passe dans la course.', 'défenseurs actifs dans la surface ; choisir la combinaison selon le placement du gardien.', 'même gestuelle pour la feinte et la frappe, synchronisation des courses, discrétion des signaux.'],
    'demi', [J(37, 4.8, 'jaune', 'G'), M(31, 15), M(33, 15), M(35, 15), M(37, 15), J(34, 26, 'rouge', '10'), B(35, 24), J(40, 26, 'rouge', '8'), J(44, 20, 'rouge', '9'), F(35, 24, 40, 25, 'passe'), F(40.5, 25, 42, 4, 'passe'), F(44, 19, 43, 9, 'course')]);
$E['sen_corner_defensif'] = ex($s, 'c', ['cpa', 'defense'], 'Corner défensif mixte et contre-attaque', 15,
    ['surface, gardien, 7 défenseurs, 6 attaquants, un tireur ; 2 attaquants restent devant pour la contre-attaque.', 'défense mixte (4 en zone, 3 au marquage) ; dès le dégagement, contre-attaque vers 2 mini-buts à 40 m.', 'corner court ; deuxième ballon repris à 20 m.', 'zones tenues avant la frappe, attaquer le ballon le plus haut possible, sortir vite.'],
    'demi', [J(37, 4.8, 'jaune', 'G'), J(31, 7, 'bleu', '2'), J(36, 10, 'bleu', '4'), J(42, 8, 'bleu', '5'), J(37, 15, 'bleu', '6'), J(34, 16, 'rouge', '9'), J(43, 15, 'rouge', '4'), J(70, 4, 'rouge', 'T'), B(69, 5), J(30, 38, 'bleu', '9'), MB(20, 55, 0), MB(54, 55, 0), F(68.5, 5.5, 37, 10, 'passe'), F(37, 10, 30.5, 36, 'passe')]);
$E['sen_jeu_reduit_intense'] = ex($s, 'c', ['physique', 'pressing', 'duels'], 'Puissance : jeu réduit 4 contre 4 intense', 16,
    ['terrain de 30 × 25 m, 2 buts avec gardiens, 4 contre 4, ballons tout autour.', '4 séquences de 3 minutes à haute intensité, 2 minutes de récupération ; l\'éducateur remet un ballon dès qu\'il sort.', 'touches limitées ; but compté double après pressing gagné.', 'intensité maximale, pressing immédiat, duels, finir les actions.'],
    'libre', [BUT(4, 20, 270), BUT(56, 20, 90), J(6, 20, 'jaune', 'G'), J(54, 20, 'jaune', 'G'), J(22, 14, 'rouge', '1'), B(23.5, 14.8), J(25, 27, 'rouge', '2'), J(34, 15, 'bleu', '3'), J(38, 26, 'bleu', '4'), F(23.5, 15.5, 24.5, 26, 'passe'), F(34, 15, 27, 24, 'course', 'blanc'), F(26, 27, 53, 21, 'passe')]);
$E['sen_conserver_renverser'] = ex($s, 'c', ['passes', 'pressing'], 'Conserver sous pression et sortir côté opposé', 20,
    ['zone de 40 × 30 m divisée en 2, 6 contre 4 d\'un côté, 2 joueurs qui attendent dans l\'autre moitié.', 'les 6 conservent sous le pressing des 4 ; après 6 passes ils renversent vers l\'autre moitié et 3 joueurs y passent : le jeu continue là-bas.', 'renversement aérien obligatoire ; pressing à 5.', 'attirer le pressing, jouer en triangles, renverser au bon moment.'],
    'libre', [Z(5, 5, 25, 30, 'jaune'), Z(30, 5, 25, 30, 'blanc'), J(8, 10, 'rouge', '1'), B(9.5, 10.8), J(20, 8, 'rouge', '2'), J(26, 20, 'rouge', '3'), J(14, 30, 'rouge', '4'), J(16, 18, 'bleu', 'D'), J(22, 14, 'bleu', 'D'), J(45, 15, 'rouge', '5'), J(48, 28, 'rouge', '6'), F(9.5, 11.5, 19.5, 9, 'passe'), F(20.5, 9, 25.5, 19.5, 'passe'), F(26.5, 20.5, 47.5, 27.5, 'passe')]);
$E['sen_duels_surface'] = ex($s, 'c', ['duels', 'finition', 'defense'], 'Duels dans la surface', 15,
    ['surface de réparation, gardien, un attaquant contre un défenseur, un passeur à 20 m.', 'le passeur joue dans les pieds de l\'attaquant dos au but ; l\'attaquant se retourne ou remet et doit frapper ; le défenseur l\'en empêche.', '2 contre 2 ; passe aérienne ; défenseur qui part au contact.', 'attaquant : protéger, sentir le défenseur, se retourner vite ; défenseur : coller, ne pas laisser se retourner.'],
    'demi', [J(37, 4.8, 'jaune', 'G'), J(37, 16, 'rouge', '9'), J(37, 13, 'bleu', '4'), J(37, 28, 'rouge', '10'), B(38.5, 28.8), F(38.5, 28.5, 38, 17, 'passe'), F(38.5, 17, 42, 14, 'conduite'), F(43.5, 14, 39, 3.5, 'passe')]);
$E['sen_match_grand_terrain'] = ex($s, 'j', ['passes', 'transition', 'pressing'], 'Match à thème sur grand terrain', 25,
    ['terrain entier ou 80 × 60 m, 2 buts avec gardiens, 9 contre 9 ou 11 contre 11.', 'match avec une consigne liée à la séance (pressing, attaque rapide, renversement…) ; arrêts courts pour corriger.', 'but compté double s\'il respecte le thème ; touches limitées au milieu.', 'retrouver en match les comportements travaillés, communication, organisation.'],
    'entier', [J(5.5, 37, 'jaune', 'G'), J(105.5, 37, 'jaune', 'G'), J(30, 37, 'rouge', '6'), B(31.5, 37.8), J(50, 15, 'rouge', '7'), J(60, 37, 'rouge', '9'), J(48, 58, 'rouge', '11'), J(55, 30, 'bleu', '6'), J(75, 37, 'bleu', '4'), F(31.5, 37.5, 49.5, 16, 'passe'), F(50, 15, 76, 15, 'conduite'), F(60, 37, 92, 37, 'course'), F(77.5, 16, 92, 36, 'passe')]);
$E['sen_match_joker'] = ex($s, 'j', ['finition', 'transition'], 'Match à 8 contre 8 avec joker offensif', 20,
    ['terrain de 65 × 50 m, 2 buts avec gardiens, 8 contre 8 et un joker qui attaque avec l\'équipe qui a le ballon.', 'match libre en supériorité numérique offensive ; le joker à 2 touches ; but compté double après une passe du joker.', 'joker interdit dans la surface ; 2 jokers.', 'utiliser le joueur libre, attaquer vite la surface, se replacer à la perte.'],
    'entier', [J(5.5, 37, 'jaune', 'G'), J(105.5, 37, 'jaune', 'G'), J(55, 37, 'jaune', 'J'), J(40, 30, 'rouge', '8'), B(41.5, 30.8), J(75, 30, 'rouge', '9'), J(70, 46, 'bleu', '4'), J(62, 28, 'bleu', '6'), F(41.5, 31.5, 54, 37, 'passe'), F(56, 37.5, 74, 31, 'passe'), F(75, 30, 104, 36, 'passe')]);

$racine = $argv[1] ?? '.';
file_put_contents($racine . '/config/exercices-complements.json', json_encode($E, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
echo count($E), " exercices écrits\n";
// Couverture : thèmes des exercices de corps de séance par groupe.
$cov = [];
foreach ($E as $e) { foreach ($e['themes'] as $t) { $cov[$e['secteur']][$e['role']][$t] = ($cov[$e['secteur']][$e['role']][$t] ?? 0) + 1; } }
foreach ($cov as $g => $r) { echo $g, ' : ', json_encode($r), "\n"; }
