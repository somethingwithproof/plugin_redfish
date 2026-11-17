<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_redfish, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_redfish
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

// Test code: refuse to run outside the command line.
if (PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg') {
	exit(1);
}

/*
 * Writes the fake BMC's certificates into <dir>, using ext-openssl only:
 *   ca.pem         test CA the client is given as a CA bundle
 *   good.pem       bmc.test and *.scale.test (the scale harness), signed
 *                  by the CA
 *   wrongname.pem  other.test, signed by the CA
 *   expired.pem    bmc.test, signed by the CA, valid for zero days, so it
 *                  has expired by the time a test connects
 *   selfsigned.pem bmc.test, signed by nobody the client trusts
 *   other-key.pem  bmc.test, signed by the CA, with a different key (pin change)
 */

$dir = $argv[1] ?? '';

if ($dir === '' || !is_dir($dir)) {
	fwrite(STDERR, "usage: php make-certs.php <dir>\n");
	exit(2);
}

$conf = $dir . '/openssl.cnf';
file_put_contents($conf, <<<CNF
[req]
distinguished_name = dn
[dn]
[ca]
basicConstraints = critical,CA:TRUE
keyUsage = critical,keyCertSign,cRLSign
[good]
basicConstraints = CA:FALSE
subjectAltName = DNS:bmc.test, DNS:*.scale.test
[wrong]
basicConstraints = CA:FALSE
subjectAltName = DNS:other.test
CNF);

$keyOpts = array('private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048, 'config' => $conf);

function fake_issue(array $dn, string $section, $signKey, $signCert, string $conf, array $keyOpts, int $days = 30): array {
	$key  = openssl_pkey_new($keyOpts);
	$csr  = openssl_csr_new($dn, $key, array('digest_alg' => 'sha256', 'config' => $conf));
	$cert = openssl_csr_sign($csr, $signCert, $signKey ?? $key, $days, array('digest_alg' => 'sha256', 'config' => $conf, 'x509_extensions' => $section), random_int(1, PHP_INT_MAX));

	if ($cert === false || !openssl_x509_export($cert, $certPem) || !openssl_pkey_export($key, $keyPem, null, array('config' => $conf))) {
		fwrite(STDERR, "certificate generation failed\n");
		exit(1);
	}

	return array($cert, $key, $certPem, $keyPem);
}

[$caCert, $caKey, $caPem] = fake_issue(array('commonName' => 'plugin_redfish fake BMC CA'), 'ca', null, null, $conf, $keyOpts);
file_put_contents($dir . '/ca.pem', $caPem);

foreach (array('good' => array('bmc.test', 'good', 30, true), 'other-key' => array('bmc.test', 'good', 30, true), 'wrongname' => array('other.test', 'wrong', 30, true), 'expired' => array('bmc.test', 'good', 0, true), 'selfsigned' => array('bmc.test', 'good', 30, false)) as $name => [$cn, $section, $days, $signed]) {
	[, , $c, $k] = fake_issue(array('commonName' => $cn), $section, $signed ? $caKey : null, $signed ? $caCert : null, $conf, $keyOpts, $days);
	file_put_contents($dir . '/' . $name . '.pem', $c . $k);
}

// ext-openssl cannot backdate a certificate; one that ends the moment it
// is issued is expired a second later.
sleep(1);
chmod($dir . '/ca.pem', 0644);
echo "certificates written to $dir\n";
