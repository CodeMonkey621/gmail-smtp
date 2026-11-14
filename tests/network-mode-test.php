<?php
/*
 * Multisite tests for network mode. Run with WP-CLI against a subdirectory multisite:
 *   wp eval-file tests/network-mode-test.php network  (plugin network-activated)
 *   wp eval-file tests/network-mode-test.php single   (plugin activated on one subsite only, run with --url=<that subsite>)
 * Prints PASS/FAIL lines and exits non-zero on any failure.
 */

$mode = isset($args[0]) ? $args[0] : 'network';
$fails = 0;
$check = function ($name, $cond) use (&$fails) {
    echo ($cond ? 'PASS ' : 'FAIL ') . $name . PHP_EOL;
    if (!$cond) {
        $fails++;
    }
};

$sites = get_sites(array('number' => 3, 'fields' => 'ids'));
$a = (int) $sites[0];
$b = (int) $sites[1];
$saved_network = get_site_option('gmail_smtp_options');
$saved = array();
foreach (array($a, $b) as $id) {
    $saved[$id] = get_blog_option($id, 'gmail_smtp_options');
}

$values = array(
    'oauth_client_id' => 'test-client-id',
    'oauth_client_secret' => 'test-client-secret',
    'oauth_access_token' => json_encode(array('access_token' => 'x', 'refresh_token' => 'test-refresh')),
    'oauth_user_email' => 'wp@example.com',
    'from_email' => 'wp@example.com',
    'from_name' => 'Test',
    'type_of_encryption' => 'tls',
    'smtp_port' => '587',
);

include_once WP_PLUGIN_DIR . '/gmail-smtp/google-api-php-client/vendor/autoload.php';
include_once WP_PLUGIN_DIR . '/gmail-smtp/class.phpmaileroauthgoogle.php';
$grant_path = 'admin.php?page=gmail-smtp-settings&action=oauth_grant';

if ($mode === 'network') {
    $check('network mode detected', function_exists('gmail_smtp_is_network_mode') && gmail_smtp_is_network_mode());

    switch_to_blog($b);
    gmail_smtp_update_option($values);
    restore_current_blog();

    $stored = get_site_option('gmail_smtp_options');
    $check('settings saved from a subsite land in the network option', is_array($stored) && $stored['oauth_client_id'] === 'test-client-id');
    $check('nothing written to the subsite option', get_blog_option($b, 'gmail_smtp_options') === $saved[$b]);

    switch_to_blog($a);
    $opts = gmail_smtp_get_option();
    $configured = is_gmail_smtp_configured();
    restore_current_blog();
    $check('another subsite reads the same settings', is_array($opts) && $opts['oauth_client_secret'] === 'test-client-secret');
    $check('another subsite counts as configured', $configured);

    switch_to_blog($b);
    $redirect = GmailXOAuth2::getClient()->getRedirectUri();
    restore_current_blog();
    $check('redirect URI is the network admin page', $redirect === network_admin_url($grant_path));

    $plugin = $GLOBALS['gmail-smtp'];
    $check('settings menu registered in network admin', has_action('network_admin_menu', array($plugin, 'plugin_menu')) !== false);
    $check('settings menu not registered in subsite admin', has_action('admin_menu', array($plugin, 'plugin_menu')) === false);
    $check('setup notice only in network admin', has_action('network_admin_notices', 'gmail_smtp_admin_notice') !== false && has_action('admin_notices', 'gmail_smtp_admin_notice') === false);

    if ($saved_network === false) {
        delete_site_option('gmail_smtp_options');
    } else {
        update_site_option('gmail_smtp_options', $saved_network);
    }
} else {
    $check('single mode detected', function_exists('gmail_smtp_is_network_mode') && !gmail_smtp_is_network_mode());

    $here = get_current_blog_id();
    $other = ($here === $a) ? $b : $a;
    $saved_other = get_blog_option($other, 'gmail_smtp_options');
    gmail_smtp_update_option($values);
    $check('settings saved to this site option', get_option('gmail_smtp_options')['oauth_client_id'] === 'test-client-id');
    $check('network option untouched', get_site_option('gmail_smtp_options') === $saved_network);
    $check('other subsite option untouched', get_blog_option($other, 'gmail_smtp_options') === $saved_other);
    $check('redirect URI is this site admin page', GmailXOAuth2::getClient()->getRedirectUri() === admin_url($grant_path));
    $plugin = $GLOBALS['gmail-smtp'];
    $check('settings menu registered in site admin', has_action('admin_menu', array($plugin, 'plugin_menu')) !== false);

    $before = isset($saved[$here]) ? $saved[$here] : false;
    if ($before === false) {
        delete_option('gmail_smtp_options');
    } else {
        update_option('gmail_smtp_options', $before);
    }
}

echo $fails ? "FAILED: $fails" . PHP_EOL : 'ALL PASS' . PHP_EOL;
if ($fails) {
    exit(1);
}
