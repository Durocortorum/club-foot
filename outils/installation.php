<?php
// Installation de l'application sur l'hébergeur, en une page. Se supprime à la fin.
// Corrige les droits de var/, écrit .env.local, importe la base, vérifie.
// Protégé par un code d'accès (son empreinte seule figure ici, posée par preparer-deploiement.ps1).

declare(strict_types=1);

const EMPREINTE_CODE = '__EMPREINTE__';
$racine = dirname(__DIR__);
$etapes = [];
$erreur = null;

function etape(string $texte, bool $ok = true): void
{
    global $etapes;
    $etapes[] = [$texte, $ok];
}

function lireEnvLocal(string $racine): array
{
    $valeurs = [];
    if (is_file("$racine/.env.local")) {
        foreach (file("$racine/.env.local") as $ligne) {
            if (preg_match('/^\s*([A-Z_]+)\s*=\s*"?([^"\r\n]*)"?/', $ligne, $m)) {
                $valeurs[$m[1]] = $m[2];
            }
        }
    }

    return $valeurs;
}

/** Importe un fichier produit par mysqldump, instruction par instruction. */
function importer(PDO $pdo, string $fichier): int
{
    $n = 0;
    $instruction = '';
    $flux = fopen($fichier, 'r');
    while (false !== ($ligne = fgets($flux))) {
        if (0 === $n && '' === $instruction) {
            $ligne = preg_replace('/^\xEF\xBB\xBF/', '', $ligne);
        }
        $nette = rtrim($ligne);
        if ('' === $instruction && ('' === $nette || str_starts_with($nette, '--'))) {
            continue;
        }
        $instruction .= $ligne;
        if (str_ends_with($nette, ';')) {
            $pdo->exec($instruction);
            $instruction = '';
            ++$n;
        }
    }
    fclose($flux);

    return $n;
}

$env = lireEnvLocal($racine);
$prefixe = preg_match('#/home/(u\d+)/#', $racine, $m) ? $m[1] . '_' : '';
$envoye = 'POST' === $_SERVER['REQUEST_METHOD'];

if ($envoye) {
    try {
        if (!password_verify((string) ($_POST['code'] ?? ''), EMPREINTE_CODE)) {
            throw new RuntimeException('Code d\'accès incorrect.');
        }

        // 1. Droits et dossiers de var/
        $var = "$racine/var";
        if (!is_dir($var)) {
            mkdir($var, 0755);
        }
        chmod($var, 0755);
        foreach (['cache', 'log', 'photos', 'documents', 'club', 'sessions'] as $d) {
            if (!is_dir("$var/$d")) {
                mkdir("$var/$d", 0755, true);
            }
            chmod("$var/$d", 0755);
        }
        $nbPhotos = count(glob("$var/photos/*.jpg") ?: []);
        etape("Droits du dossier var corrigés (755), $nbPhotos photos en place");

        // 2. Connexion à la base et .env.local
        $base = trim((string) $_POST['base']);
        $utilisateur = trim((string) $_POST['utilisateur']);
        $motDePasse = (string) $_POST['mot_de_passe'];
        // Chez Hostinger, le compte MySQL est souvent autorisé pour 127.0.0.1 et pas pour localhost
        // (connexion par socket) : on essaie les deux et on garde celui qui fonctionne.
        $pdo = null;
        foreach (['127.0.0.1', 'localhost'] as $hote) {
            try {
                $pdo = new PDO("mysql:host=$hote;port=3306;dbname=$base;charset=utf8mb4", $utilisateur, $motDePasse, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                break;
            } catch (PDOException $e) {
                $echec = $e->getMessage();
            }
        }
        if (!$pdo) {
            throw new RuntimeException('Connexion à la base impossible : vérifiez le nom de la base, l\'utilisateur et le mot de passe (' . $echec . ').');
        }
        etape("Connexion à la base « $base » réussie (serveur $hote)");

        $secret = $env['APP_SECRET'] ?? bin2hex(random_bytes(16));
        $url = sprintf('mysql://%s:%s@%s:3306/%s?charset=utf8mb4', rawurlencode($utilisateur), rawurlencode($motDePasse), $hote, rawurlencode($base));
        // doctrine.yaml lit l'adresse avec %env(resolve:...)% : un « % » isolé y serait pris
        // pour un paramètre Symfony, on le double (les caractères spéciaux encodés en contiennent).
        $url = str_replace('%', '%%', $url);
        $contenu = "# Réglages de production (écrits par l'installation)\nAPP_ENV=prod\nAPP_DEBUG=0\nAPP_SECRET=$secret\nDATABASE_URL=\"$url\"\n";
        if (false === file_put_contents("$racine/.env.local", $contenu)) {
            throw new RuntimeException('Impossible d\'écrire .env.local.');
        }
        etape('Fichier .env.local écrit');

        // 3. Base de données
        $tables = $pdo->query("SHOW TABLES LIKE 'club'")->fetchAll();
        $sql = "$racine/base-de-donnees.sql";
        if (!$tables) {
            if (!is_file($sql)) {
                throw new RuntimeException('La base est vide et base-de-donnees.sql est introuvable : envoyez-le puis recommencez.');
            }
            @set_time_limit(600);
            $n = importer($pdo, $sql);
            etape("Base de données importée ($n instructions)");
        } else {
            etape('Base de données déjà présente : import non nécessaire');
        }
        $clubs = $pdo->query('SELECT nom FROM club ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $comptes = (int) $pdo->query('SELECT COUNT(*) FROM utilisateur WHERE password IS NOT NULL')->fetchColumn();
        etape(sprintf('%d club(s) : %s ; %d compte(s) avec mot de passe', count($clubs), implode(', ', $clubs), $comptes), $comptes > 0);

        // 4. Vérification de l'application (cache neuf)
        $cache = "$var/cache/prod";
        if (is_dir($cache)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cache, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($cache);
        }
        foreach (['DATABASE_URL', 'APP_ENV', 'APP_DEBUG', 'APP_SECRET'] as $cle) {
            unset($_SERVER[$cle], $_ENV[$cle]);
            putenv($cle);
        }
        require "$racine/vendor/autoload.php";
        (new Symfony\Component\Dotenv\Dotenv())->bootEnv("$racine/.env");
        $kernel = new App\Kernel('prod', false);
        $reponse = $kernel->handle(Symfony\Component\HttpFoundation\Request::create('/connexion'), Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST, false);
        etape('Page de connexion : ' . ($reponse->getStatusCode() === 200 ? 'OK' : 'code ' . $reponse->getStatusCode()), 200 === $reponse->getStatusCode());
        $kernel->terminate(Symfony\Component\HttpFoundation\Request::create('/connexion'), $reponse);

        // 5. Ménage : copie de la base, archives et ce fichier
        foreach (["$racine/base-de-donnees.sql", ...glob("$racine/*.zip") ?: []] as $f) {
            if (is_file($f) && unlink($f)) {
                etape('Supprimé : ' . basename($f));
            }
        }
        unlink(__FILE__);
        etape('Ce fichier d\'installation s\'est supprimé');
    } catch (Throwable $e) {
        $erreur = $e->getMessage();
    }
}

$h = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Installation · Club Foot</title>
    <style>
        body { font: 16px/1.5 system-ui, sans-serif; background: #f5f5f7; color: #1d1d1f; margin: 0; }
        main { max-width: 560px; margin: 2rem auto; background: #fff; border: 1px solid #e3e3e8; border-radius: 10px; padding: 1.5rem 2rem; }
        h1 { color: #1b6fd6; font-size: 1.4rem; margin-top: 0; }
        label { display: block; margin: .9rem 0 .3rem; font-size: .9rem; color: #6b6b72; }
        input { width: 100%; box-sizing: border-box; padding: .55rem; border: 1px solid #e3e3e8; border-radius: 6px; font: inherit; }
        button { margin-top: 1.3rem; background: #1b6fd6; color: #fff; border: 0; border-radius: 8px; padding: .7rem 1.2rem; font: inherit; cursor: pointer; width: 100%; }
        .erreur { background: #fde8eb; color: #9e0b23; padding: .7rem 1rem; border-radius: 8px; }
        ul { padding-left: 1.2rem; } li.ko { color: #9e0b23; } li.ok::marker { content: '✓  '; color: #1e7b3a; } li.ko::marker { content: '✗  '; }
        .aide { font-size: .85rem; color: #6b6b72; }
        a.bouton { display: inline-block; margin-top: 1rem; background: #1b6fd6; color: #fff; padding: .7rem 1.2rem; border-radius: 8px; text-decoration: none; }
    </style>
</head>
<body>
<main>
    <h1>Installation de Club Foot</h1>
    <?php if ($etapes): ?>
        <ul><?php foreach ($etapes as [$texte, $ok]): ?><li class="<?= $ok ? 'ok' : 'ko' ?>"><?= $h($texte) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>

    <?php if ($envoye && !$erreur): ?>
        <p><strong>Installation terminée.</strong> Connectez-vous avec l'adresse e-mail de votre compte.</p>
        <a class="bouton" href="/connexion">Aller à la page de connexion</a>
    <?php else: ?>
        <?php if ($erreur): ?><p class="erreur"><?= $h($erreur) ?></p><?php endif; ?>
        <p class="aide">Les informations de la base sont dans hPanel → <strong>Bases de données → Gestion</strong> (noms complets, qui commencent par <?= $h($prefixe ?: 'u…_') ?>).</p>
        <form method="post" autocomplete="off">
            <label for="code">Code d'accès (affiché par preparer-deploiement.ps1)</label>
            <input id="code" name="code" required>
            <label for="base">Nom de la base de données</label>
            <input id="base" name="base" required value="<?= $h($_POST['base'] ?? $prefixe) ?>">
            <label for="utilisateur">Utilisateur de la base</label>
            <input id="utilisateur" name="utilisateur" required value="<?= $h($_POST['utilisateur'] ?? $prefixe) ?>">
            <label for="mot_de_passe">Mot de passe de la base</label>
            <input id="mot_de_passe" name="mot_de_passe" type="password" required>
            <button type="submit">Installer</button>
        </form>
    <?php endif; ?>
</main>
</body>
</html>
