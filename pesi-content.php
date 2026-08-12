<?php
/**
 * Gemeinsam verwendete Website-Angaben.
 *
 * Seiten verwenden sie zum Beispiel so:
 *   <?= pesi_global('practice_name') ?>
 *   <a href="mailto:<?= pesi_global('email') ?>"><?= pesi_global('email') ?></a>
 *   <a href="<?= pesi_global('booking_url') ?>">Termin vereinbaren</a>
 */
if (!function_exists('pesi')) require_once __DIR__ . '/pesi-core.php';

$PESI_GLOBALS = [
    'practice_name' => pesi('practice_name', 'Meine Praxis', 'text', 'Praxisname'),
    'address'       => pesi('address', 'Musterstraße 1, 6800 Feldkirch', 'textarea', 'Adresse'),
    'phone'         => pesi('phone', '+43 123 456789', 'tel', 'Telefonnummer'),
    'email'         => pesi('email', 'praxis@example.com', 'email', 'E-Mail-Adresse'),
    'booking_url'   => pesi('booking_url', '/kontakt', 'url', 'Link zur Terminvereinbarung'),
];
