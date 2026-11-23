<?php

declare(strict_types=1);

// An anonymous front-end 404; loaded only in isolated NotFoundCacheSubscriber tests.
function is_404(): bool
{
    return true;
}

function is_admin(): bool
{
    return false;
}

function wp_doing_ajax(): bool
{
    return false;
}

function wp_doing_cron(): bool
{
    return false;
}

function is_user_logged_in(): bool
{
    return false;
}
