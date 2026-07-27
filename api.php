<?php
/* =========================================================
   Cockpit de crise — CPTS Pau Béarn
   Stockage partagé de l'état du cockpit.

   Un seul fichier, aucune base de données : l'état tient dans
   un JSON déposé à côté de ce script. Suffisant pour l'usage
   visé (une poignée de personnes, quelques centaines de Ko)
   et compatible avec un hébergement mutualisé sans MySQL.

   Deux points méritent l'attention :

   - Chaque écriture ne porte que sur les rubriques modifiées,
     pas sur l'état entier. Deux personnes qui travaillent sur
     deux onglets différents ne s'écrasent donc jamais.
   - Un numéro de version est renvoyé à chaque lecture et
     vérifié à chaque écriture, ce qui permet au cockpit de
     détecter qu'il travaille sur une copie périmée.
   ========================================================= */

/* --- Code d'accès partagé -------------------------------
   À remplacer avant mise en ligne, et à diffuser au copil
   par un autre canal que le lien lui-même. */
const CODE_ACCES = 'A_REMPLACER';

/* Les fichiers de données portent l'extension .php et débutent
   par une instruction de sortie : même servis directement, ils
   n'exposent rien. On ne dépend donc pas du .htaccess, que tous
   les hébergements mutualisés n'appliquent pas. */
const GARDE = "<?php exit; ?>\n";

const DOSSIER = __DIR__ . '/donnees';
const FICHIER = DOSSIER . '/etat.php';
const JOURNAL = DOSSIER . '/journal.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function repondre($code, $corps) {
    http_response_code($code);
    echo json_encode($corps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function etatVide() {
    return ['version' => 0, 'maj' => null, 'par' => null, 'donnees' => new stdClass()];
}

/* Le dossier contient des noms et des numéros de téléphone :
   on refuse aussi son listing, en plus de la garde PHP. */
function preparerDossier() {
    if (!is_dir(DOSSIER)) {
        @mkdir(DOSSIER, 0750, true);
    }
    $htaccess = DOSSIER . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\nDeny from all\nOptions -Indexes\n");
    }
}

function lireEtat() {
    if (!file_exists(FICHIER)) {
        return etatVide();
    }
    $brut = file_get_contents(FICHIER);
    if ($brut === false) {
        return etatVide();
    }
    if (strpos($brut, GARDE) === 0) {
        $brut = substr($brut, strlen(GARDE));
    }
    $d = json_decode($brut, true);
    return is_array($d) ? $d : etatVide();
}

/* Écriture atomique : on passe par un fichier temporaire pour
   qu'une coupure ne laisse jamais un JSON tronqué derrière elle. */
function ecrireEtat($etat) {
    $tmp = FICHIER . '.tmp';
    $contenu = GARDE . json_encode($etat, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $ok = @file_put_contents($tmp, $contenu);
    return $ok !== false && @rename($tmp, FICHIER);
}

function tracer($ligne) {
    if (!file_exists(JOURNAL)) {
        @file_put_contents(JOURNAL, GARDE);
    }
    @file_put_contents(JOURNAL, date('c') . ' ' . $ligne . "\n", FILE_APPEND);
}

preparerDossier();

$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($methode === 'OPTIONS') {
    repondre(204, []);
}

/* --- Contrôle d'accès ----------------------------------- */
$code = $_SERVER['HTTP_X_CODE_ACCES'] ?? ($_GET['code'] ?? '');
if (!hash_equals(CODE_ACCES, (string) $code)) {
    repondre(401, ['erreur' => 'Code d\'accès invalide']);
}

/* --- Lecture -------------------------------------------- */
if ($methode === 'GET') {
    repondre(200, lireEtat());
}

/* --- Écriture ------------------------------------------- */
if ($methode !== 'POST') {
    repondre(405, ['erreur' => 'Méthode non autorisée']);
}

$entree = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($entree) || !isset($entree['donnees']) || !is_array($entree['donnees'])) {
    repondre(400, ['erreur' => 'Requête mal formée']);
}

$fp = @fopen(FICHIER . '.lock', 'c');
if ($fp === false || !flock($fp, LOCK_EX)) {
    repondre(503, ['erreur' => 'Enregistrement momentanément indisponible']);
}

try {
    $actuel = lireEtat();
    $version = isset($actuel['version']) ? (int) $actuel['version'] : 0;
    $attendue = isset($entree['version']) ? (int) $entree['version'] : $version;

    /* La version envoyée est périmée : on refuse plutôt que
       d'écraser le travail de quelqu'un d'autre. Le cockpit
       recharge alors l'état à jour avant de réessayer. */
    if ($attendue !== $version) {
        repondre(409, [
            'erreur'   => 'Le cockpit a été modifié entre-temps',
            'version'  => $version,
            'maj'      => $actuel['maj'] ?? null,
            'par'      => $actuel['par'] ?? null,
            'donnees'  => $actuel['donnees'] ?? new stdClass(),
        ]);
    }

    $donnees = (array) ($actuel['donnees'] ?? []);
    foreach ($entree['donnees'] as $rubrique => $valeur) {
        $donnees[$rubrique] = $valeur;
    }

    $nouveau = [
        'version' => $version + 1,
        'maj'     => date('c'),
        'par'     => substr((string) ($entree['par'] ?? ''), 0, 80),
        'donnees' => $donnees,
    ];

    if (!ecrireEtat($nouveau)) {
        repondre(500, ['erreur' => 'Enregistrement impossible']);
    }

    tracer(sprintf('v%d par %s — rubriques : %s',
        $nouveau['version'],
        $nouveau['par'] !== '' ? $nouveau['par'] : 'inconnu',
        implode(', ', array_keys($entree['donnees']))
    ));

    repondre(200, ['version' => $nouveau['version'], 'maj' => $nouveau['maj']]);
} finally {
    flock($fp, LOCK_UN);
    fclose($fp);
}
