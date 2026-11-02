# Prépare une MISE À JOUR du code pour un site déjà installé chez l'hébergeur (Hostinger) :
#   var/deploiement/mise-a-jour-AAAAMMJJ-HHMM.zip
# Contient uniquement le code (src, templates, config, public avec les assets compilés...).
# Ni base de données, ni .env.local, ni photos, ni vendor : ceux du serveur sont conservés.
# Si composer.lock ou les entités ont changé, utilisez plutôt preparer-deploiement.ps1.
#
# Lancer depuis le dossier du projet :  pwsh outils\preparer-mise-a-jour.ps1
# Puis, dans le gestionnaire de fichiers hPanel :
#   1. envoyer le zip à la racine de l'application et l'extraire en écrasant les fichiers existants ;
#   2. supprimer le zip.
# Le fichier .version du paquet change à chaque mise à jour : l'application prend alors un nouveau
# dossier de cache (voir Kernel::getCacheDir), inutile de vider var/cache à la main.
$ErrorActionPreference = "Stop"
$projet = Resolve-Path (Join-Path $PSScriptRoot "..")
$sortie = Join-Path $projet "var\deploiement"
$build = Join-Path $sortie "mise-a-jour"

if (Test-Path $build) { Remove-Item $build -Recurse -Force }
New-Item -ItemType Directory -Force $build | Out-Null

"1/3 Copie du code"
foreach ($d in "assets", "bin", "config", "public", "src", "templates", "translations") {
  if (-not (Test-Path "$projet\$d")) { continue }
  robocopy "$projet\$d" "$build\$d" /E /NFL /NDL /NJH /NJS /NP /XD "$projet\public\assets" | Out-Null
  if ($LASTEXITCODE -ge 8) { throw "Échec de la copie de $d (robocopy $LASTEXITCODE)" }
}
foreach ($f in "composer.json", "composer.lock", "importmap.php", ".env") {
  Copy-Item "$projet\$f" $build
}

"2/3 Compilation du CSS et du JavaScript"
# Compilé dans le projet (vendor nécessaire) puis déplacé : public/assets ne doit pas rester en local,
# sinon le serveur de développement servirait ces fichiers figés au lieu des sources.
Push-Location $projet
try {
  # Sans débogage, le cache prod ne détecte pas les fichiers modifiés : les empreintes des assets
  # resteraient celles de la compilation précédente et les navigateurs garderaient l'ancien CSS.
  Remove-Item "$projet\var\cache\prod" -Recurse -Force -ErrorAction SilentlyContinue
  $env:APP_ENV = "prod"; $env:APP_DEBUG = "0"
  php bin/console asset-map:compile --quiet
  if ($LASTEXITCODE) { throw "Échec de asset-map:compile" }
} finally {
  Remove-Item Env:APP_ENV, Env:APP_DEBUG -ErrorAction SilentlyContinue
  Pop-Location
}
Move-Item "$projet\public\assets" "$build\public\assets"

"3/3 Création de l'archive"
$version = Get-Date -Format "yyyyMMdd-HHmm"
Set-Content "$build\.version" $version -NoNewline
$zip = Join-Path $sortie ("mise-a-jour-$version.zip")
Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.IO.Compression.ZipFile]::CreateFromDirectory($build, $zip, [System.IO.Compression.CompressionLevel]::Optimal, $false)
Remove-Item $build -Recurse -Force

$taille = [math]::Round((Get-Item $zip).Length / 1MB, 1)
"Paquet prêt : $zip ($taille Mo)"
"Sur le serveur : extraire à la racine de l'application en écrasant les fichiers, puis supprimer le zip."
