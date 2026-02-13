<?php
/**
 * Titre:  Webservice simple pour dépot d'images
 * Auteur: Maxime Larrivée-Roy <mlarriveeroy@gmail.com>
 * Date:   7 septembre 2025
 */


// Load le fichier de configuration / Voir config.dummy.json
if(!$config = json_decode(@file_get_contents(__DIR__ . '/config.json'))) {
	header("HTTP/1.0 500 Mais où t'as mis le putain de fichier de configuration?");
	exit;
}


// Envoyer sur la page d'accueil les requêtes autre que POST ou OPTIONS 
if(!in_array($_SERVER['REQUEST_METHOD'], ['OPTIONS', 'POST'])) {
	readfile(__DIR__ . '/service.html');
	exit;
}


// Vérifier l'origine de la requête
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && in_array($origin, $config->allowed, true)) {
	header("Access-Control-Allow-Origin: $origin");
	header('Vary: Origin');
} else {
	header("HTTP/1.0 401 D'où c'est que tu viens?");
	header('Access-Control-Allow-Credentials: true');
	exit;
}


// Les méthodes qu'on accepte
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400');


// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
	http_response_code(204);
	exit;
}


// Vérifier si une authentification est présente
if(!($auth = getallheaders()['Authorization'] ?? null)) {
	header("HTTP/1.0 401 Plaît-il ?");
	exit;
}


// Vérifier si le Bearer token est présent
if(!preg_match('#^Bearer\s+(.*)$#i', $auth, $m)) {
	header("HTTP/1.0 401 On ne parle pas la même langue.");
	exit;
}


// Vérifier si le token est valide
// Utiliser une DB de tokens à la place du fichier de config
if(!in_array($m[1], $config->tokens)) {
	header("HTTP/1.0 401 Vous ne passerez pas!");
	exit;
}


// Une image est-elle présente dans la requête?
if(empty($_FILES['image'])) {
	header("HTTP/1.0 400 As-tu oublié quelque chose?");
	exit;
}


// Est-ce qu'il y a eu une erreur d'upload?
if($_FILES['image']['error']) {
	header("HTTP/1.0 400 Pouvez-vous répéter la question?");
	exit;
}


// Est-ce que le fichier est vide?
if(!$_FILES['image']['size']) {
	header("HTTP/1.0 400 Ta boîte est vide.");
	exit;
}


// Est-ce que le fichier dépasse la grosseur maximale?
if($_FILES['image']['size'] > $config->maxsize) {
	header('HTTP/1.0 422 Mais elle est bien trop grosse!');
	exit;
}


// Est-ce que le fichier est un type d'image accepté?
if(!in_array($_FILES['image']['type'], $config->accepted)) {
	header("HTTP/1.0 422 Drôle de format d'image...");
	exit;
}


// Vérifier l'accessibilité du fichier temporaire
if(!is_file($_FILES['image']['tmp_name'])) {
	header("HTTP/1.0 500 Woups j'ai perdu le fichier...");
	exit;
}


// Lire le fichier temporaire
if(!$bytes = @file_get_contents($_FILES['image']['tmp_name'])) {
	header("HTTP/1.0 500 Ma maman veut pas que je lise le fichier!");
	exit;
}


// Génère un hashing sécuritaire
$slug = '';
$buffer = $bits = $i = 0;
$hash = hash('sha256', $bytes, true);
$alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
while ($i < strlen($hash) && strlen($slug) < 8) {
	$buffer = ($buffer << 8) | ord($hash[$i++]);
	$bits += 8;
	while ($bits >= 5 && strlen($slug) < 8) {
		$bits -= 5;
		$slug .= $alphabet[($buffer >> $bits) & 31];
	}
}


// Décompose le hashing pour créer un arbre de dossiers
$folder = __DIR__ . '/images/' . join('/', array_slice(str_split($slug, 2), 0, 3));
if(!is_dir($folder) && !@mkdir($folder, 0777, true)) {
	header("HTTP/1.0 500 Ouff je pense que je n'ai pas les droits.");
	exit;
}


// Vérifie encore le mime type pour en extraire le type
if(!preg_match('#^image/(.*)$#i', $_FILES['image']['type'], $m)) {
	header("HTTP/1.0 500 C'était pas supposé arriver.");
	exit;
}


// Construit l'URL et vérifie si le fichier existe déjà
$destination = $folder . '/' . $slug . '.' . preg_replace('#jpe?g#i', 'jpg', $m[1]);
$url = ($_SERVER['HTTPS'] == 'on' ? 'https' : $_SERVER['REQUEST_SCHEME']) . '://' . $_SERVER['HTTP_HOST'] . '/' . pathinfo($destination, PATHINFO_BASENAME);
if(is_file($destination)) {
	echo $url;
	exit;
}


// Écrire le fichier dans l'arbre de dossiers
if(!@file_put_contents($destination, $bytes)) {
	header("HTTP/1.0 500 Pas capable écrire le fichier :(");
	exit;
}


// Bonsoir elle est partie!
echo $url;
exit;