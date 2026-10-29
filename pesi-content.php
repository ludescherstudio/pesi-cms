<?php
/**
 * Website details shared across pages.
 *
 * Pages use them like this:
 *   <?= pesi_global('practice_name') ?>
 *   <a href="mailto:<?= pesi_global('email') ?>"><?= pesi_global('email') ?></a>
 *   <a href="<?= pesi_global('booking_url') ?>">Book an appointment</a>
 *   <address><?= nl2br(pesi_global('address')) ?></address>   (keeps the line breaks)
 */
if (!function_exists('pesi')) require_once __DIR__ . '/pesi-core.php';

$PESI_GLOBALS = [
    'practice_name' => pesi('practice_name', 'My Practice', 'text', 'Practice name'),
    'address'       => pesi('address', '1 Sample Street, 6800 Feldkirch', 'textarea', 'Address'),
    'phone'         => pesi('phone', '+43 123 456789', 'tel', 'Phone number'),
    'email'         => pesi('email', 'practice@example.com', 'email', 'Email address'),
    'booking_url'   => pesi('booking_url', '/contact', 'url', 'Booking link'),
];
