<?php

declare(strict_types=1);

// These WordPress contracts are loaded only in isolated sanitizer tests.
function wp_unslash(string $value): string
{
    return stripslashes($value);
}

function sanitize_text_field(string $value): string
{
    return trim((string) preg_replace('/[\r\n\t ]+/', ' ', strip_tags($value)));
}
