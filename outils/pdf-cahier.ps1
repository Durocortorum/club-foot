# Refait les deux PDF du cahier des charges (docs/cahier-des-charges.html) sur le Bureau :
#   cahier-des-charges-complet-JJ-MM-AAAA.pdf     version complète (avec Nico et les prix)
#   cahier-des-charges-a-diffuser-JJ-MM-AAAA.pdf  sans Nico ni les prix (outils/cahier-diffusion.php)
# Les PDF précédents de ce nom sont supprimés, pour n'en garder qu'une version de chaque.
# À lancer après chaque modification du cahier des charges : powershell -File outils\pdf-cahier.ps1

$ErrorActionPreference = 'Stop'
$racine = Split-Path -Parent $PSScriptRoot
$bureau = [Environment]::GetFolderPath('Desktop')
$edge = @("${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe", "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe") | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $edge) { throw 'Microsoft Edge est introuvable : il sert à imprimer les PDF.' }

$travail = Join-Path ([IO.Path]::GetTempPath()) 'club-foot-pdf'
New-Item -ItemType Directory -Force $travail | Out-Null
$source = Join-Path $racine 'docs\cahier-des-charges.html'
$diffusion = Join-Path $travail 'cahier-diffusion.html'
& php (Join-Path $racine 'outils\cahier-diffusion.php') $source $diffusion
if ($LASTEXITCODE -ne 0) { throw 'Version à diffuser impossible : le texte du cahier a changé, adapter outils\cahier-diffusion.php.' }

$date = Get-Date -Format 'dd-MM-yyyy'
$pdfs = @(
    @{ Html = $source; Nom = 'cahier-des-charges-complet' },
    @{ Html = $diffusion; Nom = 'cahier-des-charges-a-diffuser' }
)
foreach ($p in $pdfs) {
    # Anciennes versions : supprimées.
    Get-ChildItem $bureau -Filter "$($p.Nom)-*.pdf" -ErrorAction SilentlyContinue | Remove-Item -Force
    $sortie = Join-Path $bureau "$($p.Nom)-$date.pdf"
    # Profil Edge à part : l'impression marche même si Edge est déjà ouvert. Un profil par PDF, sinon le second échoue.
    $profil = Join-Path $travail "edge-$($p.Nom)-$(Get-Random)"
    $url = 'file:///' + ($p.Html -replace '\\', '/')
    Start-Process -FilePath $edge -ArgumentList @('--headless', '--disable-gpu', '--no-pdf-header-footer', "--user-data-dir=$profil", "--print-to-pdf=$sortie", $url) -Wait
    if (-not (Test-Path $sortie)) { throw "PDF non créé : $sortie" }
    Write-Host "PDF prêt : $sortie"
}
