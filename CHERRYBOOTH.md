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
  - `start_screen.qr_*` : QR code vers https://gallery.gambi.fr/, en bas à droite, sans texte
- `private/overrides.css` : animations allégées pour le Pi 3, QR code à 75 %
- `/etc/systemd/system/ffmpeg-webcam.service` : à aligner sur l'unité générée
  par `install-photobooth.sh` (`Type=notify`, `WatchdogSec=30`,
  `TimeoutStopSec=15`, `--previewFps 15`), puis `systemctl daemon-reload`

## Déployer

Le Pi tourne sur une version d'août (`a92bb121`) avec des patchs appliqués à la
main. Pour passer sur cette branche :

1. Sauvegarder `config/`, `private/` et `data/`.
2. Construire les fichiers générés (`npm run build`, ou au minimum
   `npx gulp`) : CSS, JS et `resources/revisions.json`.
3. Copier le résultat dans `/var/www/html`, puis lancer
   `composer install --no-dev` si les dépendances PHP ont changé.
4. Mettre à jour l'unité `ffmpeg-webcam.service` et la commande `take_picture`
   (voir ci-dessus), puis redémarrer le service caméra et le kiosque.
5. Vérifier une photo, l'aperçu, l'envoi SFTP et le tableau de bord
   (Admin → « Afficher le tableau de bord »).

Éteindre le Pi depuis l'admin plutôt qu'en coupant le courant : les coupures
brutales laissent des blocs illisibles dans les journaux et peuvent abîmer la
carte SD.
