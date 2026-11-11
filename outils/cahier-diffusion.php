<?php
// Version à diffuser du cahier des charges : sans Nico ni les prix (« à voir plus tard »).
[$_, $source, $sortie] = $argv;
$s = file_get_contents($source);
$r = [
    "  <li><strong>Nico</strong> : communication, média, démarchage des clubs.</li>\n" => '',
    '<p>Forfait mensuel par club. La personnalisation est sur devis. <span class="nouveau">NOUVEAU</span>'
    => '<p>Trois formules, de la plus simple à la plus complète ; les prix sont à voir plus tard. La personnalisation est sur devis. <span class="nouveau">NOUVEAU</span>',
    '<td colspan="3">Formule 1 – 20 € par mois</td>' => '<td colspan="3">Formule 1 – prix à voir plus tard</td>',
    '<td colspan="3">Formule 2 – 40 € par mois (formule 1 +)</td>' => '<td colspan="3">Formule 2 – prix à voir plus tard (formule 1 +)</td>',
    '<td colspan="3">Formule 3 – 50 € par mois (formule 2 +)</td>' => '<td colspan="3">Formule 3 – prix à voir plus tard (formule 2 +)</td>',
    '<td>À définir avec Nico.</td>' => '<td>À définir.</td>',
    'ce qui complète la formule à 20 €.' => 'ce qui complète la formule 1.',
    'argument pour les formules à 40 € et 50 €.' => 'argument pour les formules 2 et 3.',
    '<li>Média, avec Nico.</li>' => '<li>Média.</li>',
    '<h3>Commercialisation (avec Nico)</h3>' => '<h3>Commercialisation</h3>',
];
foreach ($r as $a => $b) {
    if (1 !== substr_count($s, $a)) {
        fwrite(STDERR, 'Introuvable : ' . mb_substr($a, 0, 60) . "\n");
        exit(1);
    }
    $s = str_replace($a, $b, $s);
}
// Section « Les prix » : remplacée par « À voir plus tard ».
$s = preg_replace('#<h3>Les prix</h3>\s*<ul>.*?</ul>#su', "<h3>Les prix</h3>\n<p>À voir plus tard.</p>", $s, 1, $n);
if (1 !== $n) {
    fwrite(STDERR, "Section des prix introuvable\n");
    exit(1);
}
// Vérification : plus de Nico ni de prix des formules.
if (preg_match('/Nico|\b(20|40|50) €/u', $s, $m)) {
    fwrite(STDERR, 'Reste : ' . $m[0] . "\n");
    exit(1);
}
file_put_contents($sortie, $s);
echo "ok\n";
