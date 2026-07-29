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

/* --- Cloisonnement des crises ---------------------------
   Depuis fin juillet 2026, la CPTS conduit deux crises de front :
   les incendies de Gironde et l'épisode caniculaire sur son propre
   territoire. Un cockpit par crise, sous la même adresse, et
   surtout : aucune donnée en commun. Le paramètre ?crise= choisit
   le jeu de fichiers ; sans lui, on sert l'état historique des
   incendies, tel qu'il était nommé avant l'arrivée du second
   cockpit — rien à migrer.

   La liste est fermée : un nom inconnu retombe sur les incendies
   plutôt que de créer un fichier à la demande. */
$crises = ['canicule' => '-canicule'];
$demandee = (string) ($_GET['crise'] ?? '');
$suffixe = $crises[$demandee] ?? '';

define('FICHIER', DOSSIER . '/etat' . $suffixe . '.php');
define('JOURNAL', DOSSIER . '/journal' . $suffixe . '.php');
define('SUFFIXE_CRISE', $suffixe);

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

/* La collecte ne se mène plus d'un bloc mais par campagnes : une
   liste de matériel précise, ouverte le temps d'un appel, puis
   close. Chacune a son interrupteur ; l'interrupteur global de
   « collecte » reste celui des états ouverts avant les campagnes,
   et vaut toujours pour un formulaire appelé sans campagne. */
function campagnesCollecte($etat) {
    $d = isset($etat['donnees']) && is_array($etat['donnees']) ? $etat['donnees'] : [];
    $c = isset($d['collecte']) && is_array($d['collecte']) ? $d['collecte'] : [];
    return isset($c['campagnes']) && is_array($c['campagnes']) ? $c['campagnes'] : [];
}

function campagneCollecte($etat, $id) {
    if ($id === '') return null;
    foreach (campagnesCollecte($etat) as $c) {
        if (is_array($c) && (string) ($c['id'] ?? '') === $id) return $c;
    }
    return null;
}

/* Une seule campagne ouverte suffit à ouvrir la porte : le dépôt
   lui-même vérifie ensuite que c'est bien celle qu'on lui
   désigne. */
function collecteOuverte($etat) {
    if (porteOuverte($etat, 'collecte')) return true;
    foreach (campagnesCollecte($etat) as $c) {
        if (is_array($c) && !empty($c['ouverte'])) return true;
    }
    return false;
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
function quotaDepot($empreinte, $porte = 'depots', $parIP = DEPOT_PAR_IP, $global = DEPOT_GLOBAL) {
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
    if ($mien >= $parIP || count($recents) >= $global) return false;
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

        /* Seules les campagnes ouvertes sortent d'ici, et seulement
           ce que le formulaire a besoin d'afficher : ni qui les a
           créées, ni ce qu'elles ont déjà reçu. */
        $camps = [];
        foreach (campagnesCollecte($etat) as $k) {
            if (!is_array($k) || empty($k['ouverte'])) continue;
            $arts = [];
            $bruts = isset($k['articles']) && is_array($k['articles']) ? $k['articles'] : [];
            foreach ($bruts as $a) {
                if (count($arts) >= 40) break;
                if (!is_array($a)) continue;
                $nom = txt($a['nom'] ?? '', 140);
                if ($nom === '') continue;
                $arts[] = [
                    'id'        => txt($a['id'] ?? '', 40),
                    'nom'       => $nom,
                    'precision' => txt($a['precision'] ?? '', 240),
                ];
            }
            $camps[] = [
                'id'        => txt($k['id'] ?? '', 40),
                'titre'     => txt($k['titre'] ?? '', 200),
                'cadre'     => txt($k['cadre'] ?? '', 1500),
                'depot'     => txt($k['depot'] ?? '', 400),
                'horsListe' => !empty($k['horsListe']),
                'articles'  => $arts,
            ];
            if (count($camps) >= 20) break;
        }

        repondre(200, [
            'ouverte'   => collecteOuverte($etat),
            'message'   => txt($c['message'] ?? '', 600),
            'besoins'   => txt($c['besoins'] ?? '', 1200),
            'campagnes' => $camps,
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

    $campagne = txt($in['campagne'] ?? '', 40);

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
            /* L'article de la liste que cette ligne coche. C'est ce
               qui permet, au moment de relancer, de dire lequel des
               matériels demandés a trouvé preneur et lequel n'a
               intéressé personne. Vide pour une saisie hors liste. */
            'article'     => txt($l['article'] ?? '', 40),
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

        /* Une déclaration désigne la campagne à laquelle elle
           répond : c'est elle qui fait foi, pas l'état général de
           la collecte. Une campagne refermée entre le moment où
           l'on ouvre le formulaire et celui où on le valide
           n'accepte plus rien — le donateur doit l'apprendre. */
        $campagneTitre = '';
        if ($campagne !== '') {
            $k = campagneCollecte($etat, $campagne);
            if ($k === null) {
                repondre(404, ['erreur' => 'Cet appel aux dons n\'existe plus. Appelez la coordination de la CPTS Pau Béarn.']);
            }
            if (empty($k['ouverte'])) {
                repondre(403, ['erreur' => 'Cet appel aux dons vient d\'être clos. Votre déclaration n\'a pas été enregistrée — appelez la coordination de la CPTS Pau Béarn si votre don reste possible.']);
            }
            $campagneTitre = txt($k['titre'] ?? '', 200);
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
            'campagne'     => $campagne,
            'campagneTitre'=> $campagneTitre,
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
    $commune    = txt($in['commune'] ?? '', 80);
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

    /* Commune d'exercice et courriel sont devenus obligatoires
       côté formulaire : le serveur le tient aussi, sinon la règle
       ne vaut que pour ceux qui passent par la page. */
    if ($nom === '' || $prenom === '' || $profession === '' || $tel === '' || $commune === '') {
        repondre(400, ['erreur' => 'Nom, prénom, profession, commune d\'exercice et téléphone sont nécessaires']);
    }
    if ($mail === '') {
        repondre(400, ['erreur' => 'Un courriel valide est nécessaire']);
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
            'commune'     => $commune,
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

/* =========================================================
   RECENSEMENT DES GÉNÉRALISTES — porte publique

   La troisième porte, après la collecte et le renfort. Celle-ci
   ne demande pas des bras pour partir : elle demande des
   créneaux de consultation, au cabinet, pour les personnes
   évacuées de Gironde qui n'ont pas de médecin traitant ici.

   Le médecin coche ses demi-journées sur un calendrier de trois
   semaines. Elles arrivent sous forme « 2026-07-29:matin » —
   une liste courte, vérifiée date par date avant écriture.
   ========================================================= */

const CONSULT_PLAFOND = 2000;   /* garde-fou sur la taille du vivier   */
const CONSULT_MOTIFS  = 10;     /* motifs cochés par médecin           */
const CONSULT_DISPOS  = 60;     /* demi-journées cochées au calendrier */
const CONSULT_HEURES  = 16;     /* horaires précis par demi-journée    */

if (isset($_GET['consultations'])) {

    if ($methode === 'GET') {
        $etat = lireEtat();
        $d = isset($etat['donnees']) && is_array($etat['donnees']) ? $etat['donnees'] : [];
        $c = isset($d['consultations']) && is_array($d['consultations']) ? $d['consultations'] : [];
        repondre(200, [
            'ouverte' => porteOuverte($etat, 'consultations'),
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

    /* Champ leurre, comme ailleurs : seuls les robots le remplissent. */
    if (txt($in['site'] ?? '', 40) !== '') {
        repondre(200, ['ok' => true, 'ref' => 'MG-2026-0000']);
    }

    $nom     = txt($in['nom'] ?? '', 80);
    $prenom  = txt($in['prenom'] ?? '', 80);
    $tel     = txt($in['tel'] ?? '', 40);
    /* Un cabinet donne souvent deux lignes : le standard, puis le
       portable quand le standard ne répond pas. Le second numéro se
       range derrière le premier, jamais à sa place. */
    $tel2    = txt($in['tel2'] ?? '', 40);
    $adresse = txt($in['adresse'] ?? '', 200);
    $mail    = txt($in['mail'] ?? '', 120);
    if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) $mail = '';

    $accepte = !empty($in['accepte']);

    $motifs = [];
    $brutMotifs = isset($in['motifs']) && is_array($in['motifs']) ? $in['motifs'] : [];
    foreach ($brutMotifs as $m) {
        if (count($motifs) >= CONSULT_MOTIFS) break;
        $m = txt($m, 120);
        if ($m !== '') $motifs[] = $m;
    }

    /* Les demi-journées cochées : « AAAA-MM-JJ:matin » ou
       « :apresmidi », rien d'autre. La forme ne suffit pas — un
       31 février la respecte : la date doit exister. Ce qui ne
       rentre pas est écarté sans faire échouer l'envoi. */
    $dispos = [];
    $brutDispos = isset($in['dispos']) && is_array($in['dispos']) ? $in['dispos'] : [];
    foreach ($brutDispos as $x) {
        if (count($dispos) >= CONSULT_DISPOS) break;
        $x = txt($x, 22);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2}):(matin|apresmidi)$/', $x, $m)) continue;
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) continue;
        if (!in_array($x, $dispos, true)) $dispos[] = $x;
    }
    /* Le matin passe avant l'après-midi : l'ordre alphabétique
       ferait le contraire. */
    usort($dispos, function ($a, $b) {
        [$ja, $pa] = explode(':', $a);
        [$jb, $pb] = explode(':', $b);
        return $ja === $jb
            ? ($pa === 'matin' ? -1 : 1) * ($pa === $pb ? 0 : 1)
            : strcmp($ja, $jb);
    });

    /* Les horaires précis, demi-journée par demi-journée. Une clé
       qui ne correspond à aucune demi-journée cochée n'a pas de
       sens : elle est écartée. */
    $heures = [];
    $brutHeures = isset($in['heures']) && is_array($in['heures']) ? $in['heures'] : [];
    foreach ($brutHeures as $cle => $liste) {
        $cle = txt($cle, 22);
        if (!in_array($cle, $dispos, true) || !is_array($liste)) continue;
        $h = [];
        foreach ($liste as $x) {
            if (count($h) >= CONSULT_HEURES) break;
            $x = txt($x, 5);
            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $x) && !in_array($x, $h, true)) $h[] = $x;
        }
        sort($h);
        if ($h) $heures[$cle] = $h;
    }

    /* La même chose en clair, pour l'export et l'impression :
       « Mar 29/07 matin (09:30, 10:00) · Jeu 31/07 après-midi ». */
    $parJour = [];
    foreach ($dispos as $x) {
        [$j, $p] = explode(':', $x);
        $lib = ($p === 'apresmidi') ? 'après-midi' : 'matin';
        if (isset($heures[$x])) $lib .= ' (' . implode(', ', $heures[$x]) . ')';
        $parJour[$j][] = $lib;
    }
    $JOURS = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
    $lisible = [];
    foreach ($parJour as $j => $parts) {
        $t = strtotime($j . ' 12:00:00');
        $etiq = ($t ? $JOURS[(int) date('w', $t)] . ' ' . date('d/m', $t) : $j);
        $lisible[] = $etiq . ' ' . implode(' et ', array_unique($parts));
    }
    $creneaux = implode(' · ', $lisible);
    $precisions = txt($in['precisions'] ?? '', 400);
    if ($precisions !== '') {
        $creneaux = $creneaux === '' ? $precisions : $creneaux . "\n" . $precisions;
    }

    if ($nom === '' || $prenom === '' || $tel === '') {
        repondre(400, ['erreur' => 'Nom, prénom et téléphone sont nécessaires']);
    }
    if (empty($in['conditions'])) {
        repondre(400, ['erreur' => 'Le cadre du recensement doit être accepté']);
    }

    if (!quotaDepot(empreinteAppelant(), 'medecins')) {
        repondre(429, ['erreur' => 'Trop de réponses en peu de temps. Réessayez dans une heure ou appelez la coordination.']);
    }

    $fp = @fopen(FICHIER . '.lock', 'c');
    if ($fp === false || !flock($fp, LOCK_EX)) {
        repondre(503, ['erreur' => 'Enregistrement momentanément indisponible']);
    }

    try {
        $etat = lireEtat();
        if (!porteOuverte($etat, 'consultations')) {
            repondre(403, ['erreur' => 'Le recensement n\'est pas ouvert']);
        }

        $donnees = (array) ($etat['donnees'] ?? []);
        $med = isset($donnees['medecins']) && is_array($donnees['medecins']) ? $donnees['medecins'] : [];
        if (count($med) >= CONSULT_PLAFOND) {
            repondre(507, ['erreur' => 'Registre saturé — contactez la coordination']);
        }

        $id = 'mf' . substr(md5(uniqid('', true)), 0, 10);
        $le = date('c');

        $med[] = [
            'id'         => $id,
            'date'       => date('Y-m-d'),
            'heure'      => date('H:i'),
            'nom'        => $nom,
            'prenom'     => $prenom,
            'rpps'       => txt($in['rpps'] ?? '', 40),
            'structure'  => txt($in['structure'] ?? '', 160),
            'commune'    => txt($in['commune'] ?? '', 80),
            'adresse'    => $adresse,
            'tel'        => $tel,
            'tel2'       => $tel2,
            'mail'       => $mail,
            'rdv'        => txt($in['rdv'] ?? '', 200),
            /* Séparés par un point médian, jamais par une virgule :
               « Certificat, document administratif de santé » en contient
               une, et la liste deviendrait illisible. */
            'motifs'     => implode(' · ', $motifs),
            'capacite'   => txt($in['capacite'] ?? '', 80),
            'creneaux'   => $creneaux,
            'dispos'     => $dispos,
            'heures'     => $heures,
            'delai'      => txt($in['delai'] ?? '', 80),
            'conditions' => txt($in['contraintes'] ?? '', 600),
            /* Le médecin traitant, c'est la question de la fin : elle
               n'est posée qu'après tout le reste, et sa réponse par
               défaut n'engage à rien. */
            'mt'         => txt($in['mt'] ?? '', 30) ?: 'À préciser',
            /* La diffusion est déclarée par l'intéressé lui-même :
               c'est son accord, pas une case que le copil coche à sa
               place. Elle vaut donc telle quelle. */
            'diffusion'  => !empty($in['diffusion']) ? 'Oui' : 'Non',
            'par'        => '',
            'origine'    => '',
            'statut'     => $accepte ? 'Volontaire' : 'Indisponible',
            'note'       => txt($in['note'] ?? '', 800),
            'source'     => 'formulaire',
            /* Reste à rappeler pour confirmer les créneaux : ce que le
               médecin coche entre deux consultations se confirme de
               vive voix avant qu'on lui adresse quelqu'un. */
            'aVerifier'  => $accepte,
            'engagement' => [
                'le'        => $le,
                'nom'       => trim($prenom . ' ' . $nom),
                'conditions'=> true,
                'diffusion' => !empty($in['diffusion']),
                'empreinte' => empreinteAppelant(),
            ],
            'auteur'     => 'Formulaire en ligne',
            'quand'      => $le,
        ];

        $donnees['medecins'] = $med;
        $nouveau = [
            'version' => (isset($etat['version']) ? (int) $etat['version'] : 0) + 1,
            'maj'     => date('c'),
            'par'     => trim($prenom . ' ' . $nom) . ' (recensement médecins)',
            'donnees' => $donnees,
        ];

        if (!ecrireEtat($nouveau)) {
            repondre(500, ['erreur' => 'Enregistrement impossible']);
        }
        tracer(sprintf('v%d recensement généraliste — %s, %s, %d créneau%s',
            $nouveau['version'], trim($prenom . ' ' . $nom),
            $accepte ? 'accepte' : 'n\'accepte pas',
            count($dispos), count($dispos) > 1 ? 'x' : ''));

        repondre(200, [
            'ok'  => true,
            'id'  => $id,
            'ref' => 'MG-2026-' . strtoupper(substr($id, -4)),
            'le'  => $le,
        ]);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/* =========================================================
   SAISIE SUR PLACE — quatrième porte publique

   Les trois précédentes s'adressent à qui déclare pour
   lui-même, depuis son cabinet. Celle-ci s'adresse à la
   personne qui tient la table à l'entrée d'une collecte et note
   ce qui arrive, déposant par déposant, pendant deux heures.

   Elle n'a pas le code du cockpit. Elle a un lien qui ne vaut
   que pour une collecte : l'identifiant de celle-ci et un jeton
   tiré au sort par le cockpit au moment où le lien est fabriqué.
   Le jeton se révoque d'un clic, et la clôture de la collecte
   ferme la porte d'elle-même — un lien retrouvé le lendemain
   dans un vieux message n'écrit plus rien.

   Ce qui entre ici rejoint directement les lignes de dépôt de
   la collecte : les totaux, la synthèse par matériel et le
   récapitulatif imprimable s'en déduisent sans ressaisie.
   ========================================================= */

const TERRAIN_LIGNES  = 20;    /* matériels par déposant            */
const TERRAIN_DEPOTS  = 400;   /* dépôts par heure et par adresse   */
const TERRAIN_GLOBAL  = 1200;  /* dépôts par heure, toutes adresses */
const TERRAIN_PLAFOND = 4000;  /* lignes de dépôt par collecte      */

/* Ce qui se range en consommable sans qu'on ait à le dire. Le
   cockpit fait le même tri (CAT_PAR_DEFAUT) : toute addition
   ici doit y être reportée. */
function categorieTerrain($materiel) {
    $m = function_exists('mb_strtolower') ? mb_strtolower($materiel, 'UTF-8') : strtolower($materiel);
    foreach (['masque', 'gel ', 'gel hydro', 'gant', 'compresse', 'pansement',
              'seringue', 'aiguille', 'bandelette', 'lancette', 'blouse', 'surblouse',
              'charlotte', 'sursouliers', 'solut', 'antiseptique'] as $mot) {
        if (strpos($m, $mot) !== false) return 'Consommable';
    }
    return 'Dispositif médical';
}

/* La collecte visée par le lien, à condition que le jeton
   corresponde. On renvoie aussi son rang : l'écriture se fait
   sur la liste relue sous verrou, jamais sur cette copie. */
function collecteTerrain($etat, $id, $jeton) {
    if ($id === '' || $jeton === '') return null;
    $d = isset($etat['donnees']) && is_array($etat['donnees']) ? $etat['donnees'] : [];
    $pts = isset($d['points']) && is_array($d['points']) ? $d['points'] : [];
    foreach ($pts as $i => $p) {
        if (!is_array($p) || (string) ($p['id'] ?? '') !== $id) continue;
        $j = isset($p['jeton']) ? (string) $p['jeton'] : '';
        if ($j === '' || !hash_equals($j, $jeton)) return null;
        return ['rang' => $i, 'point' => $p];
    }
    return null;
}

/* Une collecte close ou annulée n'accepte plus rien : la personne
   qui tient la table a fermé, et ce qui arrive après relève d'une
   autre collecte. */
function terrainOuvert($p) {
    $s = (string) ($p['statut'] ?? 'Ouvert');
    return $s !== 'Clôturé' && $s !== 'Annulé';
}

/* Le récapitulatif tel qu'il s'affiche sur place : les totaux
   par matériel et la liste des déposants déjà passés. Il se
   recalcule depuis les lignes, jamais depuis un compteur. */
function synthesTerrain($p) {
    $lignes = isset($p['lignes']) && is_array($p['lignes']) ? $p['lignes'] : [];
    $mats = [];
    $deps = [];
    $unites = 0;
    $n = 0;
    foreach ($lignes as $l) {
        if (!is_array($l)) continue;
        $m = trim((string) ($l['materiel'] ?? ''));
        if ($m === '') continue;
        $n++;
        $q = (float) str_replace(',', '.', (string) ($l['quantite'] ?? 0));
        if (!is_finite($q) || $q < 0) $q = 0;
        $unites += $q;
        if (!isset($mats[$m])) $mats[$m] = 0;
        $mats[$m] += $q;
        $d = trim((string) ($l['deposant'] ?? ''));
        if ($d !== '') {
            if (!isset($deps[$d])) $deps[$d] = ['nom' => $d, 'qualite' => (string) ($l['qualite'] ?? ''), 'unites' => 0];
            $deps[$d]['unites'] += $q;
        }
    }
    arsort($mats);
    $parMat = [];
    foreach ($mats as $m => $q) $parMat[] = ['materiel' => $m, 'quantite' => $q];
    return [
        'lignes'    => $n,
        'unites'    => $unites,
        'deposants' => array_values($deps),
        'parMat'    => $parMat,
    ];
}

if (isset($_GET['terrain'])) {

    $idPoint = txt($_GET['p'] ?? '', 40);
    $jeton   = txt($_GET['j'] ?? '', 64);

    /* La page interroge cette adresse avant de s'afficher : le
       lieu, le créneau, la liste du matériel attendu et ce qui a
       déjà été noté. Rien d'autre de l'état ne sort d'ici. */
    if ($methode === 'GET') {
        $etat = lireEtat();
        $trouve = collecteTerrain($etat, $idPoint, $jeton);
        if ($trouve === null) {
            repondre(200, ['ouverte' => false, 'motif' => 'lien']);
        }
        $p = $trouve['point'];
        if (!terrainOuvert($p)) {
            repondre(200, [
                'ouverte' => false,
                'motif'   => 'close',
                'lieu'    => txt($p['lieu'] ?? '', 200),
                'statut'  => txt($p['statut'] ?? '', 40),
            ]);
        }

        /* Le matériel proposé d'un doigt : ce qui a été demandé
           par les appels aux dons ouverts, plus ce qui est déjà
           passé par la table — c'est presque toujours la même
           chose qui revient. */
        $suggestions = [];
        $ajouter = function ($x) use (&$suggestions) {
            $x = txt($x, 140);
            if ($x !== '' && !in_array($x, $suggestions, true) && count($suggestions) < 24) $suggestions[] = $x;
        };
        foreach (campagnesCollecte($etat) as $c) {
            if (!is_array($c) || empty($c['ouverte'])) continue;
            foreach ((isset($c['articles']) && is_array($c['articles']) ? $c['articles'] : []) as $a) {
                if (is_array($a)) $ajouter($a['nom'] ?? '');
            }
        }
        foreach ((isset($p['lignes']) && is_array($p['lignes']) ? $p['lignes'] : []) as $l) {
            if (is_array($l)) $ajouter($l['materiel'] ?? '');
        }
        foreach (['Thermomètre', 'Hémoglucotest', 'Tensiomètre', 'Saturomètre', 'Stéthoscope',
                  'Masques FFP2', 'Gel hydroalcoolique'] as $x) $ajouter($x);

        repondre(200, [
            'ouverte'     => true,
            'lieu'        => txt($p['lieu'] ?? '', 200),
            'adresse'     => txt($p['adresse'] ?? '', 200),
            'date'        => txt($p['date'] ?? '', 12),
            'debut'       => txt($p['debut'] ?? '', 8),
            'fin'         => txt($p['fin'] ?? '', 8),
            'responsable' => txt($p['responsable'] ?? '', 160),
            'destination' => txt($p['destination'] ?? '', 200),
            'suggestions' => $suggestions,
            'recap'       => synthesTerrain($p),
        ]);
    }

    if ($methode !== 'POST') {
        repondre(405, ['erreur' => 'Méthode non autorisée']);
    }

    $brut = file_get_contents('php://input');
    if ($brut === false || strlen($brut) > DEPOT_CORPS_MAX) {
        repondre(413, ['erreur' => 'Saisie trop volumineuse']);
    }
    $in = json_decode($brut ?: '', true);
    if (!is_array($in)) {
        repondre(400, ['erreur' => 'Saisie mal formée']);
    }

    /* Champ leurre, comme aux autres portes : seuls les robots le
       remplissent. On répond comme si tout allait bien. */
    if (txt($in['site'] ?? '', 40) !== '') {
        repondre(200, ['ok' => true, 'ref' => 'DP-2026-0000']);
    }

    $idPoint = $idPoint !== '' ? $idPoint : txt($in['p'] ?? '', 40);
    $jeton   = $jeton   !== '' ? $jeton   : txt($in['j'] ?? '', 64);

    $nom        = txt($in['nom'] ?? '', 80);
    $prenom     = txt($in['prenom'] ?? '', 80);
    $profession = txt($in['profession'] ?? '', 120);
    $deposant   = trim($prenom . ' ' . $nom);

    $lignes = [];
    $brutLignes = isset($in['lignes']) && is_array($in['lignes']) ? $in['lignes'] : [];
    foreach ($brutLignes as $l) {
        if (count($lignes) >= TERRAIN_LIGNES) break;
        if (!is_array($l)) continue;
        $mat = txt($l['materiel'] ?? '', 140);
        if ($mat === '') continue;
        $q = (float) str_replace(',', '.', txt($l['quantite'] ?? '', 12));
        if (!is_finite($q) || $q < 0) $q = 0;
        if ($q > 100000) $q = 100000;
        $lignes[] = [
            'deposant'  => $deposant,
            'qualite'   => $profession,
            'materiel'  => $mat,
            'categorie' => categorieTerrain($mat),
            'quantite'  => $q == (int) $q ? (int) $q : $q,
        ];
    }

    if ($deposant === '' || !count($lignes)) {
        repondre(400, ['erreur' => 'Le nom du déposant et au moins un matériel sont nécessaires']);
    }

    if (!quotaDepot(empreinteAppelant(), 'terrain', TERRAIN_DEPOTS, TERRAIN_GLOBAL)) {
        repondre(429, ['erreur' => 'Trop de dépôts en peu de temps. Attendez une minute, ou appelez la coordination.']);
    }

    $fp = @fopen(FICHIER . '.lock', 'c');
    if ($fp === false || !flock($fp, LOCK_EX)) {
        repondre(503, ['erreur' => 'Enregistrement momentanément indisponible']);
    }

    try {
        $etat = lireEtat();
        $trouve = collecteTerrain($etat, $idPoint, $jeton);
        if ($trouve === null) {
            repondre(403, ['erreur' => 'Ce lien de saisie n\'est plus valable. Appelez la coordination de la CPTS Pau Béarn.']);
        }
        if (!terrainOuvert($trouve['point'])) {
            repondre(403, ['erreur' => 'Cette collecte a été clôturée : la saisie n\'est plus acceptée. Le dépôt reste à consigner par la coordination.']);
        }

        $donnees = (array) ($etat['donnees'] ?? []);
        $points = isset($donnees['points']) && is_array($donnees['points']) ? $donnees['points'] : [];
        $rang = $trouve['rang'];
        $p = is_array($points[$rang] ?? null) ? $points[$rang] : null;
        if ($p === null || (string) ($p['id'] ?? '') !== $idPoint) {
            repondre(409, ['erreur' => 'La collecte a changé pendant la saisie. Rechargez la page.']);
        }

        $anciennes = isset($p['lignes']) && is_array($p['lignes']) ? $p['lignes'] : [];
        if (count($anciennes) + count($lignes) > TERRAIN_PLAFOND) {
            repondre(507, ['erreur' => 'Collecte saturée — appelez la coordination']);
        }

        $le = date('c');
        $id = 'dt' . substr(md5(uniqid('', true)), 0, 10);
        foreach ($lignes as $k => $l) {
            $l['id'] = $id . '-' . $k;
            /* D'où vient la ligne : le récapitulatif ne distingue
               pas, mais la coordination doit pouvoir savoir ce qui
               a été noté sur place et ce qu'elle a saisi elle-même. */
            $l['source'] = 'terrain';
            $l['quand']  = $le;
            $anciennes[] = $l;
        }
        $p['lignes'] = $anciennes;
        $points[$rang] = $p;
        $donnees['points'] = $points;

        $nouveau = [
            'version' => (isset($etat['version']) ? (int) $etat['version'] : 0) + 1,
            'maj'     => date('c'),
            'par'     => $deposant . ' (saisie sur place)',
            'donnees' => $donnees,
        ];

        if (!ecrireEtat($nouveau)) {
            repondre(500, ['erreur' => 'Enregistrement impossible']);
        }
        tracer(sprintf('v%d dépôt sur place — %s, %s (%d ligne%s)',
            $nouveau['version'], txt($p['lieu'] ?? '', 80), $deposant,
            count($lignes), count($lignes) > 1 ? 's' : ''));

        repondre(200, [
            'ok'    => true,
            'id'    => $id,
            'ref'   => 'DP-2026-' . strtoupper(substr($id, -4)),
            'le'    => $le,
            'recap' => synthesTerrain($p),
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

/* =========================================================
   PIÈCES JOINTES

   Le récapitulatif d'un point de collecte signé sur place, la
   photo d'un bordereau : des documents qui font foi et qu'on ne
   peut pas retaper. Ils ne tiennent pas dans l'état partagé —
   celui-ci est relu en entier toutes les quinze secondes par
   chaque poste — et vivent donc à côté, dans donnees/pieces.
   L'état ne garde que le nom du fichier et son identifiant.

   Réservé au copil : le contrôle d'accès ci-dessus est déjà
   passé. La lecture accepte le code en paramètre d'adresse, pour
   qu'un lien s'ouvre dans un onglet du navigateur.
   ========================================================= */

/* Les pièces suivent le même cloisonnement que l'état : celles
   d'une crise ne se listent ni ne s'ouvrent depuis l'autre. */
define('PIECES', DOSSIER . '/pieces' . SUFFIXE_CRISE);
const PIECE_MAX = 4000000;   /* octets, une fois décodé */

/* Liste fermée : rien d'exécutable ne peut entrer. Le contenu
   est écrit en .bin, jamais sous son extension d'origine, et le
   dossier refuse déjà d'être servi (.htaccess + garde PHP). */
function typesPiece() {
    return [
        'pdf'  => 'application/pdf',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'heic' => 'image/heic',
        'csv'  => 'text/csv',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
}

if (isset($_GET['piece'])) {
    if (!is_dir(PIECES)) {
        @mkdir(PIECES, 0750, true);
    }
    $id = preg_replace('/[^a-z0-9]/', '', strtolower((string) $_GET['piece']));

    /* --- Lecture d'une pièce --- */
    if ($methode === 'GET') {
        if ($id === '') {
            repondre(400, ['erreur' => 'Pièce non désignée']);
        }
        $brutMeta = @file_get_contents(PIECES . '/' . $id . '.json');
        $fichier  = PIECES . '/' . $id . '.bin';
        if ($brutMeta === false || !file_exists($fichier)) {
            repondre(404, ['erreur' => 'Pièce introuvable']);
        }
        if (strpos($brutMeta, GARDE) === 0) {
            $brutMeta = substr($brutMeta, strlen(GARDE));
        }
        $meta = json_decode($brutMeta, true);
        if (!is_array($meta)) {
            repondre(500, ['erreur' => 'Pièce illisible']);
        }
        $nom = str_replace(['"', "\r", "\n"], '', (string) ($meta['nom'] ?? 'piece'));
        header('Content-Type: ' . ($meta['mime'] ?? 'application/octet-stream'));
        header('Content-Disposition: inline; filename="' . $nom . '"');
        header('Content-Length: ' . filesize($fichier));
        header('Cache-Control: private, max-age=3600');
        readfile($fichier);
        exit;
    }

    if ($methode !== 'POST') {
        repondre(405, ['erreur' => 'Méthode non autorisée']);
    }

    $in = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($in)) {
        repondre(400, ['erreur' => 'Requête mal formée']);
    }

    /* --- Retrait d'une pièce --- */
    if (!empty($in['supprimer'])) {
        if ($id === '') {
            repondre(400, ['erreur' => 'Pièce non désignée']);
        }
        @unlink(PIECES . '/' . $id . '.bin');
        @unlink(PIECES . '/' . $id . '.json');
        tracer('pièce retirée — ' . $id);
        repondre(200, ['ok' => true]);
    }

    /* --- Dépôt d'une pièce --- */
    $nom = txt($in['nom'] ?? '', 160);
    if ($nom === '') {
        repondre(400, ['erreur' => 'Nom de fichier manquant']);
    }
    $ext = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
    $types = typesPiece();
    if (!isset($types[$ext])) {
        repondre(415, ['erreur' => 'Format non accepté : ' . implode(', ', array_keys($types))]);
    }

    /* Le fichier arrive encodé en base64, éventuellement précédé
       de l'en-tête « data: » que produit le navigateur. */
    $b64 = (string) ($in['donnees'] ?? '');
    $virgule = strpos($b64, ',');
    if (strpos($b64, 'data:') === 0 && $virgule !== false) {
        $b64 = substr($b64, $virgule + 1);
    }
    $contenu = base64_decode($b64, true);
    if ($contenu === false || $contenu === '') {
        repondre(400, ['erreur' => 'Fichier illisible']);
    }
    if (strlen($contenu) > PIECE_MAX) {
        repondre(413, ['erreur' => 'Fichier trop volumineux (4 Mo au maximum)']);
    }

    $id = 'p' . substr(md5(uniqid('', true)), 0, 12);
    $meta = [
        'id'     => $id,
        'nom'    => $nom,
        'mime'   => $types[$ext],
        'taille' => strlen($contenu),
        'le'     => date('c'),
        'par'    => txt($in['par'] ?? '', 80),
    ];
    if (@file_put_contents(PIECES . '/' . $id . '.bin', $contenu) === false
        || @file_put_contents(PIECES . '/' . $id . '.json', GARDE . json_encode($meta, JSON_UNESCAPED_UNICODE)) === false) {
        repondre(500, ['erreur' => 'Enregistrement de la pièce impossible']);
    }
    tracer(sprintf('pièce déposée — %s (%s, %d o) par %s',
        $id, $nom, $meta['taille'], $meta['par'] !== '' ? $meta['par'] : 'inconnu'));
    repondre(200, $meta);
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
