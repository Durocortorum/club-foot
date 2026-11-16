<?php
// Version à diffuser du cahier des charges : sans Nico ni les prix (« à voir plus tard »).
[$_, $source, $sortie] = $argv;
$s = file_get_contents($source);
$r = [
    "  <li><strong>Nico</strong> : communication, média, démarchage des clubs.</li>\n" => '',
    '<p>Trois formules par club, chacune comprend la précédente ; au mois ou à la saison (10 mois : juillet et août offerts). Au-delà de 300 licenciés : Essentiel 30 €, Club 60 €, Formation 95 € par mois. La personnalisation est sur devis. <span class="nouveau">NOUVEAU</span>'
    => '<p>Trois formules, de la plus simple à la plus complète, chacune comprend la précédente ; les prix sont à voir plus tard. La personnalisation est sur devis. <span class="nouveau">NOUVEAU</span>',
    'Formule Essentiel – 20 € par mois :' => 'Formule Essentiel :',
    'Formule Club – 40 € par mois :' => 'Formule Club :',
    'Formule Formation – 65 € par mois :' => 'Formule Formation :',
    '<td>À définir avec Nico.</td>' => '<td>À définir.</td>',
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
if (preg_match('/Nico|\b(20|30|40|50|60|65|95|200|400|650) €/u', $s, $m)) {
    fwrite(STDERR, 'Reste : ' . $m[0] . "\n");
    exit(1);
}
file_put_contents($sortie, $s);
echo "ok\n";
