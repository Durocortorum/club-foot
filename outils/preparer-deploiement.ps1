# Prépare une INSTALLATION complète chez l'hébergeur (Hostinger) :
#   var/deploiement/club-foot-AAAAMMJJ-HHMM.zip
# contenant le code sans les outils de développement, les dépendances de production, les assets
# compilés, une copie de la base (base-de-donnees.sql), les photos, bulletins et logos des clubs
# (var/photos, var/documents, var/club) et une page d'installation à usage unique
# (public/installation-XXXX.php, protégée par le code d'accès affiché à la fin).
#
# Lancer depuis le dossier du projet :  pwsh outils\preparer-deploiement.ps1
# Puis, dans hPanel :
#   1. créer une base MySQL (Bases de données > Gestion) ;
#   2. envoyer les zips et extraire-XXXX.php dans le dossier du site, sans rien extraire ;
#   3. ouvrir https://<site>/extraire-XXXX.php : il extrait les zips puis mène à l'installation.
# Pour les mises à jour suivantes du code seul : preparer-mise-a-jour.ps1.
# Le paquet contient des données personnelles (mineurs) : ne le partagez pas, supprimez-le après l'envoi.
param(
  [string]$mysqldump = "C:\wamp64\bin\mysql\mysql5.7.36\bin\mysqldump.exe",
  [string]$base = "club_foot",
  [string]$utilisateurMysql = "root"
)
$ErrorActionPreference = "Stop"
$projet = Resolve-Path (Join-Path $PSScriptRoot "..")
$sortie = Join-Path $projet "var\deploiement"
$horodatage = Get-Date -Format "yyyyMMdd-HHmm"
# Dossier de travail neuf à chaque fois : l'antivirus garde parfois des fichiers de vendor verrouillés.
$build = Join-Path $sortie "club-foot-$horodatage"

if (Test-Path $build) { Remove-Item $build -Recurse -Force }
New-Item -ItemType Directory -Force $build | Out-Null

"1/6 Copie du code"
robocopy $projet $build /E /NFL /NDL /NJH /NJS /NP `
  /XD "$projet\vendor" "$projet\var" "$projet\node_modules" "$projet\.git" "$projet\tests" "$projet\outils" "$projet\public\assets" "$projet\.idea" "$projet\.vscode" "$projet\.claude" `
  /XF ".env.local" ".env.local.php" ".env.*.local" | Out-Null
if ($LASTEXITCODE -ge 8) { throw "Échec de la copie (robocopy $LASTEXITCODE)" }

"2/6 Dépendances de production (composer)"
# Copie du vendor local plutôt que composer install : l'antivirus verrouille les fichiers que composer
# extrait et l'installation échoue (« Could not delete ... »). L'autoloader ne garde que la production.
robocopy "$projet\vendor" "$build\vendor" /E /NFL /NDL /NJH /NJS /NP | Out-Null
if ($LASTEXITCODE -ge 8) { throw "Échec de la copie de vendor (robocopy $LASTEXITCODE)" }
Push-Location $build
try {
  composer dump-autoload --no-dev --optimize --no-scripts --no-interaction --quiet
  if ($LASTEXITCODE) { throw "Échec de composer dump-autoload" }

  "3/6 Compilation du CSS et du JavaScript"
  $env:APP_ENV = "prod"; $env:APP_DEBUG = "0"
  php bin/console asset-map:compile --quiet
  if ($LASTEXITCODE) { throw "Échec de asset-map:compile" }
  Remove-Item Env:APP_ENV, Env:APP_DEBUG
  Remove-Item "var\cache" -Recurse -Force -ErrorAction SilentlyContinue
} finally { Pop-Location }

"4/6 Photos, bulletins et logos"
foreach ($d in "photos", "documents", "club") {
  $src = Join-Path $projet "var\$d"
  New-Item -ItemType Directory -Force (Join-Path $build "var\$d") | Out-Null
  if (Test-Path $src) { Copy-Item "$src\*" (Join-Path $build "var\$d") -Recurse -Force }
}

"5/6 Copie de la base de données"
& $mysqldump --user=$utilisateurMysql --default-character-set=utf8mb4 --single-transaction --no-tablespaces --skip-add-locks --result-file="$build\base-de-donnees.sql" $base
if ($LASTEXITCODE) { throw "Échec de mysqldump" }
# Retire la mention du serveur d'origine, refusée par certains hébergeurs (DEFINER, SQL_LOG_BIN...).
(Get-Content "$build\base-de-donnees.sql" -Raw -Encoding UTF8) -replace '(?m)^/\*!50013 DEFINER=.*\*/\r?\n', '' |
  Set-Content "$build\base-de-donnees.sql" -Encoding UTF8 -NoNewline

# Page d'installation : nom imprévisible, protégée par un code dont seule l'empreinte est dans le fichier.
$code = -join ((1..12) | ForEach-Object { 'abcdefghjkmnpqrstuvwxyz23456789'[(Get-Random -Maximum 31)] })
$empreinte = php -r "echo password_hash('$code', PASSWORD_DEFAULT);"
$page = "installation-" + (-join ((1..6) | ForEach-Object { '{0:x2}' -f (Get-Random -Maximum 256) })) + ".php"
(Get-Content "$projet\outils\installation.php" -Raw -Encoding UTF8).Replace('__EMPREINTE__', $empreinte) |
  Set-Content "$build\public\$page" -Encoding UTF8 -NoNewline
Set-Content "$build\.version" $horodatage -NoNewline

"6/6 Création des archives"
# Plusieurs petites archives plutôt qu'une : le gestionnaire de fichiers de hPanel renvoie une
# erreur 500 en extrayant ~10 000 fichiers d'un coup. Chacune s'extrait à la racine du site.
$envoi = "$build-envoi"
New-Item -ItemType Directory -Force $envoi | Out-Null
Add-Type -AssemblyName System.IO.Compression, System.IO.Compression.FileSystem
function Nouvelle-Archive([string]$zip, $fichiers) {
  $archive = [System.IO.Compression.ZipFile]::Open($zip, 'Create')
  try {
    foreach ($f in $fichiers) {
      $chemin = $f.FullName.Substring($build.Length + 1).Replace('\', '/')
      [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $f.FullName, $chemin, 'Optimal') | Out-Null
    }
  } finally { $archive.Dispose() }
}
$tous = Get-ChildItem $build -Recurse -File -Force
$relatif = { $_.FullName.Substring($build.Length + 1) }
Nouvelle-Archive "$envoi\1-code.zip" ($tous | Where-Object { (& $relatif) -notmatch '^(vendor|var)\\' })
Nouvelle-Archive "$envoi\2-photos.zip" ($tous | Where-Object { (& $relatif) -match '^var\\' })
# vendor : paquets (vendor\editeur\paquet) regroupés par lots d'environ 1 500 fichiers.
$lot = @(); $n = 0
$paquets = $tous | Where-Object { (& $relatif) -match '^vendor\\' } |
  Group-Object { ((& $relatif) -split '\\', 4)[0..2] -join '\' }
foreach ($p in $paquets) {
  if ($lot.Count -and $lot.Count + $p.Count -gt 1500) { Nouvelle-Archive "$envoi\vendor-$((++$n)).zip" $lot; $lot = @() }
  $lot += $p.Group
}
if ($lot.Count) { Nouvelle-Archive "$envoi\vendor-$((++$n)).zip" $lot }
# Page qui extrait ces archives sur le serveur, même code d'accès que l'installation.
$extraction = "extraire-" + (-join ((1..6) | ForEach-Object { '{0:x2}' -f (Get-Random -Maximum 256) })) + ".php"
(Get-Content "$projet\outils\extraction.php" -Raw -Encoding UTF8).Replace('__EMPREINTE__', $empreinte) |
  Set-Content "$envoi\$extraction" -Encoding UTF8 -NoNewline

Remove-Item $build -Recurse -Force -ErrorAction SilentlyContinue
if (Test-Path $build) { "(Dossier de travail $build verrouillé : à supprimer plus tard.)" }

"Archives prêtes dans $envoi :"
Get-ChildItem $envoi | ForEach-Object { "  {0,-16} {1,6} Mo" -f $_.Name, [math]::Round($_.Length / 1MB, 1) }
"Tout envoyer à la racine du site (public_html) sans rien extraire, puis ouvrir :"
"Page d'extraction   : https://<votre-site>/$extraction  (enchaîne sur l'installation)"
"Page d'installation : https://<votre-site>/$page"
"Code d'accès        : $code"
