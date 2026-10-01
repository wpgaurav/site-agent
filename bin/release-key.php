<?php
/**
 * Generate an Ed25519 keypair for signing Site Agent releases.
 *
 * Put the public key in Updater::PUBLIC_KEY. Store the secret key as SITE_AGENT_SIGNING_KEY
 * outside the repository (for example ~/.env) and back it up: installs that enforce the public
 * key reject updates signed with any other key.
 */

if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
	fwrite( STDERR, "Sodium is required.\n" );
	exit( 1 );
}
$pair = sodium_crypto_sign_keypair();
echo 'Public key (includes/class-updater.php, Updater::PUBLIC_KEY):' . PHP_EOL;
echo base64_encode( sodium_crypto_sign_publickey( $pair ) ) . PHP_EOL . PHP_EOL;
echo 'Secret key (SITE_AGENT_SIGNING_KEY; never commit it):' . PHP_EOL;
echo base64_encode( sodium_crypto_sign_secretkey( $pair ) ) . PHP_EOL;
