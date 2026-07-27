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

   S'y ajoute une porte de dépôt public (?depot), utilisée par
   don.html : les professionnels de santé qui proposent du
   matériel n'ont pas le code du copil. Cette porte n'ajoute
   qu'une ligne à la rubrique « dons » et ne renvoie jamais
   l'état du cockpit.
   ========================================================= */

date_default_timezone_set('Europe/Paris');

/* --- Code d'accès partagé -------------------------------
   Mot de passe commun au copil, demandé une fois par appareil.
   Le laisser vide ouvre le cockpit à quiconque connaît l'adresse. */
const CODE_ACCES = 'bearn2026';

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

/* =========================================================
   DÉPÔTS PUBLICS — formulaires don-de-materiel.html et
   appel-au-renfort.html

   Ouverts sans code d'accès : ni les donateurs ni les
   professionnels qui se recensent ne peuvent l'avoir. En
   contrepartie, chacune de ces portes ne sait faire qu'une
   chose — ajouter une ligne à sa rubrique — et ne renvoie rien
   de l'état existant. Elles restent fermées tant que le copil
   n'a pas ouvert la collecte, ou l'appel au renfort, depuis le
   cockpit.
   ========================================================= */

const DEPOT_PAR_IP    = 5;      /* dépôts par heure et par adresse   */
const DEPOT_GLOBAL    = 60;     /* dépôts par heure, toutes adresses */
const DEPOT_LIGNES    = 12;     /* lignes de matériel par bordereau  */
const DEPOT_PLAFOND   = 3000;   /* garde-fou sur la taille du fichier */
const DEPOT_CORPS_MAX = 60000;  /* octets acceptés en entrée         */

/* Le formulaire est public : on nettoie tout ce qui entre,
   longueur comprise, avant de l'écrire dans l'état partagé. */
function txt($v, $max) {
    if (is_bool($v) || is_null($v)) return '';
    if (!is_scalar($v)) return '';
    $v = (string) $v;
    if (!preg_match('//u', $v)) $v = preg_replace('/[^\x20-\x7E]/', '', $v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v);
    $v = trim(preg_replace('/[ \t]+/', ' ', $v));
    return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
}

/* Chaque porte publique a son interrupteur dans l'état partagé :
   « collecte » pour les dons, « renfort » pour le recensement. */
function porteOuverte($etat, $rubrique) {
    $d = isset($etat['donnees']) && is_array($etat['donnees']) ? $etat['donnees'] : [];
    $c = isset($d[$rubrique]) && is_array($d[$rubrique]) ? $d[$rubrique] : [];
    return !empty($c['ouverte']);
}

function collecteOuverte($etat) {
    return porteOuverte($etat, 'collecte');
}

/* Empreinte de l'appelant : on ne conserve pas l'adresse IP en
   clair, seulement un condensé qui suffit à compter. */
function empreinteAppelant() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return substr(hash('sha256', $ip . '|cpts64'), 0, 16);
}

/* Fenêtre glissante d'une heure, deux plafonds : par adresse
   pour la saisie répétée, global pour le reste. Un compteur par
   porte : une journée de collecte chargée ne doit pas fermer le
   recensement des renforts, et réciproquement. */
function quotaDepot($empreinte, $porte = 'depots') {
    $f = DOSSIER . '/' . $porte . '.php';
    $liste = [];
    if (file_exists($f)) {
        $b = file_get_contents($f);
        if ($b !== false) {
            if (strpos($b, GARDE) === 0) $b = substr($b, strlen(GARDE));
            $d = json_decode($b, true);
            if (is_array($d)) $liste = $d;
        }
    }
    $depuis = time() - 3600;
    $recents = [];
    $mien = 0;
    foreach ($liste as $x) {
        if (!is_array($x) || !isset($x['t']) || $x['t'] <= $depuis) continue;
        $recents[] = $x;
        if (isset($x['e']) && $x['e'] === $empreinte) $mien++;
    }
    if ($mien >= DEPOT_PAR_IP || count($recents) >= DEPOT_GLOBAL) return false;
    $recents[] = ['t' => time(), 'e' => $empreinte];
    @file_put_contents($f, GARDE . json_encode($recents));
    return true;
}

if (isset($_GET['depot'])) {

    /* Le formulaire interroge cette adresse avant de s'afficher :
       inutile de faire remplir un bordereau si la collecte est
       fermée. Rien d'autre ne sort d'ici. */
    if ($methode === 'GET') {
        $etat = lireEtat();
        $d = isset($etat['donnees']) && is_array($etat['donnees']) ? $etat['donnees'] : [];
        $c = isset($d['collecte']) && is_array($d['collecte']) ? $d['collecte'] : [];
        repondre(200, [
            'ouverte' => collecteOuverte($etat),
            'message' => txt($c['message'] ?? '', 600),
            'besoins' => txt($c['besoins'] ?? '', 1200),
        ]);
    }

    if ($methode !== 'POST') {
        repondre(405, ['erreur' => 'Méthode non autorisée']);
    }

    $brut = file_get_contents('php://input');
    if ($brut === false || strlen($brut) > DEPOT_CORPS_MAX) {
        repondre(413, ['erreur' => 'Formulaire trop volumineux']);
    }
    $in = json_decode($brut ?: '', true);
    if (!is_array($in)) {
        repondre(400, ['erreur' => 'Formulaire mal formé']);
    }

    /* Champ leurre : invisible à l'écran, seuls les robots le
       remplissent. On répond comme si tout allait bien. */
    if (txt($in['site'] ?? '', 40) !== '') {
        repondre(200, ['ok' => true, 'ref' => 'BD-2026-0000']);
    }

    $structure   = txt($in['structure'] ?? '', 160);
    $representant= txt($in['representant'] ?? '', 120);
    $tel         = txt($in['tel'] ?? '', 40);
    $mail        = txt($in['mail'] ?? '', 120);
    if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) $mail = '';

    $lignes = [];
    $brutLignes = isset($in['lignes']) && is_array($in['lignes']) ? $in['lignes'] : [];
    foreach ($brutLignes as $l) {
        if (count($lignes) >= DEPOT_LIGNES) break;
        if (!is_array($l)) continue;
        $des = txt($l['designation'] ?? '', 200);
        if ($des === '') continue;
        $lignes[] = [
            'designation' => $des,
            'quantite'    => txt($l['quantite'] ?? '', 60),
            'etat'        => txt($l['etat'] ?? '', 60),
            'obs'         => txt($l['obs'] ?? '', 200),
        ];
    }

    if ($structure === '' || $representant === '' || $tel === '' || !count($lignes)) {
        repondre(400, ['erreur' => 'Dénomination, représentant, téléphone et au moins une ligne de matériel sont nécessaires']);
    }
    if (empty($in['conditions'])) {
        repondre(400, ['erreur' => 'Les conditions du don doivent être acceptées']);
    }

    if (!quotaDepot(empreinteAppelant())) {
        repondre(429, ['erreur' => 'Trop de dépôts en peu de temps. Réessayez dans une heure ou appelez la coordination.']);
    }

    $fp = @fopen(FICHIER . '.lock', 'c');
    if ($fp === false || !flock($fp, LOCK_EX)) {
        repondre(503, ['erreur' => 'Enregistrement momentanément indisponible']);
    }

    try {
        $etat = lireEtat();
        if (!collecteOuverte($etat)) {
            repondre(403, ['erreur' => 'La collecte de matériel n\'est pas ouverte']);
        }

        $donnees = (array) ($etat['donnees'] ?? []);
        $dons = isset($donnees['dons']) && is_array($donnees['dons']) ? $donnees['dons'] : [];
        if (count($dons) >= DEPOT_PLAFOND) {
            repondre(507, ['erreur' => 'Registre saturé — contactez la coordination']);
        }

        $id = 'df' . substr(md5(uniqid('', true)), 0, 10);

        /* La colonne « matériel » du cockpit reste lisible d'un
           coup d'œil : le détail ligne à ligne vit à côté, et
           c'est lui qui remplit le bordereau. */
        $resume = [];
        foreach ($lignes as $l) {
            $resume[] = $l['designation'] . ($l['quantite'] !== '' ? ' (' . $l['quantite'] . ')' : '');
        }

        $dons[] = [
            'id'           => $id,
            'date'         => date('Y-m-d'),
            'heure'        => date('H:i'),
            'structure'    => $structure,
            'type'         => txt($in['type'] ?? '', 60),
            'commune'      => txt($in['commune'] ?? '', 80),
            'adresse'      => txt($in['adresse'] ?? '', 200),
            'tel'          => $tel,
            'mail'         => $mail,
            'interlocuteur'=> $representant,
            'par'          => '',
            'reponse'      => 'Oui',
            'materiel'     => implode(', ', $resume),
            'quantite'     => txt($in['quantite'] ?? '', 80),
            'dispo'        => txt($in['dispo'] ?? '', 200),
            'lieu'         => '',
            'retrait'      => 'À organiser',
            'bordereau'    => 'Engagement en ligne',
            'note'         => txt($in['note'] ?? '', 800),
            'lignes'       => $lignes,
            'source'       => 'formulaire',
            'aVerifier'    => true,
            'engagement'   => [
                'le'         => date('c'),
                'nom'        => $representant,
                'qualite'    => txt($in['qualite'] ?? '', 120),
                'conditions' => true,
                'empreinte'  => empreinteAppelant(),
            ],
            'auteur'       => 'Formulaire en ligne',
            'quand'        => date('c'),
            'suivi'        => [],
        ];

        $donnees['dons'] = $dons;
        $nouveau = [
            'version' => (isset($etat['version']) ? (int) $etat['version'] : 0) + 1,
            'maj'     => date('c'),
            'par'     => $structure . ' (formulaire)',
            'donnees' => $donnees,
        ];

        if (!ecrireEtat($nouveau)) {
            repondre(500, ['erreur' => 'Enregistrement impossible']);
        }
        tracer(sprintf('v%d dépôt formulaire — %s (%d ligne%s)',
            $nouveau['version'], $structure, count($lignes), count($lignes) > 1 ? 's' : ''));

        /* L'horodatage renvoyé est celui écrit dans le registre :
           l'exemplaire du donateur et celui de la CPTS portent
           ainsi la même heure, quelle que soit la pendule de
           l'appareil qui a rempli le formulaire. */
        repondre(200, [
            'ok'  => true,
            'id'  => $id,
            'ref' => 'BD-2026-' . strtoupper(substr($id, -4)),
            'le'  => $dons[count($dons) - 1]['engagement']['le'],
        ]);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/* =========================================================
   RECENSEMENT PUBLIC — formulaire appel-au-renfort.html

   Même porte que le dépôt de dons, pour la rubrique
   « volontaires ». Elle n'ajoute qu'une ligne au registre des
   professionnels volontaires et ne renvoie jamais l'état.

   Ce qui entre ici, ce sont des noms, des numéros de téléphone
   et des numéros d'inscription à l'ordre : la porte reste fermée
   tant que le copil n'a pas ouvert l'appel au renfort, et rien
   n'en ressort jamais.
   ========================================================= */

const RENFORT_PLAFOND = 2000;   /* garde-fou sur la taille du registre */
const RENFORT_TYPES   = 12;     /* types de renfort cochés par personne */

if (isset($_GET['renfort'])) {

    /* Le formulaire interroge cette adresse avant de s'afficher :
       inutile de faire remplir un recensement si l'appel est
       fermé. Rien d'autre ne sort d'ici. */
    if ($methode === 'GET') {
        $etat = lireEtat();
        $d = isset($etat['donnees']) && is_array($etat['donnees']) ? $etat['donnees'] : [];
        $c = isset($d['renfort']) && is_array($d['renfort']) ? $d['renfort'] : [];
        repondre(200, [
            'ouverte' => porteOuverte($etat, 'renfort'),
            'message' => txt($c['message'] ?? '', 600),
            'cadre'   => txt($c['cadre'] ?? '', 1200),
        ]);
    }

    if ($methode !== 'POST') {
        repondre(405, ['erreur' => 'Méthode non autorisée']);
    }

    $brut = file_get_contents('php://input');
    if ($brut === false || strlen($brut) > DEPOT_CORPS_MAX) {
        repondre(413, ['erreur' => 'Formulaire trop volumineux']);
    }
    $in = json_decode($brut ?: '', true);
    if (!is_array($in)) {
        repondre(400, ['erreur' => 'Formulaire mal formé']);
    }

    /* Champ leurre : invisible à l'écran, seuls les robots le
       remplissent. On répond comme si tout allait bien. */
    if (txt($in['site'] ?? '', 40) !== '') {
        repondre(200, ['ok' => true, 'ref' => 'AR-2026-0000']);
    }

    $nom        = txt($in['nom'] ?? '', 80);
    $prenom     = txt($in['prenom'] ?? '', 80);
    $profession = txt($in['profession'] ?? '', 80);
    $tel        = txt($in['tel'] ?? '', 40);
    $mail       = txt($in['mail'] ?? '', 120);
    if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) $mail = '';

    /* Les types de renfort cochés arrivent en liste et se rangent
       dans un seul champ texte, lisible tel quel à l'export. */
    $types = [];
    $brutTypes = isset($in['renforts']) && is_array($in['renforts']) ? $in['renforts'] : [];
    foreach ($brutTypes as $t) {
        if (count($types) >= RENFORT_TYPES) break;
        $t = txt($t, 100);
        if ($t !== '') $types[] = $t;
    }

    if ($nom === '' || $prenom === '' || $profession === '' || $tel === '') {
        repondre(400, ['erreur' => 'Nom, prénom, profession et téléphone sont nécessaires']);
    }
    if (empty($in['conditions'])) {
        repondre(400, ['erreur' => 'Les conditions du recensement doivent être acceptées']);
    }

    if (!quotaDepot(empreinteAppelant(), 'renforts')) {
        repondre(429, ['erreur' => 'Trop d\'inscriptions en peu de temps. Réessayez dans une heure ou appelez la coordination.']);
    }

    $fp = @fopen(FICHIER . '.lock', 'c');
    if ($fp === false || !flock($fp, LOCK_EX)) {
        repondre(503, ['erreur' => 'Enregistrement momentanément indisponible']);
    }

    try {
        $etat = lireEtat();
        if (!porteOuverte($etat, 'renfort')) {
            repondre(403, ['erreur' => 'L\'appel au renfort n\'est pas ouvert']);
        }

        $donnees = (array) ($etat['donnees'] ?? []);
        $vol = isset($donnees['volontaires']) && is_array($donnees['volontaires']) ? $donnees['volontaires'] : [];
        if (count($vol) >= RENFORT_PLAFOND) {
            repondre(507, ['erreur' => 'Registre saturé — contactez la coordination']);
        }

        $id = 'vf' . substr(md5(uniqid('', true)), 0, 10);
        $le = date('c');

        $vol[] = [
            'id'          => $id,
            'date'        => date('Y-m-d'),
            'heure'       => date('H:i'),
            'nom'         => $nom,
            'prenom'      => $prenom,
            'profession'  => $profession,
            'exercice'    => txt($in['exercice'] ?? '', 60),
            'rpps'        => txt($in['rpps'] ?? '', 40),
            'tel'         => $tel,
            'mail'        => $mail,
            'commune'     => txt($in['commune'] ?? '', 80),
            'structure'   => txt($in['structure'] ?? '', 160),
            'renforts'    => implode(', ', $types),
            'zone'        => txt($in['zone'] ?? '', 100),
            'vehicule'    => txt($in['vehicule'] ?? '', 60),
            'dispo'       => txt($in['dispo'] ?? '', 400),
            'apartir'     => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in['apartir'] ?? '')) ? $in['apartir'] : '',
            'duree'       => txt($in['duree'] ?? '', 60),
            'nuit'        => txt($in['nuit'] ?? '', 20),
            'experience'  => txt($in['experience'] ?? '', 600),
            'engage'      => txt($in['engage'] ?? '', 200),
            /* Ce que le professionnel déclare de lui-même ne vaut pas
               vérification : le copil le confirme au téléphone avant
               que le moindre nom sorte du cockpit. */
            'continuite'  => 'À vérifier',
            'assurance'   => 'À vérifier',
            'accord'      => !empty($in['transmission']) ? 'Oui' : 'Non',
            'par'         => '',
            'transmis'    => '',
            'affectation' => '',
            'statut'      => 'Recensé',
            'note'        => txt($in['note'] ?? '', 800),
            'source'      => 'formulaire',
            'aVerifier'   => true,
            'engagement'  => [
                'le'           => $le,
                'nom'          => trim($prenom . ' ' . $nom),
                'conditions'   => true,
                'transmission' => !empty($in['transmission']),
                'empreinte'    => empreinteAppelant(),
            ],
            'auteur'      => 'Formulaire en ligne',
            'quand'       => $le,
            'suivi'       => [],
        ];

        $donnees['volontaires'] = $vol;
        $nouveau = [
            'version' => (isset($etat['version']) ? (int) $etat['version'] : 0) + 1,
            'maj'     => date('c'),
            'par'     => trim($prenom . ' ' . $nom) . ' (recensement)',
            'donnees' => $donnees,
        ];

        if (!ecrireEtat($nouveau)) {
            repondre(500, ['erreur' => 'Enregistrement impossible']);
        }
        tracer(sprintf('v%d recensement renfort — %s, %s',
            $nouveau['version'], trim($prenom . ' ' . $nom), $profession));

        repondre(200, [
            'ok'  => true,
            'id'  => $id,
            'ref' => 'AR-2026-' . strtoupper(substr($id, -4)),
            'le'  => $le,
        ]);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/* --- Contrôle d'accès ----------------------------------- */
if (CODE_ACCES !== '') {
    $code = $_SERVER['HTTP_X_CODE_ACCES'] ?? ($_GET['code'] ?? '');
    if (!hash_equals(CODE_ACCES, (string) $code)) {
        repondre(401, ['erreur' => 'Code d\'accès invalide']);
    }
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
