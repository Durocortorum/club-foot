<?php
// Extraction des archives d'installation, une par page, à la place du gestionnaire de fichiers
// de hPanel (erreur 500 sur les grosses archives). Se dépose à la racine du site avec les zips,
// extrait vendor-N.zip, 2-photos.zip puis 1-code.zip, renvoie vers la page d'installation
// et se supprime. Protégé par le même code d'accès que l'installation.
// 1-code.zip passe en dernier : son .htaccess renvoie toutes les adresses vers public/,
// cette page ne serait plus joignable pour les archives suivantes.

declare(strict_types=1);

const EMPREINTE_CODE = '__EMPREINTE__';
// Racine du site, que la page soit déposée à la racine ou dans public/ (si le .htaccess
// de 1-code.zip est déjà en place, seul public/ est joignable).
$racine = 'public' === basename(__DIR__) ? dirname(__DIR__) : __DIR__;
session_start();
$erreur = null;
$fait = [];

/**
 * Crée un dossier en rendant inscriptibles ceux qui existent déjà sur le chemin : le gestionnaire
 * de fichiers de hPanel en laisse (var/ notamment) où PHP ne peut rien créer. Si les droits ne se
 * corrigent pas, le dossier bloqué est mis de côté (renommé) et recréé.
 */
function creerDossier(string $dossier): bool
{
    global $racine;
    // La racine du site et ce qui est au-dessus ne sont jamais renommés.
    if (!str_starts_with($dossier, "$racine/")) {
        return is_dir($dossier) && is_writable($dossier);
    }
    if (is_dir($dossier)) {
        @chmod($dossier, 0755);
        clearstatcache(true, $dossier);
        if (is_writable($dossier) && @touch("$dossier/.essai") && @unlink("$dossier/.essai")) {
            return true;
        }
        if (!@rename($dossier, $dossier . '-bloque-' . date('His'))) {
            return false;
        }
    }

    return creerDossier(dirname($dossier)) && @mkdir($dossier, 0755);
}

if ('POST' === $_SERVER['REQUEST_METHOD']) {
    if (password_verify((string) ($_POST['code'] ?? ''), EMPREINTE_CODE)) {
        $_SESSION['extraction'] = true;
    } else {
        $erreur = 'Code d\'accès incorrect.';
    }
}
$autorise = !empty($_SESSION['extraction']);

$archives = array_values(array_filter(
    array_merge(glob("$racine/vendor-*.zip") ?: [], ["$racine/2-photos.zip", "$racine/1-code.zip"]),
    'is_file',
));

if ($autorise && $archives) {
    try {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('L\'extension zip de PHP est absente sur ce serveur.');
        }
        @set_time_limit(300);
        $zip = new ZipArchive();
        if (true !== $zip->open($archives[0])) {
            throw new RuntimeException('Impossible d\'ouvrir ' . basename($archives[0]) . ' : renvoyez-la.');
        }
        // Fichier par fichier plutôt que extractTo() : on corrige au passage les droits des dossiers
        // laissés par une extraction interrompue (gestionnaire de fichiers) et on sait ce qui bloque.
        $n = $zip->numFiles;
        $echecs = [];
        for ($i = 0; $i < $n; ++$i) {
            $nom = $zip->getNameIndex($i);
            if (str_contains($nom, '..')) {
                continue;
            }
            $cible = "$racine/$nom";
            $dossier = str_ends_with($nom, '/') ? rtrim($cible, '/') : dirname($cible);
            if (!creerDossier($dossier)) {
                $echecs[] = "$nom (dossier : " . (error_get_last()['message'] ?? '?') . ')';
                continue;
            }
            if (str_ends_with($nom, '/')) {
                continue;
            }
            if (is_file($cible) && !is_writable($cible)) {
                @chmod($cible, 0644);
            }
            if (false === @file_put_contents($cible, $zip->getStream($nom))) {
                $echecs[] = "$nom (" . (error_get_last()['message'] ?? '?') . ')';
            }
        }
        $zip->close();
        if ($echecs) {
            throw new RuntimeException(sprintf('%d fichier(s) de %s non extraits, par exemple : %s', \count($echecs), basename($archives[0]), implode(' ; ', \array_slice($echecs, 0, 3))));
        }
        unlink($archives[0]);
        $fait = [basename($archives[0]), $n];
        array_shift($archives);
    } catch (Throwable $e) {
        $erreur = $e->getMessage();
    }
}

// Cherchée après l'extraction : elle arrive avec 1-code.zip, la dernière archive.
$installation = glob("$racine/public/installation-*.php") ?: [];
$termine = $autorise && !$archives && !$erreur;
if ($termine && $installation) {
    unlink(__FILE__);
}
$h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <?php if ($autorise && $archives && !$erreur): ?><meta http-equiv="refresh" content="1"><?php endif; ?>
    <title>Extraction · Club Foot</title>
    <style>
        body { font: 16px/1.5 system-ui, sans-serif; background: #f5f5f7; color: #1d1d1f; margin: 0; }
        main { max-width: 560px; margin: 2rem auto; background: #fff; border: 1px solid #e3e3e8; border-radius: 10px; padding: 1.5rem 2rem; }
        h1 { color: #1b6fd6; font-size: 1.4rem; margin-top: 0; }
        label { display: block; margin: .9rem 0 .3rem; font-size: .9rem; color: #6b6b72; }
        input { width: 100%; box-sizing: border-box; padding: .55rem; border: 1px solid #e3e3e8; border-radius: 6px; font: inherit; }
        button, a.bouton { display: inline-block; margin-top: 1.3rem; background: #1b6fd6; color: #fff; border: 0; border-radius: 8px; padding: .7rem 1.2rem; font: inherit; cursor: pointer; text-decoration: none; }
        .erreur { background: #fde8eb; color: #9e0b23; padding: .7rem 1rem; border-radius: 8px; }
        .ok { color: #1e7b3a; }
    </style>
</head>
<body>
<main>
    <h1>Extraction des archives</h1>
    <?php if ($erreur): ?><p class="erreur"><?= $h($erreur) ?></p><?php endif; ?>

    <?php if (!$autorise): ?>
        <form method="post" autocomplete="off">
            <label for="code">Code d'accès (le même que pour l'installation)</label>
            <input id="code" name="code" required>
            <button type="submit">Extraire</button>
        </form>
    <?php elseif ($termine): ?>
        <p class="ok"><strong>Toutes les archives sont extraites.</strong></p>
        <?php if ($installation): ?>
            <a class="bouton" href="/<?= $h(basename($installation[0])) ?>">Continuer vers l'installation</a>
        <?php else: ?>
            <p class="erreur">Page d'installation introuvable dans public/ : 1-code.zip a-t-elle bien été envoyée ?</p>
        <?php endif; ?>
    <?php else: ?>
        <?php if ($fait): ?><p class="ok">✓ <?= $h($fait[0]) ?> extraite (<?= $fait[1] ?> fichiers)</p><?php endif; ?>
        <p>Reste : <?= $h(implode(', ', array_map('basename', $archives))) ?></p>
        <?php if (!$erreur): ?><p>La page continue toute seule…</p><?php else: ?><a class="bouton" href="">Réessayer</a><?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
