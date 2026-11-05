<?php
/**
 * NEPSEE · Réception des demandes de devis du site nepsee.fr
 *
 * - Envoie la demande à NEPSEE (DESTINATAIRE) avec le client en « Répondre à ».
 * - Envoie un accusé de réception au client.
 * - Protections : méthode POST uniquement, champ piège anti-robot, délai minimal
 *   de remplissage, limite d'envois par adresse IP, nettoyage des en-têtes.
 *
 * Hébergement mutualisé OVH : la fonction mail() d'OVH exige que l'expéditeur
 * soit une adresse du domaine hébergé (ici @nepsee.fr).
 */

declare(strict_types=1);
date_default_timezone_set('Europe/Paris');
mb_internal_encoding('UTF-8');

/* ---------- Réglages ---------- */
const DESTINATAIRE   = 'lucasquicot' . '@' . 'nepsee.fr';   // où arrivent les demandes (écrite en morceaux pour ne pas être aspirée par les robots)
const EXPEDITEUR     = 'contact@nepsee.fr';       // adresse du domaine nepsee.fr
const NOM_EXPEDITEUR = 'NEPSEE';
const TELEPHONE      = '06 98 52 21 37';
const SITE           = 'https://www.nepsee.fr';
const MAX_PAR_HEURE  = 5;                         // envois max par IP et par heure
const DELAI_MIN_SEC  = 3;                         // un humain met plus de 3 s à remplir

const TYPES = [
  'Bureaux et open spaces', 'Restaurant et cuisine professionnelle', 'Local commercial',
  'Local médical ou paramédical', 'Immeuble et copropriété', 'Établissement de nuit',
  'Fin de chantier', 'Vitrerie et façades', 'Autre',
];
const FREQUENCES = ['Quotidienne', 'Plusieurs fois par semaine', 'Hebdomadaire', 'Mensuelle', 'Ponctuelle'];
const HORAIRES   = ['', 'Tôt le matin', 'En journée', 'En soirée', 'Après fermeture', 'Week-end'];

/* ---------- Réponse JSON ---------- */
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function repondre(int $code, array $data): void {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  repondre(405, ['ok' => false, 'error' => 'Méthode non autorisée.']);
}

/* ---------- Lecture et nettoyage ---------- */
function champ(string $nom, int $max = 200): string {
  $v = isset($_POST[$nom]) ? (string) $_POST[$nom] : '';
  $v = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '');
  return mb_substr($v, 0, $max);
}
function ligne(string $v): string { return trim(str_replace(["\r", "\n", "%0a", "%0d"], ' ', $v)); }

// Champ piège : rempli uniquement par les robots. On répond « ok » sans rien envoyer.
if (champ('website') !== '') repondre(200, ['ok' => true]);

$t = (int) champ('t', 20);
if ($t > 0 && (time() - intdiv($t, 1000)) < DELAI_MIN_SEC) repondre(200, ['ok' => true]);

$d = [
  'societe'  => ligne(champ('societe', 120)),
  'nom'      => ligne(champ('nom', 120)),
  'email'    => ligne(champ('email', 160)),
  'tel'      => ligne(champ('tel', 30)),
  'type'     => ligne(champ('type', 80)),
  'surface'  => ligne(champ('surface', 10)),
  'freq'     => ligne(champ('freq', 60)),
  'horaires' => ligne(champ('horaires', 60)),
  'ville'    => ligne(champ('ville', 120)),
  'msg'      => champ('msg', 2000),
];

/* ---------- Validation ---------- */
$erreurs = [];
if (mb_strlen($d['nom']) < 2)                                   $erreurs['nom'] = 'Indiquez le nom de la personne à contacter.';
if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL))            $erreurs['email'] = 'Indiquez une adresse e-mail valide.';
if (strlen(preg_replace('/\D/', '', $d['tel'])) < 10)          $erreurs['tel'] = 'Indiquez un numéro de téléphone à 10 chiffres.';
if (!in_array($d['type'], TYPES, true))                         $erreurs['type'] = 'Choisissez le type de locaux.';
if (!ctype_digit($d['surface']) || (int) $d['surface'] < 10)   $erreurs['surface'] = 'Indiquez une surface d’au moins 10 m².';
if (!in_array($d['freq'], FREQUENCES, true))                    $erreurs['freq'] = 'Choisissez une fréquence.';
if (!in_array($d['horaires'], HORAIRES, true))                  $d['horaires'] = '';
if (mb_strlen($d['ville']) < 2)                                 $erreurs['ville'] = 'Indiquez le code postal et la ville des locaux.';
if ($erreurs) repondre(422, ['ok' => false, 'error' => 'Certains champs sont à compléter.', 'fields' => $erreurs]);

/* ---------- Limite d'envois par IP ---------- */
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$fichier = rtrim(sys_get_temp_dir(), '/') . '/nepsee-devis-' . substr(hash('sha256', $ip . __FILE__), 0, 24) . '.json';
$historique = [];
if (is_file($fichier)) {
  $historique = array_filter((array) json_decode((string) @file_get_contents($fichier), true), fn($ts) => is_int($ts) && $ts > time() - 3600);
}
if (count($historique) >= MAX_PAR_HEURE) {
  repondre(429, ['ok' => false, 'error' => 'Trop de demandes envoyées. Appelez-nous au ' . TELEPHONE . '.']);
}

/* ---------- Construction des e-mails ---------- */
function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

$lignes = [
  'Société'            => $d['societe'] ?: '—',
  'Contact'            => $d['nom'],
  'E-mail'             => $d['email'],
  'Téléphone'          => $d['tel'],
  'Type de locaux'     => $d['type'],
  'Surface'            => $d['surface'] . ' m²',
  'Fréquence'          => $d['freq'],
  'Horaires souhaités' => $d['horaires'] ?: 'Indifférent',
  'Lieu'               => $d['ville'],
];

function tableau(array $lignes): string {
  $h = '';
  foreach ($lignes as $k => $v) {
    $h .= '<tr><td style="padding:10px 0;border-bottom:1px solid #e6e6ea;color:#6e6e73;font-size:12px;letter-spacing:.08em;text-transform:uppercase;width:42%;vertical-align:top">' . e($k) . '</td>'
        . '<td style="padding:10px 0;border-bottom:1px solid #e6e6ea;color:#1d1d1f;font-size:15px;vertical-align:top">' . nl2br(e($v)) . '</td></tr>';
  }
  return $h;
}

function gabarit(string $titre, string $intro, string $corps): string {
  return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($titre) . '</title></head>'
    . '<body style="margin:0;padding:0;background:#f5f5f7;font-family:Helvetica,Arial,sans-serif;color:#1d1d1f">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f7"><tr><td align="center" style="padding:32px 16px">'
    . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:14px;overflow:hidden">'
    . '<tr><td style="background:#05080d;padding:28px 32px"><span style="font-size:20px;font-weight:700;letter-spacing:.32em;color:#3d8bff">NEPSEE</span>'
    . '<div style="margin-top:6px;font-size:11px;letter-spacing:.2em;text-transform:uppercase;color:#9a9aa0">Propreté professionnelle · Lyon</div></td></tr>'
    . '<tr><td style="padding:32px">'
    . '<h1 style="margin:0 0 12px;font-size:24px;line-height:1.2;font-weight:400;color:#1d1d1f">' . e($titre) . '</h1>'
    . '<p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#4a4a4f">' . $intro . '</p>'
    . $corps
    . '</td></tr>'
    . '<tr><td style="padding:20px 32px;background:#f5f5f7;font-size:12px;line-height:1.6;color:#6e6e73">NEPSEE · ' . e(TELEPHONE) . ' · ' . e(EXPEDITEUR) . ' · <a href="' . SITE . '" style="color:#1565c0;text-decoration:none">nepsee.fr</a></td></tr>'
    . '</table></td></tr></table></body></html>';
}

// Texte prêt à coller dans le générateur de devis (remplissage automatique)
$coller = "Société : {$lignes['Société']}\nContact : {$d['nom']}\nE-mail : {$d['email']}\nTéléphone : {$d['tel']}\n"
        . "Lieu d’intervention : {$d['ville']}\nType de locaux : {$d['type']}\nSurface : {$d['surface']} m²\n"
        . "Fréquence : {$d['freq']}\nHoraires : {$lignes['Horaires souhaités']}" . ($d['msg'] !== '' ? "\nPrécisions : {$d['msg']}" : '');

$htmlNepsee = gabarit(
  'Nouvelle demande de devis',
  'Reçue le ' . date('d/m/Y à H:i') . ' depuis le site. Répondez directement à cet e-mail pour écrire au client.',
  '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . tableau($lignes) . '</table>'
  . ($d['msg'] !== '' ? '<p style="margin:24px 0 8px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#6e6e73">Précisions</p><p style="margin:0;font-size:15px;line-height:1.6">' . nl2br(e($d['msg'])) . '</p>' : '')
  . '<p style="margin:28px 0 8px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#6e6e73">À coller dans le générateur de devis</p>'
  . '<pre style="margin:0;padding:16px;background:#f5f5f7;border-radius:10px;font-size:13px;line-height:1.55;white-space:pre-wrap;font-family:Menlo,Consolas,monospace">' . e($coller) . '</pre>'
);
$texteNepsee = "Nouvelle demande de devis (" . date('d/m/Y H:i') . ")\n\n" . $coller . "\n";

$prenom = explode(' ', $d['nom'])[0];
$htmlClient = gabarit(
  'Votre demande de devis est bien reçue',
  'Bonjour ' . e($prenom) . ',<br><br>Merci pour votre demande. Votre interlocuteur NEPSEE vous recontacte sous deux heures ouvrées pour convenir d’une visite de vos locaux et établir votre devis, gratuit et sans engagement.',
  '<p style="margin:0 0 8px;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:#6e6e73">Récapitulatif</p>'
  . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . tableau(array_diff_key($lignes, ['E-mail' => 1])) . '</table>'
  . '<p style="margin:28px 0 0;font-size:15px;line-height:1.6">Une question d’ici là ? Appelez-nous au <strong>' . e(TELEPHONE) . '</strong> ou répondez simplement à cet e-mail.</p>'
  . '<p style="margin:24px 0 0;font-size:15px;line-height:1.6">Bien cordialement,<br>L’équipe NEPSEE</p>'
);
$texteClient = "Bonjour {$prenom},\n\nMerci pour votre demande. Votre interlocuteur NEPSEE vous recontacte sous deux heures ouvrées pour convenir d’une visite de vos locaux et établir votre devis, gratuit et sans engagement.\n\nRécapitulatif\n"
  . "Type de locaux : {$d['type']}\nSurface : {$d['surface']} m²\nFréquence : {$d['freq']}\nLieu : {$d['ville']}\n\n"
  . "Une question ? " . TELEPHONE . " ou répondez à cet e-mail.\n\nBien cordialement,\nL’équipe NEPSEE\n" . SITE . "\n";

/* ---------- Envoi ---------- */
function envoyer(string $a, string $sujet, string $html, string $texte, string $repondreA, string $nomRepondre = ''): bool {
  $frontiere = 'nepsee_' . bin2hex(random_bytes(12));
  $entetes = [
    'MIME-Version: 1.0',
    'From: ' . mb_encode_mimeheader(NOM_EXPEDITEUR, 'UTF-8', 'Q') . ' <' . EXPEDITEUR . '>',
    'Reply-To: ' . ($nomRepondre !== '' ? mb_encode_mimeheader($nomRepondre, 'UTF-8', 'Q') . ' ' : '') . '<' . $repondreA . '>',
    'X-Mailer: NEPSEE site',
    'Content-Type: multipart/alternative; boundary="' . $frontiere . '"',
  ];
  $corps = "--{$frontiere}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
         . chunk_split(base64_encode($texte))
         . "--{$frontiere}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
         . chunk_split(base64_encode($html))
         . "--{$frontiere}--\r\n";
  $sujetEncode = mb_encode_mimeheader($sujet, 'UTF-8', 'B', "\r\n");
  return @mail($a, $sujetEncode, $corps, implode("\r\n", $entetes), '-f' . EXPEDITEUR);
}

$sujet = 'Demande de devis · ' . $d['type'] . ' · ' . $d['ville'];
$ok = envoyer(DESTINATAIRE, $sujet, $htmlNepsee, $texteNepsee, $d['email'], $d['nom']);
if (!$ok) {
  repondre(500, ['ok' => false, 'error' => 'L’envoi n’a pas abouti. Appelez-nous au ' . TELEPHONE . ' ou écrivez à ' . EXPEDITEUR . '.']);
}

// Accusé de réception (un échec ici ne bloque pas la demande, déjà transmise)
envoyer($d['email'], 'Votre demande de devis NEPSEE', $htmlClient, $texteClient, DESTINATAIRE, 'NEPSEE');

$historique[] = time();
@file_put_contents($fichier, json_encode(array_values($historique)), LOCK_EX);

repondre(200, ['ok' => true]);
