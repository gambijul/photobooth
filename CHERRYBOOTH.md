# CherryBooth

Branche `cherrybooth` : la version de Photobooth qui tourne sur le CherryBooth
(Raspberry Pi 3, Nikon D3200, Canon SELPHY CP1500). Elle part de `dev` et
regroupe :

| Branche / commit | Contenu |
|---|---|
| `feat/background-remote-upload` (PR #1674) | Envoi SFTP en arrière-plan, plus de double chargement de l'image |
| `feat/start-screen-qr` (PR #1675) | QR code optionnel sur l'écran d'accueil |
| `fix/camera-robustness` | Service caméra : watchdog systemd, arrêt propre, `set_config` qui ne tue plus le service, reconnexion de l'aperçu, `--previewFps`, journaux lisibles après une coupure de courant |
| `feat/admin-dashboard` | Tableau de bord de santé dans l'admin |
| `fix/admin-session-expired` | Plus d'enregistrement qui tourne sans fin quand la session admin a expiré |
| commit `feat(cameracontrol): optional live view autofocus` | Option `--autofocus` (mise au point en live view avant la photo) |
| commit `feat(remote-storage): skip empty folder/title` | Plus de dossier `n-a` côté SFTP |

## Réglages propres au Pi

Ils ne sont pas dans le dépôt (fichiers privés ou système) :

- `config/my.config.inc.php`
  - `commands.take_picture` :
    `python3 /var/www/html/api/cameracontrol.py --device /dev/video9 --autofocus --capture-image-and-download %s`
  - `commands.preview` : `python3 cameracontrol.py --device /dev/video9`
  - `ftp.baseFolder` = `images`, `ftp.folder` et `ftp.title` vides
    (photos dans `Downloads\CherryBooth\images` et `\thumbs` sur le NAS)
  - `start_screen.qr_*` : QR code vers https://gallery.gambi.fr/, en bas à droite
  - `ui.skip_welcome` = `true`
- `private/overrides.css` : animations allégées pour le Pi 3, QR code à 75 %
- `/etc/systemd/system/ffmpeg-webcam.service` : à aligner sur l'unité générée
  par `install-photobooth.sh` (`Type=notify`, `WatchdogSec=30`,
  `TimeoutStopSec=15`, `--previewFps 15`), puis `systemctl daemon-reload`

## Déployer

Depuis le 8 novembre 2026, `/var/www/html` sur le Pi est un dépôt git sur la
branche `cherrybooth` (propriétaire `www-data`). L'ancien état, la version
d'août avec ses patchs manuels, est conservé dans la branche `pi-aout-patchs`.
Une archive complète est aussi gardée dans
`/root/photobooth-avant-cherrybooth-20261108.tgz`.

Mettre à jour (le Pi n'a pas accès à GitHub, on passe par un bundle) :

1. Sur le PC : `git bundle create cb.bundle <dernier commit déployé>..cherrybooth`,
   puis copier le bundle dans `/tmp` sur le Pi.
2. Sur le Pi, en tant que `www-data`, dans `/var/www/html` :
   `git fetch /tmp/cb.bundle cherrybooth:refs/remotes/bundle/cherrybooth`
   puis `git merge --ff-only bundle/cherrybooth`.
3. Construire, toujours en tant que `www-data` avec `HOME=/var/www` :
   `npm install && npm run build` (environ 5 minutes sur le Pi 3). Le build
   lance aussi `composer install` et reformate les sources : `git status` doit
   rester propre ensuite, sinon corriger la branche.
4. Redémarrer `php8.4-fpm`, `ffmpeg-webcam` puis le kiosque (`getty@tty1`).
5. Vérifier une photo, l'aperçu, l'envoi SFTP et le tableau de bord
   (Admin → « Afficher le tableau de bord »).

À savoir :

- `npm run build` supprime `welcome/.skip_welcome`. `ui.skip_welcome` est donc
  activé dans `my.config.inc.php` pour que l'écran de bienvenue ne revienne pas.
- Retour arrière : `git switch pi-aout-patchs`, puis remettre l'ancienne unité
  et l'ancienne config (sauvegardée dans `/root/my.config.inc.php.avant-cherrybooth`),
  ou restaurer l'archive.

Éteindre le Pi depuis l'admin plutôt qu'en coupant le courant : les coupures
brutales laissent des blocs illisibles dans les journaux et peuvent abîmer la
carte SD.
