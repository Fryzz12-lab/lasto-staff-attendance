<?php
/** Shared password rules for profile changes and recovery links. */
declare(strict_types=1);

function password_policy_error(string $password): ?string
{
    if (mb_strlen($password) < 8) return 'password_minimum_8';

    $common = [
        'password', 'password1', 'password123', '12345678', '123456789',
        'qwerty123', 'lasto123', '123456789012345', '1234567890123456', 'password123456789',
        'password1234567890', 'qwerty123456789', 'qwertyuiop12345',
        'lasto1234567890', 'lastotechnology',
    ];
    if (in_array(mb_strtolower($password), $common, true)) return 'password_too_common';

    return null;
}

function hash_new_password(string $password): string
{
    return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
}
